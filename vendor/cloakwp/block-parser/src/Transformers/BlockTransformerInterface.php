<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;

interface BlockTransformerInterface
{
  public function transform(WP_Block $block, int|null $postId = null): array;
}