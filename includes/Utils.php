<?php

namespace CloakWP;

use Extended\ACF\Fields\Accordion;
use WP_Theme_JSON_Resolver;

class Utils
{

  /*
    Helper function used by the CloakWP plugin to log errors or other details to the WP error log
  */
  public static function write_log($log)
  {
    if (!CLOAKWP_DEBUG) {
      return;
    }

    if (is_array($log) || is_object($log)) {
      error_log(print_r($log, true));
    } else {
      error_log($log);
    }
  }

  public static function acfBlockWrapper($blockLabel, $fields)
  {
    if (class_exists('Extended\ACF\Fields\Accordion')) {
      return [
        Accordion::make($blockLabel)
          ->open()
          ->multiExpand(), // Allow accordion to remain open when other accordions are opened.
        ...$fields,
        Accordion::make('Endpoint')
          ->endpoint()
          ->multiExpand(),
      ];
    } else {
      throw new \Exception('The Extended ACF composer package does not appear to be installed.');
    }
  }

  /* 
    A function that returns the given post's full URL pathname, eg. `/blog/post-slug`
  */
  public static function get_post_pathname($post_id)
  {
    $pathname = parse_url(get_permalink($post_id), PHP_URL_PATH);
    return $pathname;
  }

  /*  
    A function that returns the theme.json color palette -- optionally pass in a block name to return that particular block's color palette
  */
  public static function get_theme_color_palette($blockName = null)
  {
    $color_palette = [];

    // check if theme.json is being used and if so, grab the settings
    if (class_exists('WP_Theme_JSON_Resolver')) {
      $settings = WP_Theme_JSON_Resolver::get_theme_data()->get_settings();

      if ($blockName) {
        // custom block color palette
        if (isset($settings['blocks'][$blockName]['color']['palette'])) {
          $color_palette = $settings['blocks']['acf/acf-separator']['color']['palette'];
        }
      } elseif (isset($settings['color']['palette']['theme'])) {
        // full theme color palette
        $color_palette = $settings['color']['palette']['theme'];
      }
    }

    return $color_palette;
  }

  /* 
    A simple wrapper for requiring multiple files using a glob pattern. 
    eg. Utils::require_glob(get_stylesheet_directory() . '/models/*.php'); // will require all PHP files within your child theme's `models/` folder
  */
  public static function require_glob($folder_path_glob)
  {
    $files = glob($folder_path_glob);
    foreach ($files as $file) {
      require_once $file;
    }
  }

  /* 
    A function that returns this plugin's "includes" directory URL.
    Use case example: enables a theme's block.json file to reference 
    the `block-preview.php` file within the plugin, like so: 
    Utils::cloakwp_plugin_path() . '/block-preview.php';
  */
  public static function cloakwp_plugin_path()
  {
    return dirname(__FILE__);
  }
}
