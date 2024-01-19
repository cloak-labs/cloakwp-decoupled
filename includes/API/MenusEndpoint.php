<?php

namespace CloakWP\API;

/**
 * This class adds a "/menus" endpoint to the WordPress REST API to enable headless projects to easily retrieve WP menus
 */
class MenusEndpoint
{
  protected static bool $isRegistered = false;

  public static function register()
  {
    if (!self::$isRegistered) {
      $self = new self(); // Create an instance of the class

      add_action('rest_api_init', function () use ($self) {
        // Register menus endpoint
        register_rest_route(
          'cloakwp',
          '/menus',
          array(
            'methods' => 'GET',
            'callback' => array($self, 'handle_all_menus_request'),
            'permission_callback' => '__return_true'
          )
        );

        // Register menu endpoint
        register_rest_route(
          'cloakwp',
          '/menus/(?P<menu_slug>[a-zA-Z0-9-]+)',
          array(
            'methods' => 'GET',
            'callback' => array($self, 'handle_single_menu_request'),
            'permission_callback' => '__return_true'
          )
        );
      });

      self::$isRegistered = true;
    }
  }

  // Callback function to retrieve all menus
  public function handle_all_menus_request($request)
  {
    // Get all menus
    $menus = wp_get_nav_menus();

    if (!$menus) {
      return new \WP_Error('menus_not_found', 'Zero menus exist.', array('status' => 404));
    }

    $data = $this->get_menu_data($menus);

    return rest_ensure_response($data);
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

    $data = $this->get_menu_data($menu)[0];
    return rest_ensure_response($data);
  }

  // helper function used by both menu endpoints to retrieve and format menu data
  private function get_menu_data($menus)
  {
    if (!is_array($menus))
      $menus = [$menus];

    $formatted_menus = array();

    foreach ($menus as $menu) {
      if (!$menu)
        continue;

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
    $formatted_items = [];
    $parent_items = [];

    foreach ($menu_items as $item) {
      $type = $item->object;
      $url = $item->url;

      if ($type != 'custom') { // format internal links:
        // chop off domain name so we're left with a relative path
        $url = str_replace(\MY_FRONTEND_URL, "", $url);

        // Remove trailing backslash:
        // $url = rtrim($url, '/');
      }

      $formatted_item = array(
        'id' => $item->ID,
        'title' => $item->title,
        'description' => $item->description,
        'url' => $url,
        'target' => $item->target,
        'link_type' => $type,
        'menu_item_parent' => $item->menu_item_parent,
        'menu_order' => $item->menu_order,
        'sub_menu_items' => [] // Prepare for potential submenu items
      );

      $formatted_items[$item->ID] = $formatted_item;
      if ($item->menu_item_parent == '0') {
        // Store top-level items separately for easier assembly
        $parent_items[$item->ID] = &$formatted_items[$item->ID];
      }
    }

    // Now nest submenu items under their respective parent items
    foreach ($formatted_items as $id => &$item) {
      if ($item['menu_item_parent'] != '0') {
        $parent_id = $item['menu_item_parent'];
        if (isset($formatted_items[$parent_id])) {
          $formatted_items[$parent_id]['sub_menu_items'][] = &$item;
        }
      }
    }

    // Return only top-level items, as they now contain the submenus
    return array_values($parent_items);
  }
}
