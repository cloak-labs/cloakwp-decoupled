<?php

namespace CloakWP;

use DeepCopy\DeepCopy;
use WP_Theme_JSON_Resolver;

enum PostReturnType: string
{
  case Objects = 'objects';
  case Names = 'names';
}

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

  /**
   * A thin wrapper around the built-in `get_post` function; only difference is
   * that it accepts an associative array post (assumes it has an `id` property),
   * and always returns the post as a WP_Post instance. Useful for throwing in
   * a post of multiple format and knowing you'll get it back in WP_Post format.
   */
  public static function get_wp_post_object(int|\WP_Post|array $input): \WP_Post|null
  {
    // If input is an integer, assume it's a post ID
    if (is_int($input)) {
      return get_post($input);
    }

    // If input is a WP_Post object, return it as is
    if (is_a($input, 'WP_Post')) {
      return $input;
    }

    // If input is an array, try to get the post by ID
    if (is_array($input)) {
      $post_id = $input['id'] ?? null;
      if ($post_id) {
        return get_post($post_id);
      }
    }

    // If none of the above, return null
    return null;
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
    if (!$id || is_bool($id) || (!is_numeric($id) && !is_string($id)))
      return null;

    $author = get_user_by('ID', $id);
    $user_meta = get_metadata('user', $author->ID);
    $desired_meta = apply_filters('cloakwp/author_format/included_meta', [], $user_meta);

    $final_meta = [];
    $acf = [];
    if ($user_meta) {
      foreach ($user_meta as $key => $value) {
        $is_acf_field = isset($user_meta["_$key"]);
        $is_acf_key = str_starts_with($key, "_") && isset($user_meta[substr($key, 1)]);
        // If an ACF reference exists for this value, add it to the $acf array.
        if ($is_acf_field) {
          $acf_obj = get_field_object($key, 'user_' . $author->ID);
          if (is_array($acf_obj)) {
            $acf[$key] = $acf_obj['value'];
          }
        } else if (!$is_acf_key && ($desired_meta === true || in_array($key, $desired_meta))) {
          $final_meta[$key] = $value[0];
        }
      }
    }

    return $author ? array(
      'id' => $author->ID,
      'slug' => $author->user_nicename,
      'display_name' => $author->display_name,
      'meta' => $final_meta,
      'acf' => $acf,
    ) : null;
  }

  /**
   * Function to count and return the total number of posts of a given type; it only counts them instead of retrieving them, ensuring efficiency.
   */
  public static function get_num_posts_of_type(string $post_type): int
  {
    // Set up the query arguments
    $args = array(
      'post_type' => $post_type,
      'posts_per_page' => -1,  // Retrieves all posts
      'fields' => 'ids' // Retrieve only the IDs for quicker execution
    );

    // Create a new WP_Query instance
    $query = new \WP_Query($args);

    // Return the total number of posts found
    return $query->found_posts;
  }

  /**
   * Returns an array of the names of all custom post types (excludes builtins).
   */
  public static function get_custom_post_types(PostReturnType $returnType = PostReturnType::Names, array $excluded = []): array
  {
    $cpts = get_post_types(['_builtin' => false], $returnType->value);

    if (!$cpts)
      return [];

    $excluded_types = array_merge(
      array('acf-field-group', 'acf-field', 'acf-taxonomy', 'acf-post-type', 'acf-ui-options-page'),
      $excluded
    );

    foreach ($excluded_types as $exclude) {
      if (isset($cpts[$exclude])) {
        unset($cpts[$exclude]);
      }
    }

    return $cpts;
  }

  /**
   * Returns an array of the names of all public post types.
   */
  public static function get_public_post_types(PostReturnType $returnType = PostReturnType::Names): array
  {
    return get_post_types(['public' => true], $returnType->value);
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
    $post_types = array_filter($post_types, 'use_block_editor_for_post_type');
    $post_types[] = 'wp_navigation';
    $post_types = array_filter($post_types, 'post_type_exists');

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

  public static function get_files_from_theme_dir($dir, $options = [])
  {
    // Set default options
    $defaults = [
      'recurse' => false,
      'filename' => null
    ];
    // Merge passed options with defaults
    $options = array_merge($defaults, $options);

    // Get the paths for the child and parent theme directories
    $child_theme_dir = get_stylesheet_directory() . $dir;
    $parent_theme_dir = get_template_directory() . $dir;

    // Define the recursive function within the main function scope to avoid naming conflicts
    $scandir_recursive = function ($dir) use (&$scandir_recursive, $options) {
      $files = [];
      if (is_dir($dir)) {
        $items = scandir($dir);
        foreach ($items as $item) {
          if ($item == '.' || $item == '..')
            continue;
          $path = $dir . '/' . $item;
          if (is_dir($path) && $options['recurse']) {
            $files = array_merge($files, $scandir_recursive($path));
          } elseif (is_file($path)) {
            if ($options['filename'] && basename($path) != $options['filename'])
              continue;
            $files[] = $path;
          }
        }
      }
      return $files;
    };

    // Initialize arrays to store files
    $child_files = [];
    $parent_files = [];

    // Get the files from child directory if it exists
    if (is_dir($child_theme_dir)) {
      $child_files = $scandir_recursive($child_theme_dir);
    }

    // Get the files from parent directory if it exists
    if (is_dir($parent_theme_dir)) {
      $parent_files = $scandir_recursive($parent_theme_dir);
    }

    // Create an associative array with filenames as keys and paths as values
    $files = [];

    // Add child theme files
    foreach ($child_files as $file) {
      $relative_path = str_replace(get_stylesheet_directory(), '', $file);
      $files[$relative_path] = $file;
    }

    // Add parent theme files, only if they are not overridden by child theme
    foreach ($parent_files as $file) {
      $relative_path = str_replace(get_template_directory(), '', $file);
      if (!isset($files[$relative_path])) {
        $files[$relative_path] = $file;
      }
    }

    // Return the paths of the files
    return array_values($files);
  }

  /**
   * `require_all` provides a simple and effective way to include multiple files and collect their contents in an array.
   */
  public static function require_all($files)
  {
    $contents = [];
    foreach ($files as $file) {
      if (file_exists($file)) {
        $contents[] = require $file;
      } else {
        throw new \Exception("File not found: $file");
      }
    }
    return $contents;
  }

  /** 
   * A simple wrapper for requiring multiple files using a glob pattern. 
   * eg. Utils::require_glob(get_stylesheet_directory() . '/models/*.php'); // will require all PHP files within your child theme's `models/` folder
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

  /**
   * A function that deeply copies Objects (class instances) and Arrays
   */
  public static function deep_copy($var)
  {
    static $copier = null;

    if (null === $copier) {
      $copier = new DeepCopy(true);
    }

    try {
      $copy = $copier->copy($var);
      return $copy;
    } catch (\Exception $err) {
      Utils::write_log("Caught Error while running deep_copy: {$err}");
    }

    return $var;
  }

  /**
   * filterArrayByKeys takes an array of associative arrays and an array of field names, then returns a cleaned version of the 1st array where each associative array only contains the properties defined in the 2nd array.
   * Note: you can also rename any selected property by passing the keys array like so: filterArrayByKeys([...], ['field_x' => 'new_field_name', ...])
   */
  public static function filterArrayByKeys(array $array, array $keys)
  {
    return array_map(function ($item) use ($keys) {
      $filteredItem = [];
      foreach ($keys as $originalKey => $newKey) {
        if (is_int($originalKey)) {
          // If the key is an integer, use it as the original key and the value as the new key
          $originalKey = $newKey;
        }
        if (array_key_exists($originalKey, $item)) {
          $filteredItem[$newKey] = $item[$originalKey];
        }
      }
      return $filteredItem;
    }, $array);
  }
}
