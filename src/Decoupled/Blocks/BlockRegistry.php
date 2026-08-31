<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Blocks;

use CloakWP\Core\Content\ContentModel;
use CloakWP\Decoupled\CMS;

/**
 * Registers block instances that expose Extended-ACF-style field-group
 * settings. Objects that do not look like those blocks are skipped.
 */
final class BlockRegistry
{
  /** @var list<object> */
  private array $blocks = [];

  /**
   * @param list<object> $blocks
   */
  public function register(array $blocks, CMS $cms): void
  {
    foreach ($blocks as $block) {
      if (!is_object($block) || !method_exists($block, 'getFieldGroupSettings')) {
        continue;
      }

      if (!$this->dependenciesAreRegistered($block)) {
        continue;
      }

      if (!isset($block->parsedBlockJson['render_callback']) && !isset($block->parsedBlockJson['acf']['renderTemplate'])) {
        $block->args([
          'render_callback' => [$cms, 'renderBlockIframePreview'],
        ]);
      }

      if (!$block->emptyFieldsMessage) {
        $block->emptyFieldsMessage('This block has no fields/controls. Simply drop it wherever you wish to display it.');
      }

      $block->register();
    }

    $this->blocks = array_merge($this->blocks, $blocks);
  }

  /**
   * @return list<object>
   */
  public function all(): array
  {
    return $this->blocks;
  }

  private function dependenciesAreRegistered(object $block): bool
  {
    $dependencies = [];

    if (method_exists($block, 'getContentTypeDependencies')) {
      $dependencies = $block->getContentTypeDependencies();
    } elseif (isset($block->contentTypeDependencies)) {
      $dependencies = $block->contentTypeDependencies;
    }

    if (!is_array($dependencies) || empty($dependencies)) {
      return true;
    }

    $contentModel = ContentModel::getInstance();
    $missing = array_filter(
      $dependencies,
      fn($dependency) => is_string($dependency) && !$contentModel->hasType($dependency)
    );

    if (empty($missing)) {
      return true;
    }

    if ((defined('WP_ENV') && \WP_ENV === 'development') || \is_admin()) {
      $blockName = $block->parsedBlockJson['name'] ?? get_class($block);
      trigger_error(
        'Skipping block "' . $blockName . '" because these content type dependencies are not registered: ' . implode(', ', $missing),
        E_USER_WARNING
      );
    }

    return false;
  }
}
