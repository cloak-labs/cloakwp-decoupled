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

class Frontpage
{

  public function __construct()
  {
    $this->bootstrap();
  }

  /**
   * Register menu routes for WP API v2.
   *
   * @since  1.0.1
   */
  public function register_routes()
  {
    register_rest_route('wp/v2', '/frontpage', array(
        'methods'  => 'GET',
        'callback' => array( $this, 'get_frontpage' )
      )
    );
  }

      /**
     * Get the frontpage
     *
     * @since  1.0.1
     * @return Object of the frontpage (pageObject)
     */
    public static function get_frontpage() {

      // Get the ID of the static frontpage. If not set it's 0
      $page_id = get_option('page_on_front');

      // If the Frontpage is set, it's id shouldn't be 0
      if ( $page_id > 0 ) {

        // Create a request to get the frontpage
        $request = new \WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id );

				// Process the request and get the response
				$response = rest_do_request( $request );

      } else {
        $response = null;
      }

      // No static frontpage is set
      if( empty($response) ) {
        return new \WP_Error( '404',
          esc_html__( 'No Static Frontpage set', 'wpse' )
        );
      }

      // Return the response
      return $response;
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