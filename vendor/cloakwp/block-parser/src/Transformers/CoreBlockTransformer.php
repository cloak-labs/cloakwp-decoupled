<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;
use CloakWP\BlockParser\Helpers\AttributeParser;

class CoreBlockTransformer extends AbstractBlockTransformer
{
  protected static string $type = 'core';

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    $attrs = $block->attributes;
    $this->parseAttributes($block, $attrs, $postId);

    $formattedBlock = $this->formatBaseBlock($block, $attrs);

    if ($this->shouldIncludeRendered($formattedBlock)) {
      $formattedBlock['rendered'] = do_shortcode($block->render());
    }

    return $formattedBlock;
  }

  protected function parseAttributes(WP_Block $block, array &$attrs, int $postId): void
  {
    $attributeParser = new AttributeParser();

    foreach ($block->block_type->attributes as $key => $attribute) {
      if (!isset($attrs[$key]) || $attrs[$key] == "") {
        $attrValue = $attributeParser->getAttribute($attribute, $block->inner_html, $postId);
        if ($attrValue !== null) {
          $attrs[$key] = $attrValue;
        }
      }
    }

    $this->removeUnwantedAttributes($attrs);
  }

  protected function shouldIncludeRendered(array $formattedBlock): bool
  {
    return apply_filters('cloakwp/block/include_rendered', true, $formattedBlock);
  }
}