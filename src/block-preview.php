<?php

use CloakWP\BlockParser\BlockParser;
use CloakWP\DecoupledCMS;
use CloakWP\Core\Utils;

/**
 * ACF Block Decoupled Preview Template.
 * Uses an iframe to preview the block's UI from your decoupled frontend.
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
} elseif (isset($block['data']) && !empty($block['data'])) {
  // Handle regular Gutenberg Editor ACF Block iframe preview rendering
  $is_block_inserter = isset($block['data']['cloakwp_block_inserter_iframe']) && $block['data']['cloakwp_block_inserter_iframe'];

  // Remove unnecessary data
  unset($block['style']['spacing'], $block['render_callback']);

  $field_values = [];
  foreach ($block['data'] as $key => $value) {
    if (strpos($key, 'field_') === 0) {
      /* when previewing an ACF Block where data has been updated via AJAX request, the $block value is very 
        different from when the data hasn't been updated (i.e. on initial page load) -- the code below transforms 
        the ACF data so that it's always in the same shape no matter the context. This ensures previews don't 
        break after making field changes.
      */
      $field_object = get_field_object($key);
      if ($field_object) {
        $field_values[$field_object['name']] = $field_object['value'];
      }
    } else {
      $first_render = true;
      break;
    }
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

  // $blockTransformer = new ACFBlockTransformer();
  $blockParser = new BlockParser();
  $blockData = $blockParser->transformBlock($formattedData, $post_id);
  $json = wp_json_encode($blockData ?? null);
  $postPathname = Utils::getPostPathname($post_id);

  $CMS = DecoupledCMS::getInstance();
  $frontend = $CMS->getActiveFrontend();
  $frontendUrl = $frontend->getUrl();
  $settings = $frontend->getSettings();
  $iframeUrl = esc_url("$frontendUrl/{$settings['blockPreviewPath']}?secret={$settings['authSecret']}&pathname=$postPathname");
  $iframeId = uniqid('block-preview-');
  $bodyClasses = apply_filters('admin_body_class', '');
  $isPageDark = in_array('dark', explode(" ", $bodyClasses));

?>
  <div class="decoupled-block-preview-ctnr">
    <!-- Block selector icon overlay on hover (note: we don't render this icon for block inserter previews, only within Editor) -->
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

    <iframe id="<?php echo esc_attr($iframeId); ?>"
      class="block-preview-iframe <?php echo $is_block_inserter ? 'in-block-inserter' : ''; ?>"
      src="<?php echo $iframeUrl; ?>" title="Block Preview" width="100%" scrolling="no" allow="same-origin"
      loading="lazy"></iframe>

    <script>
      (function() {
        const blockData = <?php echo $json; ?>;
        const isPageDark = <?php echo $isPageDark ? 'true' : 'false'; ?>;
        const bodyClassNames = isPageDark ? ['dark', 'dark:darker'] : [];

        const iframe = document.getElementById("<?php echo esc_js($iframeId); ?>");
        if (!iframe) return;

        const sendDataToIframe = (data) => iframe.contentWindow.postMessage(JSON.stringify(data), "*");

        if (!isPageDark) {
          let wpBlockAncestor = iframe.closest('.wp-block');
          while (wpBlockAncestor && !wpBlockAncestor.classList.contains('is-root-container')) {
            if (wpBlockAncestor.classList.contains('wp-block')) {
              const c = wpBlockAncestor.classList;
              if (c.contains('is-style-dark') || c.contains('dark')) {
                bodyClassNames.push('dark', 'dark:darker');
                break;
              }
            }
            wpBlockAncestor = wpBlockAncestor.parentNode;
          }
        }

        const sendAllInfo = () => {
          sendDataToIframe(blockData);
          if (bodyClassNames.length) {
            sendDataToIframe({
              bodyClassName: bodyClassNames.join(' ')
            });
          }
        };

        window.addEventListener("message", function(event) {
          if (event.source === iframe.contentWindow) {
            if (event.data === "ready") {
              sendAllInfo();
            } else {
              const height = parseInt(event.data) + 1 + "px";
              iframe.style.height = height;
              iframe.parentNode.style.height = height;
            }
          }
        });

        sendAllInfo();

        // remove display: none from .cloakwp-block-selector
        setTimeout(() => {
          const blockSelector = iframe.parentNode.querySelector('.cloakwp-block-selector');
          if (blockSelector) {
            blockSelector.style.display = 'block';
          }
        }, 2000);
      })();
    </script>
  </div>
<?php
}
