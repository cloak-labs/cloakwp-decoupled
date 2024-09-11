<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;

abstract class AbstractBlockTransformer implements BlockTransformerInterface
{
  protected static string $type;

  abstract public function transform(WP_Block $block, int|null $postId = null): array;

  protected function removeUnwantedAttributes(array &$attrs): void
  {
    unset($attrs['data'], $attrs['name'], $attrs['mode']);
  }

  protected function formatBaseBlock(WP_Block $block, array $attrs): array
  {
    return [
      'name' => $block->name,
      'type' => static::$type,
      'attrs' => $attrs,
    ];
  }

  public static function getType(): string
  {
    return static::$type;
  }
}