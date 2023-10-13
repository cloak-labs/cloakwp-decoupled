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

  /* 
    A function that returns the given post's full URL pathname, eg. `/blog/post-slug`
  */
  public static function get_post_pathname($post_id)
  {
    $pathname = parse_url(get_permalink($post_id), PHP_URL_PATH);
    return $pathname;
  }

  /* 
    Given an author ID, return an object containing its regularly needed fields
  */
  public static function get_pretty_author($id)
  {
    if (!$id) return null;
    $author = get_user_by('ID', $id);
    return $author ? array(
      'id' => $author->ID,
      'slug' => $author->user_nicename,
      'display_name' => $author->display_name
    ) : null;
  }

  /**
   * Returns an array of the names of all public post types.
   */
  public static function get_public_post_types(): array
  {
    return get_post_types(['public' => true], 'names');
  }

  /**
   * Returns an array of the names of all post types that have the Gutenberg Editor enabled.
   */
  public static function get_post_types_with_editor(): array
  {
    $post_types = get_post_types(['show_in_rest' => true], 'names');
    $post_types = array_values($post_types);


    if (!function_exists('use_block_editor_for_post_type')) {
      require_once ABSPATH . 'wp-admin/includes/post.php';
    }
    $post_types   = array_filter($post_types, 'use_block_editor_for_post_type');
    $post_types[] = 'wp_navigation';
    $post_types   = array_filter($post_types, 'post_type_exists');

    return $post_types;
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
          $color_palette = $settings['blocks'][$blockName]['color']['palette'];
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

  /**
   * add_hook_variations
   *    --> adapted from ACF's acf_add_filter_variations() + acf_add_action_variations() + _acf_apply_hook_variations()
   *
   * Registers variations for the given filter/action.
   *
   * @param   string $type The hook type, either 'filter' or 'action'
   * @param   string $hook The filter/action name.
   * @param   array  $variations An array of variation keys.
   * @param   int    $index The param index to find variation values.
   * @return  void
   */
  public static function add_hook_variations($type = 'filter', $hook = '', $variations = array(), $index = 0)
  {
    $apply_hook_variations = function () use ($type, $variations, $index) {
      // Get current hook name
      $hook_name = current_filter();

      // Get args provided to current hook
      $args = func_get_args();

      // Find field in args using index.
      $field = $args[$index];

      // Loop over variations and apply filters.
      foreach ($variations as $variation) {

        // Get value from field.
        // First look for "backup" value ("_name", "_key").
        if (isset($field["_$variation"])) {
          $value = $field["_$variation"];
        } elseif (isset($field[$variation])) {
          $value = $field[$variation];
        } else {
          continue;
        }

        // Apply filter variations
        if ($type === 'filter') {
          $args[0] = apply_filters_ref_array("$hook_name/$variation=$value", $args);
        } else {
          // Or do action variations
          do_action_ref_array("$hook_name/$variation=$value", $args);
        }
      }

      // Return first arg.
      return $args[0];
    };

    // Hook our filter/action variations onto the parent hook
    // Use a priotiry of 10, and accepted args of 10 (ignored by WP).
    if ($type === 'filter') {
      add_filter($hook, $apply_hook_variations, 10, 10);
    } else {
      add_action($hook, $apply_hook_variations, 10, 10);
    }
  }

  /* 
    Given an array of objects, where those objects might have arrays 
    of objects themselves, this function recursively traverses the array, 
    checks for arrays or objects, and clones the objects using `clone` to
    ensure a complete deep copy; useful to remove object references so you
    don't modify your original objects. Taken from: https://stackoverflow.com/a/6418989/8297151
  */
  public static function array_deep_copy($arr)
  {
    $newArray = array();
    foreach ($arr as $key => $value) {
      if (is_array($value)) $newArray[$key] = self::array_deep_copy($value);
      else if (is_object($value)) $newArray[$key] = clone $value;
      else $newArray[$key] = $value;
    }
    return $newArray;
  }
}
