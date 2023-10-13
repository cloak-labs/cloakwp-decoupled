<?php

namespace CloakWP\API;

/**
 * This class adds an "/options" endpoint to the WordPress REST API to enable headless projects to easily retrieve data from ACF Options pages
 */
class OptionsEndpoint
{
  protected static bool $isRegistered = false;

  public static function register()
  {
    if (!self::$isRegistered) {
      $self = new self(); // Create an instance of the class

      add_action('rest_api_init', function () use ($self) {
        // Register options endpoint
        register_rest_route('cloakwp', '/options', array(
          'methods' => 'GET',
          'callback' => array($self, 'handle_all_options_request'),
          'permission_callback' => '__return_true'
        ));

        // Register option endpoint
        register_rest_route('cloakwp', '/options/(?P<option_slug>[a-zA-Z0-9-]+)', array(
          'methods' => 'GET',
          'callback' => array($self, 'handle_single_option_request'),
          'permission_callback' => '__return_true'
        ));
      });

      self::$isRegistered = true;
    }
  }

  // Callback function to retrieve all options
  public function handle_all_options_request($request)
  {
    // Get all options
    $options = get_fields('options');

    if (!$options) {
      return new \WP_Error('options_not_found', 'Zero options exist.', array('status' => 404));
    }

    return rest_ensure_response($options);
  }

  // TODO: Callback function to retrieve a particular option's data
  public function handle_single_option_request($request)
  {
    return rest_ensure_response('TODO: return single option');
  }
}
