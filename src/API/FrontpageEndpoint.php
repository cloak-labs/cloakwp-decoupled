<?php

namespace CloakWP\API;

/**
 * This class adds a "/frontpage" endpoint to the WordPress REST API to easily retrieve the website's home/front page data
 */
class FrontpageEndpoint
{
  protected static bool $isRegistered = false;

  public static function register()
  {
    if (!self::$isRegistered) {
      $self = new self(); // Create an instance of the class

      add_action('rest_api_init', function () use ($self) {
        register_rest_route('cloakwp', '/frontpage', array(
          'methods'  => 'GET',
          'callback' => array($self, 'get_frontpage'),
          'permission_callback' => '__return_true'
        ));
      });

      self::$isRegistered = true;
    }
  }

  /**
   * Get the frontpage post data
   */
  public function get_frontpage()
  {
    // Get the ID of the static frontpage. If not set it's 0
    $page_id = get_option('page_on_front');

    // If the Frontpage is set, its id shouldn't be 0
    if ($page_id > 0) {

      // Create a request to get the frontpage
      $request = new \WP_REST_Request('GET', '/wp/v2/pages/' . $page_id);

      // Process the request and get the response
      $response = rest_do_request($request);
    } else {
      $response = null;
    }

    // No static frontpage is set
    if (empty($response)) {
      return new \WP_Error(
        '404',
        esc_html__('No Static Frontpage set', 'wpse')
      );
    }

    // Return the response
    return $response;
  }
}
