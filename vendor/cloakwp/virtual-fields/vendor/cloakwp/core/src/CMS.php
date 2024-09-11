<?php

namespace CloakWP\Core;

use CloakWP\Core\Enqueue\Script;
use CloakWP\Core\Enqueue\Stylesheet;
use Snicco\Component\BetterWPAPI\BetterWPAPI;

use InvalidArgumentException;
use WP_Block_Type_Registry;

/**
 * A class that provides a simpler API around some core WordPress functions
 */
class CMS extends BetterWPAPI
{

  /**
   * Initialize the class and set its properties.
   */
  public function __construct()
  {
  }

  /**
   * Enqueue a single Stylesheet or Script
   */
  public function enqueueAsset(Stylesheet|Script $asset): static
  {
    $asset->enqueue();
    return $this;
  }

  /**
   * Provide an array of Stylesheets and/or Scripts to enqueue
   */
  public function assets(array $assets): static
  {
    foreach ($assets as $asset) {
      $this->enqueueAsset($asset);
    }
    return $this;
  }

  /**
   * Define which core blocks to enable in Gutenberg (an array of block names). Any that aren't defined will be excluded from use.
   * You can also specify post type rules, so that certain blocks are only allowed on certain post types -- for example:
   * 
   * enabledCoreBlocks([
   *  'core/paragraph' => [
   *    'postTypes' => ['post', 'page'] // will only be available on posts of type 'post' and 'page'
   *  ],
   *  'core/heading', // will be available to all post types
   *  ...
   * ]) 
   */
  public function enabledCoreBlocks(array|bool $blocksToInclude): static
  {
    add_filter('allowed_block_types_all', function ($allowed_block_types, $editor_context) use ($blocksToInclude) {
      return $this->getAllowedBlocks($editor_context, $blocksToInclude);
    }, 10, 2);

    return $this;
  }

  private function getAllowedBlocks(object $editorContext, array|bool $blocks): bool|array
  {
    $registeredBlockTypes = WP_Block_Type_Registry::get_instance()->get_all_registered();
    $registeredBlockTypeKeys = array_keys($registeredBlockTypes);

    $currentPostType = $editorContext->post->post_type;
    $finalAllowedBlocks = array_filter($registeredBlockTypeKeys, fn($b) => !str_starts_with($b, 'core/')); // start with all non-core blocks, then we'll add user-provided core blocks to this list
    if (is_array($blocks)) {
      foreach ($blocks as $key => $value) {
        if (is_string($value)) {
          $finalAllowedBlocks[] = $value;
        } else if (is_array($value)) {
          $blockName = $key;
          if (isset($value['postTypes'])) {
            if (is_array($value['postTypes'])) {
              foreach ($value['postTypes'] as $postType) {
                if ($currentPostType == $postType) {
                  $finalAllowedBlocks[] = $blockName;
                }
              }
            } else {
              throw new InvalidArgumentException("postTypes argument must be an array of post type slugs");
            }
          } else {
            $finalAllowedBlocks[] = $blockName;
          }
        } else {
          continue; // current $block is invalid, move on to next one.
        }
      }
    } else if (is_bool($blocks)) {
      return $blocks;
    } else {
      throw new InvalidArgumentException("Invalid argument type passed to coreBlocks() -- must be of type array or boolean.");
    }

    return $finalAllowedBlocks;
  }
}
