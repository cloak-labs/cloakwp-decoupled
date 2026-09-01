<?php

use CloakWP\BlockParser\BlockParser;
use CloakWP\Core\Utils;

/**
 * ACF Block Decoupled Preview Template.
 *
 * Renders an iframe pointing at the frontend block-preview route, plus JSON
 * block data for the editor. Companion script `js/block-preview.js` owns the
 * live update path:
 * - Keeps the iframe mounted across ACF `fetch-block` AJAX re-renders
 * - Delivers block data via postMessage after a `cloakwp-preview-ready` handshake
 * - Applies optimistic field edits immediately; authoritative AJAX JSON follows
 *   and is skipped when stale relative to newer local edits
 *
 * This PHP template always emits the iframe shell (including on fetch-block
 * AJAX — new blocks first-paint exclusively via AJAX) plus a stable
 * `previewKey` (from `$block['id']`) so JS can correlate iframe ↔ payload.
 * Once mounted, block-preview.js returns cached iframe HTML so ACF setHtml
 * no-ops and the live iframe is reused across edits.
 *
 * The following variables are made available by WP/Gutenberg for use in this template
 * @var   array $block The block settings and attributes.
 * @var   string $content The block inner HTML (empty).
 * @var   bool $is_preview True while in Block Editor
 * @var   int $post_id The post ID the block is rendering content against.
 *          This is either the post ID currently being displayed inside a query loop,
 *          or the post ID of the post hosting this block.
 * @var   object $context The context provided to the block by the post or its parent block.
 */

// Prevent the block preview code from running in irrelevant contexts, such as during WP REST API requests:
if (!$is_preview) {
  echo '<div>' . esc_html('__' . $block['name'] . '__') . '</div>';
  return;
}

// Handle block inserter preview image
if (isset($block['data']['cloakwp_block_inserter_preview_image'])) {
  $image_path = $block['data']['cloakwp_block_inserter_preview_image'];

  // If $image_path starts with "/", assume it's a relative path within the child theme
  if (strpos($image_path, '/') === 0) {
    $image_path = get_stylesheet_directory_uri() . $image_path;
  }

  echo '<img src="' . esc_url($image_path) . '" style="width:100%; height:auto;" alt="Block Preview">';
} elseif (isset($block['data'])) {
  // Handle regular Gutenberg Editor ACF Block iframe preview rendering.
  // Include empty `$block['data']` (brand-new inserts often have no field
  // values yet) so the iframe shell still mounts; postMessage fills data later.
  $is_block_inserter = isset($block['data']['cloakwp_block_inserter_iframe']) && $block['data']['cloakwp_block_inserter_iframe'];

  // Remove unnecessary data
  unset($block['style']['spacing'], $block['render_callback']);

  $field_values = [];
  $saw_field_keys = false;
  $saw_named_keys = false;
  foreach ($block['data'] as $key => $value) {
    if (strpos($key, 'field_') === 0) {
      $saw_field_keys = true;
    } else {
      $saw_named_keys = true;
      break;
    }
  }

  if ($saw_field_keys && !$saw_named_keys) {
    /* when previewing an ACF Block where data has been updated via AJAX request, the $block value is very 
      different from when the data hasn't been updated (i.e. on initial page load) -- the code below transforms 
      the ACF data so that it's always in the same shape no matter the context. This ensures previews don't 
      break after making field changes.

      Nested group/clone subfields must stay nested (e.g. ken_burns.enable). A flat
      `$field_values['enable']` breaks routers that read `block.data.ken_burns`.

      Important: only nest under top-level groups/clones (walk the parent chain).
      Leaves under repeaters/flexible (e.g. cards → card_data → title) must NOT
      be hoisted — the repeater value already contains them, and hoisting can
      blank or corrupt cards previews.
    */
    $layout_types = ['accordion', 'tab', 'message'];
    $is_field_key = static function ($key): bool {
      return is_string($key) && strpos($key, 'field_') === 0;
    };

    /**
     * Walk group/clone parents and return a name path under a top-level group
     * (e.g. ['ken_burns','enable'] or ['link_options','button','enabled']).
     * Returns null when the leaf lives under a repeater/flexible row — those
     * values are already inside the parent field's formatted value.
     */
    $resolve_group_value_path = static function (array $field_object) use ($is_field_key): ?array {
      $leaf_name = $field_object['name'] ?? null;
      if (!$leaf_name) {
        return null;
      }

      $parts = [$leaf_name];
      $parent_key = $field_object['parent'] ?? null;
      $guard = 0;

      while ($is_field_key($parent_key) && $guard++ < 20) {
        $parent_field = get_field_object($parent_key);
        if (!$parent_field || empty($parent_field['name'])) {
          return null;
        }

        $parent_type = $parent_field['type'] ?? '';
        if ($parent_type === 'repeater' || $parent_type === 'flexible_content') {
          // Nested inside a row — do not hoist to top-level block data.
          return null;
        }

        if ($parent_type !== 'group' && $parent_type !== 'clone') {
          return null;
        }

        array_unshift($parts, $parent_field['name']);
        $parent_key = $parent_field['parent'] ?? null;
      }

      // Path must start at a top-level group (at least group + leaf).
      return count($parts) >= 2 ? $parts : null;
    };

    foreach ($block['data'] as $key => $value) {
      $field_object = get_field_object($key);
      if (!$field_object || empty($field_object['name'])) {
        continue;
      }

      $type = $field_object['type'] ?? '';
      if (in_array($type, $layout_types, true)) {
        continue;
      }

      $name = $field_object['name'];
      $val = $field_object['value'];
      $parent_key = $field_object['parent'] ?? null;

      // Top-level block fields (parent is the field group, not another field).
      if (!$is_field_key($parent_key)) {
        if (($type === 'group' || $type === 'clone') && is_array($val)) {
          $existing = isset($field_values[$name]) && is_array($field_values[$name])
            ? $field_values[$name]
            : [];
          // Subfield patches (written below) win over a possibly-stale group blob.
          $field_values[$name] = array_merge($val, $existing);
          continue;
        }

        $field_values[$name] = $val;
        continue;
      }

      $path = $resolve_group_value_path($field_object);
      if ($path === null) {
        // Under repeater/flexible, or not a group nest we understand — skip leaf.
        continue;
      }

      // Write leaf into nested group path (mutates $field_values by reference walk).
      $cursor = &$field_values;
      $last = count($path) - 1;
      for ($i = 0; $i < $last; $i++) {
        $segment = $path[$i];
        if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
          $cursor[$segment] = [];
        }
        $cursor = &$cursor[$segment];
      }
      $cursor[$path[$last]] = $val;
      unset($cursor);
    }
  } else {
    $first_render = true;
  }

  $formattedData = [
    'blockName' => $block['name'],
    'attrs' => [
      'data' => empty($field_values) ? $block['data'] : $field_values,
    ]
  ];

  $attrsToConditionallyAdd = ['align', 'style', 'backgroundColor', 'gradient', 'textColor', 'className'];
  foreach ($attrsToConditionallyAdd as $attr) {
    if (isset($block[$attr])) {
      $formattedData['attrs'][$attr] = $block[$attr];
    }
  }

  $blockParser = new BlockParser();
  $blockData = $blockParser->transformBlock($formattedData, $post_id);
  $json = wp_json_encode($blockData ?? null);
  $postPathname = Utils::getPostPathname($post_id);
  if (!is_string($postPathname) || $postPathname === '' || !str_starts_with($postPathname, '/')) {
    $postPathname = '/';
  }

  $CMS = \CloakWP\Decoupled\CMS::getInstance();
  $frontend = $CMS->getActiveFrontend();
  $frontendUrl = $frontend->getUrl();
  // Stable key across ACF AJAX re-renders (AJAX sets $block['id'] to block_{clientId}).
  // Prefer this over uniqid so editor JS can reuse the live iframe via postMessage.
  $previewKey = !empty($block['id'])
    ? sanitize_html_class((string) $block['id'])
    : sanitize_html_class('block-preview-' . uniqid());

  // Include previewKey in the iframe URL so the frontend preview can identify itself in
  // postMessage ("ready") even when the editor canvas document is not queryable
  // from the outer window (common with the iframed block editor).
  $iframeUrl = esc_url($CMS->previewUrls()->forBlock($frontend, $previewKey, $postPathname));
  $iframeOriginParts = wp_parse_url($frontendUrl);
  $iframeOrigin = is_array($iframeOriginParts)
    && isset($iframeOriginParts['scheme'], $iframeOriginParts['host'])
      ? $iframeOriginParts['scheme'] . '://' . $iframeOriginParts['host']
        . (isset($iframeOriginParts['port']) ? ':' . $iframeOriginParts['port'] : '')
      : '';

  $bodyClasses = apply_filters('admin_body_class', '');
  $isPageDark = in_array('dark', explode(" ", $bodyClasses));

  // Always emit the iframe shell — including on ACF fetch-block AJAX.
  // New blocks first paint exclusively via AJAX; skipping the iframe there
  // left them with JSON-only markup and no preview until a full editor reload.
  // block-preview.js still returns cached iframe HTML once mounted so ACF's
  // setHtml early-returns and the live iframe is not remounted on each edit.

?>
  <div
    class="decoupled-block-preview-ctnr"
    data-cloakwp-preview-key="<?php echo esc_attr($previewKey); ?>"
    data-cloakwp-preview-origin="<?php echo esc_attr($iframeOrigin); ?>"
    data-cloakwp-is-page-dark="<?php echo $isPageDark ? '1' : '0'; ?>"
  >
    <!-- Block selector icon overlay on hover (editor only) -->
    <?php if (!$is_block_inserter): ?>
      <div class="cloakwp-block-selector" style="display: none; max-width: 32px; max-height: 32px;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
          class="cloakwp-block-selector-icon">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
      </div>
    <?php endif; ?>

    <?php /*
      Initial height is set by JS (see below) to one editor screen minus chrome.
      The height listener overwrites this inline property on first report.
    */ ?>
    <iframe id="<?php echo esc_attr($previewKey); ?>"
      data-cloakwp-preview-key="<?php echo esc_attr($previewKey); ?>"
      class="block-preview-iframe <?php echo $is_block_inserter ? 'in-block-inserter' : ''; ?>"
      src="<?php echo $iframeUrl; ?>" title="Block Preview" width="100%" scrolling="no" allow="same-origin"></iframe>

    <script type="application/json" class="cloakwp-block-data"><?php echo $json; ?></script>
    <script type="application/json" class="cloakwp-preview-meta"><?php echo wp_json_encode([
      'isPageDark' => $isPageDark,
    ]); ?></script>
  </div>
<?php
}
