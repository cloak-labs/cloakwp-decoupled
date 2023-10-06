<?php

namespace CloakWP\API;

use CloakWP\Utils;
use WP_Error;
use WP_REST_Response;

/**
 * Fired during plugin activation
 *
 * @link       https://https://github.com/cloak-labs
 * @since      0.7.0
 *
 * @package    CloakWP
 * @subpackage CloakWP/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class modifies the WordPress REST API for all post types to include useful things for headless projects.
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class Posts
{
  private $blockTransformer;

  public function __construct()
  {
    $this->bootstrap();
  }

  /**
   * Get post types with editor.
   *
   * @return array
   */
  private function get_post_types_with_editor()
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

  /**
   * Add rest api fields.
   *
   * @return void
   */
  public function add_blocks_to_rest_responses()
  {
    $types = $this->get_post_types_with_editor();
    if (!$types) {
      return;
    }

    register_rest_field(
      $types,
      'has_blocks',
      [
        'get_callback'    => array($this, 'has_blocks'),
        'update_callback' => null,
        'schema'          => [
          'description' => __('Has blocks.', 'cloakwp'),
          'type'        => 'boolean',
          'context'     => ['embed', 'view', 'edit'],
          'readonly'    => true,
        ],
      ]
    );

    register_rest_field(
      $types,
      'blocks_data',
      [
        'get_callback'    => array($this, 'get_blocks'),
        'update_callback' => null,
        'schema'          => [
          'description' => __('Blocks.', 'cloakwp'),
          'type'        => 'object',
          'context'     => ['embed', 'view', 'edit'],
          'readonly'    => true,
        ],
      ]
    );
  }


  /**
   * Callback to get if post content has block data.
   *
   * @param array $object Array of data rest api request.
   *
   * @return bool
   */
  public function has_blocks(array $object)
  {
    if (isset($object['content']['raw'])) {
      return has_blocks($object['content']['raw']);
    }
    $id   = !empty($object['wp_id']) ? $object['wp_id'] : $object['id'];
    $post = get_post($id);
    if (!$post) {
      return false;
    }

    return has_blocks($post);
  }

  /**
   * Loop around all blocks and get block data.
   *
   * @param array $object Array of data rest api request.
   *
   * @return array
   */
  public function get_blocks(array $object)
  {
    $id = !empty($object['wp_id']) ? $object['wp_id'] : $object['id'];
    if (isset($object['content']['raw'])) {
      return $this->blockTransformer->get_blocks($object['content']['raw'], $id);
    }
    $post   = get_post($id);
    $output = [];
    if (!$post) {
      return $output;
    }
    return $this->blockTransformer->get_blocks($post->post_content, $post->ID);
  }

  /**
   * Global modifications to all post type's REST API responses
   * 
   * @return mixed
   */
  public function modify_all_post_rest_responses()
  {
    $all_post_types = get_post_types(['public' => true], 'names');
    // $all_post_types = get_post_types([], 'names');
    // $all_post_types["revision"] = "revision"; // add in revision post types (which are private)

    // Add custom 'featured_image' field in all post type REST API responses, which adds src URLs to the medium and large versions of the image rather than just the image ID (default behavior)
    register_rest_field(
      $all_post_types,
      'featured_image',
      array(
        'get_callback'    => array($this, 'get_featured_image_urls'),
        'update_callback' => null,
        'schema'          => null,
      )
    );

    register_rest_field(
      $all_post_types,
      'pathname',
      array(
        'get_callback'    => array($this, 'get_post_pathname'),
        'update_callback' => null,
        'schema'          => null,
      )
    );

    register_rest_field(
      $all_post_types,
      'taxonomies',
      array(
        'get_callback'    => array($this, 'get_post_taxonomies'),
        'update_callback' => null,
        'schema'          => null,
      )
    );

    foreach ($all_post_types as $post_type) {
      add_filter("rest_prepare_{$post_type}", array($this, 'clean_post_rest_responses'), 10, 3);
    }
  }

  /**
   * When hooked into the 'rest_prepare_{$post_type}' filter, this function cleans up the 
   * REST API response for that post type, removing fields that are usually unused by 
   * decoupled frontends; need to be careful about removing things that are used by WordPress.
   * 
   * @return WP_REST_Response|WP_Error
   */
  public function clean_post_rest_responses(WP_REST_Response|WP_Error $response, $post, $context)
  {
    // First check if the REST response is an error:
    if (is_wp_error($response)) {
      return $response;
    }

    $original_data = $response->data;
    $modified_data = $response->data;
    $modified_data['author'] = Utils::get_pretty_author($original_data['author']);

    /* 
      Remove unnecessary fields from the response.
      Note: the 'content' field is not usually useful when doing headless the "right" way, but it's
            required by Gutenberg in order to properly show/preview blocks in the editor, so leave it.
     */
    unset(
      $modified_data['date_gmt'],
      $modified_data['modified_gmt'],
      $modified_data['featured_media'],
      $modified_data['comment_status'],
      $modified_data['ping_status'],
      $modified_data['guid'],
      $modified_data['post_author'], // replaced by new 'author' field above
    );

    // Remove footnotes if it's empty:
    if ($modified_data['meta']['footnotes'] == "") unset($modified_data['meta']);

    // Remove some unnecessary nesting:
    if (isset($modified_data['title']['rendered'])) $modified_data['title'] = $modified_data['title']['rendered'];
    if (isset($modified_data['excerpt']['rendered'])) $modified_data['excerpt'] = $modified_data['excerpt']['rendered'];

    // Apply a filter to the final modified data so users can customize further (eg. they can remove more fields, and/or add back in some that we removed above)
    $final_data = apply_filters('cloakwp/rest/posts/response_format', $modified_data, $original_data);

    $response->data = $final_data;
    return $response;
  }

  /**
   * Include medium and large URLs for Posts' Featured Images in REST API, which otherwise only includes image ID
   *
   * @return array
   */
  public function get_featured_image_urls($object)
  {
    // Check type of object before accessing params
    if (gettype($object) === "object") {
      $id = get_post_thumbnail_id($object->id);
    }
    if (gettype($object) === "array") {
      $id = get_post_thumbnail_id($object['id']);
    }

    // check that array is returned before accessing params
    $medium = wp_get_attachment_image_src($id, 'medium');
    $medium_url = false;
    if (is_array($medium)) {
      $medium_url = $medium['0'];
    }
    $large = wp_get_attachment_image_src($id, 'large');
    $large_url = false;
    if (is_array($large)) {
      $large_url = $large['0'];
    }

    return array(
      'medium' => $medium_url,
      'large'  => $large_url,
    );
  }

  /**
   * Gets the full relative URL pathname of a post
   *
   * @return array
   */
  public function get_post_pathname($object)
  {
    $id = !empty($object['wp_id']) ? $object['wp_id'] : $object['id'];
    $pathname = Utils::get_post_pathname($id);
    return $pathname;
  }

  /**
   * Gets all taxonomies attached to a post, formatted for REST API
   *
   * @return array
   */
  public function get_post_taxonomies($object)
  {
    $post_id = !empty($object['wp_id']) ? $object['wp_id'] : $object['id'];

    // Get the post type associated with the post ID
    $post_type = get_post_type($post_id);

    // Get all taxonomies attached to the post
    $taxonomies = get_object_taxonomies($post_type);
    $taxonomies_data = array();

    // Iterate through each taxonomy
    foreach ($taxonomies as $taxonomy) {
      // Get the terms for the current taxonomy
      $terms = wp_get_post_terms($post_id, $taxonomy);
      $terms_data = array();

      // Iterate through each term
      foreach ($terms as $term) {
        // Build the term data array
        $term_data = array(
          'name' => $term->name,
          'slug' => $term->slug,
          'id' => $term->term_id,
        );

        // Add the term data to the terms array
        $terms_data[] = $term_data;
      }

      // Add the taxonomy slug to its own object
      $taxonomies_data[$taxonomy]['slug'] = $taxonomy;

      // Add the terms data to the taxonomies data array
      $taxonomies_data[$taxonomy]['terms'] = $terms_data;
    }

    return $taxonomies_data;
  }

  /**
   * Add blocks_data to post revisions API responses
   * 
   * @return WP_REST_Response|WP_Error
   */
  public function modify_revisions_rest_responses($response, $post)
  {
    $data = $response->get_data();

    $data['hasBlocks'] = has_blocks($post);
    $data['pathname'] = Utils::get_post_pathname($post->ID);
    $data['blocks_data'] = $this->blockTransformer->get_blocks($post->post_content, $post->ID);

    // TODO: still need to confirm the below works -- do featured image URLs properly show up in revision REST responses?
    $data['featured_image'] = $this->get_featured_image_urls(array('id' => $post->ID));

    // TODO: do we need to manually get and include static ACF fields?

    return rest_ensure_response($data);
  }

  /**
   * Bootstrap filters and actions.
   *
   * @return void
   */
  private function bootstrap()
  {
    $this->blockTransformer = new BlockTransformer();
    add_action('rest_api_init', array($this, 'add_blocks_to_rest_responses'));
    add_action('rest_api_init', array($this, 'modify_all_post_rest_responses'));

    // add_filter('rest_prepare_post', array($this, 'clean_post_rest_responses'), 10, 3);
    /**
     * Note: revisions are not considered a "post type", so the register_rest_field method 
     * of adding block data to its REST response will not work. This is why we have a separate 
     * way of handling it using the "rest_prepare_revision" filter:
     */
    add_filter('rest_prepare_revision', array($this, 'modify_revisions_rest_responses'), 10, 2);
  }
}
