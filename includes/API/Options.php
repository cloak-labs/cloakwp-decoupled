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
 * This class adds an "options" endpoint to the WordPress REST API to enable headless projects to easily retrieve data from ACF Options pages
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class Options
{

  public function __construct()
  {
    $this->bootstrap();
  }

  public function register_routes()
  {
    // Register options endpoint
    register_rest_route('cloakwp', '/options', array(
      'methods' => 'GET',
      'callback' => array($this, 'handle_all_options_request'),
    ));

    // Register option endpoint
    register_rest_route('cloakwp', '/options/(?P<option_slug>[a-zA-Z0-9-]+)', array(
      'methods' => 'GET',
      'callback' => array($this, 'handle_single_option_request'),
    ));
  }

  // Callback function to retrieve all options
  function handle_all_options_request($request)
  {
    // Get all options
    $options = get_fields('options');

    if (!$options) {
      return new \WP_Error('options_not_found', 'Zero options exist.', array('status' => 404));
    }

    return $options;
  }

  // Callback function to retrieve a particular option's data
  public function handle_single_option_request($request)
  {
    // $option_slug = $request->get_param('option_slug');

    // // Get option by slug
    // $option = wp_get_nav_option_object($option_slug);

    // if (!$option) {
    //   return new \WP_Error('option_not_found', 'Menu not found.', array('status' => 404));
    // }

    // return $this->get_option_data($option)[0];
    return 'TODO: return single option';
  }

  /**
   * Bootstrap filters and actions.
   *
   * @return void
   */
  private function bootstrap()
  {
    // Add custom endpoint for options
    add_action('rest_api_init', array($this, 'register_routes'));
  }
}
