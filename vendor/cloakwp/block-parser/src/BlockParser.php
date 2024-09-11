<?php

namespace CloakWP\BlockParser;

use CloakWP\BlockParser\Transformers\BlockTransformerInterface;
use CloakWP\BlockParser\Transformers\CoreBlockTransformer;
use CloakWP\BlockParser\Transformers\ACFBlockTransformer;
use CloakWP\HookModifiers;
use WP_Block;
use WP_Post;

class BlockParser
{
  protected array $transformers = [];
  private static $initialized = false;

  public function __construct()
  {
    if (!self::$initialized) {
      // Run the following code only ONCE, no matter how many instances of BlockParser are created
      HookModifiers::make(['name', 'type'])
        ->forFilter('cloakwp/block')
        ->register();

      HookModifiers::make(['name', 'type', 'blockName'])
        ->forFilter('cloakwp/block/field')
        ->modifiersArgPosition(2)
        ->register();

      self::$initialized = true;
    }

    $this->registerDefaultTransformers();
  }

  protected function registerDefaultTransformers(): void
  {
    $this->registerTransformer(CoreBlockTransformer::class);

    if (function_exists('acf_register_block_type')) {
      $this->registerTransformer(ACFBlockTransformer::class);
    }
  }

  public function registerTransformer(string $transformerClass): void
  {
    if (!is_subclass_of($transformerClass, BlockTransformerInterface::class)) {
      throw new \InvalidArgumentException("Transformer must implement BlockTransformerInterface");
    }

    $type = $transformerClass::getType();
    $this->transformers[$type] = new $transformerClass();
  }

  public function parseBlocksFromPost(WP_Post|int $post): array
  {
    $post = get_post($post);
    $blocks = parse_blocks($post->post_content);

    return array_values(
      $this->transformBlocks($blocks, $post->ID)
    );
  }

  public function transformBlock(array $block, int $postId): array
  {
    $wpBlock = new WP_Block($block);
    $blockType = $this->determineBlockType($wpBlock);

    $transformer = $this->transformers[$blockType] ?? $this->transformers['core'];
    $parsedBlock = $transformer->transform($wpBlock, $postId);

    if (!empty($block['innerBlocks'])) {
      $parsedBlock['innerBlocks'] = $this->transformBlocks($block['innerBlocks'], $postId);
    }

    return apply_filters('cloakwp/block', $parsedBlock, $wpBlock);
  }

  protected function transformBlocks(array $blocks, int $postId): array
  {
    return array_map(
      fn($block) => $this->transformBlock($block, $postId),
      array_filter($blocks, fn($block) => !empty ($block['blockName']))
    );
  }

  protected function determineBlockType(WP_Block $block): string
  {
    if (isset($block->block_type->attributes['data'])) {
      return 'acf';
    }
    return 'core';
  }
}