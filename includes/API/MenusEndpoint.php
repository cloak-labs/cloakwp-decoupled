<?php

namespace CloakWP\API;

use CloakWP\Eloquent\Model\Menu;
use CloakWP\Eloquent\Model\Menu\MenuLocation;

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
          array (
            'methods' => 'GET',
            'callback' => array ($self, 'handle_all_menus_request'),
            'permission_callback' => '__return_true'
          )
        );

        // Register menu endpoint
        register_rest_route(
          'cloakwp',
          '/menus/(?P<menu_slug>[a-zA-Z0-9-]+)',
          array (
            'methods' => 'GET',
            'callback' => array ($self, 'handle_single_menu_request'),
            'permission_callback' => '__return_true'
          )
        );
      });

      self::$isRegistered = true;
    }
  }

  // Callback function to retrieve all menus
  public function handle_all_menus_request(\WP_REST_Request $request)
  {
    $params = $request->get_query_params();
    $locationFilter = $params['location'];

    $menus = null;
    if ($locationFilter) {
      $menuAtLocation = MenuLocation::getMenuByLocation($locationFilter);
      if ($menuAtLocation && method_exists($menuAtLocation, 'getStructuredMenu')) {
        $menus = $menuAtLocation->getStructuredMenu();
      } else {
        $menus = null;
      }
    } else {
      $menus = Menu::all()->map(function (Menu $menu) {
        return $menu->getStructuredMenu();
      });
    }

    if (!$menus) {
      $error_msg = $locationFilter ? "No menus are assigned to the location, '$locationFilter'." : 'No menus exist.';
      return new \WP_Error('menus_not_found', $error_msg, array('status' => 404));
    }

    return rest_ensure_response($menus);
  }

  // Callback function to retrieve a particular menu's data
  public function handle_single_menu_request($request)
  {
    $menu_slug = $request->get_param('menu_slug');

    // Get menu by slug
    $menu = Menu::findBySlug($menu_slug);

    if (!$menu) {
      return new \WP_Error('menu_not_found', 'Menu not found.', array('status' => 404));
    }

    return rest_ensure_response($menu->getStructuredMenu());
  }
}
