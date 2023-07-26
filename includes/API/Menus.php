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
 * This class adds a "menus" endpoint to the WordPress REST API to enable headless projects to easily retrieve WP menus
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class Menus
{

  public function __construct()
  {
    $this->bootstrap();
  }

  public function register_routes()
  {
    // Register menus endpoint
    register_rest_route('cloakwp', '/menus', array(
      'methods' => 'GET',
      'callback' => array($this, 'handle_all_menus_request'),
    ));

    // Register menu endpoint
    register_rest_route('cloakwp', '/menus/(?P<menu_slug>[a-zA-Z0-9-]+)', array(
      'methods' => 'GET',
      'callback' => array($this, 'handle_single_menu_request'),
    ));
  }

  // Callback function to retrieve all menus
  function handle_all_menus_request($request)
  {
    // Get all menus
    $menus = wp_get_nav_menus();

    if (!$menus) {
      return new \WP_Error('menus_not_found', 'Zero menus exist.', array('status' => 404));
    }

    return $this->get_menu_data($menus);
  }

  // Callback function to retrieve a particular menu's data
  public function handle_single_menu_request($request)
  {
    $menu_slug = $request->get_param('menu_slug');

    // Get menu by slug
    $menu = wp_get_nav_menu_object($menu_slug);

    if (!$menu) {
      return new \WP_Error('menu_not_found', 'Menu not found.', array('status' => 404));
    }

    return $this->get_menu_data($menu)[0];
  }

  // helper function used by both menu endpoints to retrieve and format menu data
  private function get_menu_data($menus)
  {
    if (!is_array($menus)) $menus = [$menus];

    $formatted_menus = array();

    foreach ($menus as $menu) {
      if (!$menu) continue;

      // Get menu items
      $menu_items = wp_get_nav_menu_items($menu->term_id);

      // Process and format menu items data:
      $formatted_items = $this->filter_menu_items_data($menu_items);

      $formatted_menus[] = array(
        ...get_object_vars($menu),
        'menu_items' => $formatted_items,
      );
    }

    return $formatted_menus;
  }

  // Process and format menu_items, reducing the data returned by the REST API
  private function filter_menu_items_data($menu_items)
  {
    $formatted_items = array();

    foreach ($menu_items as $item) {
      $formatted_items[] = array(
        'id' => $item->ID,
        'title' => $item->title,
        'url' => $item->url,
        'target' => $item->target,
        'link_type' => $item->object,
        'menu_item_parent' => $item->menu_item_parent,
        'menu_order' => $item->menu_order,
      );
    }

    return $formatted_items;
  }

  /**
   * Bootstrap filters and actions.
   *
   * @return void
   */
  private function bootstrap()
  {
    // Add custom endpoint for menus
    add_action('rest_api_init', array($this, 'register_routes'));
  }
}
