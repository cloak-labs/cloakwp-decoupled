<?php

namespace CloakWP\Admin;

// use CloakWP\DecoupledAdmin;
// use CloakWP\Utils;

use CloakWP\Admin\Enqueue\Script;
use CloakWP\Admin\Enqueue\Stylesheet;
use Snicco\Component\BetterWPAPI\BetterWPAPI;

/**
 * A class that provides a simple API for customizing wp-admin
 */
class Admin extends BetterWPAPI
{

  /**
   * Initialize the class and set its properties.
   */
  public function __construct()
  {}

  /**
   * Enqueue a single Stylesheet or Script
   */
  public function enqueueAsset(Stylesheet|Script $asset): static
  {
    $asset->enqueue();
    return $this;
  }

  /**
   * Enqueue an array of Stylesheets and/or Scripts
   */
  public function enqueueAssets(array $assets): static
  {
    foreach($assets as $asset) {
      $this->enqueueAsset($asset);
    }
    return $this;
  }

  /**
   * Remove and add certain theme support
   */
  // private function addThemeSupport()
  // {
  //   // We use the after_setup_theme hook with a priority of 11 to load after the parent theme, which will fire on the default priority of 10
  //   add_action( 'after_setup_theme', function() {
  //     remove_theme_support('core-block-patterns'); // disable default Gutenberg Patterns
  //     add_theme_support('post-thumbnails'); // enable featured images
  //     add_post_type_support('page', 'excerpt'); // enable page excerpts
  //   }, 11 ); 
  // }
}
