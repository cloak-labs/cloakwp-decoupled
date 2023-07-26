<?php

namespace CloakWP\API;

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
 * This class modifies the WordPress REST API for Widgets to include useful things for headless projects.
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class Widgets
{

  public function __construct()
  {
    $this->bootstrap();
  }

  /**
   * Add Gutenberg Block fields to REST API responses for widgets, if the Widgets Block Editor is enabled.
   *
   * @return void
   */
  public function add_blocks_to_widget_rest_responses()
  {
    if (!function_exists('wp_use_widgets_block_editor') || !wp_use_widgets_block_editor()) {
      return;
    }

    register_rest_field(
      'widget',
      'has_blocks',
      [
        'get_callback'    => array($this, 'has_blocks_widget_get_callback'),
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
      'widget',
      'blocks',
      [
        'get_callback'    => array($this, 'blocks_widget_get_callback'),
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
   * Get widget
   *
   * @param array $object Object data.
   *
   * @return mixed
   */
  private function get_widget( array $object ) {
    global $wp_widget_factory;

    $widget_object = $wp_widget_factory->get_widget_object( $object['id_base'] );
    $parsed_id     = wp_parse_widget_id( $object['id'] );
    $all_instances = $widget_object->get_settings();

    return $all_instances[ $parsed_id['number'] ];
  }

  /**
   * Callback to get if post content has block data.
   *
   * @param array $object Array of data rest api request.
   *
   * @return bool
   */
  public function has_blocks_widget_get_callback( array $object ) {
    if ( ! isset( $object['id_base'] ) || 'block' !== $object['id_base'] ) {
      return false;
    }

    $instance = $this->get_widget( $object );
    if ( ! isset( $instance['content'] ) || ! $instance['content'] ) {
      return false;
    }

    return has_blocks( $instance['content'] );
  }

  /**
   * Loop around all blocks and get block data.
   *
   * @param array $object Array of data rest api request.
   *
   * @return array
   */
  public function blocks_widget_get_callback( array $object ) {
    if ( ! $this->has_blocks_widget_get_callback( $object ) ) {
      return [];
    }

    $instance = $this->get_widget( $object );

    return BlockTransformer::get_blocks( $instance['content'] );
  }


  /**
   * Bootstrap filters and actions.
   *
   * @return void
   * 
   * @since    0.7.0
   */
  private function bootstrap()
  {
    add_action('rest_api_init', array($this, 'add_blocks_to_widget_rest_responses'));
  }
}
