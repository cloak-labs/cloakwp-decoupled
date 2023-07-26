<?php

namespace CloakWP\Frontend;

use CloakWP\CloakWP;
use CloakWP\Utils;
use Exception;

/**
 * Fired during plugin activation
 *
 * @link       https://https://github.com/cloak-labs
 * @since      0.6.0
 *
 * @package    CloakWP
 * @subpackage CloakWP/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to set auth status on headless front-end based on WP auth status.
 *
 * @since      0.6.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */
class Auth
{

  public function __construct()
  {
    $this->add_frontend_login();
    $this->add_frontend_logout();
  }

  /** 
   * Whenever you log in to WordPress, we redirect to an API endpoint on our Next front-end (/api/login) 
   * which sets a cookie that tells cloakwp you're logged in, and it redirects you back to the WP Admin dashboard.
   * CloakWP will read this cookie to determine when to show the AdminBar component. We use a redirect rather than
   * a GET request so that it also works while in local development (can't GET request localhost from WP) 
   *
   * @param string CLOAKWP_API_BASE_PATH
   * 
   * @return void 
   */
  public function login_on_frontend()
  {
    $url = CloakWP::get_frontend_url();
    $secret = CloakWP::get_preview_secret();
    $cloakwp_api_base = \CLOAKWP_API_BASE_PATH;

    try {
      wp_redirect("{$url}/api/{$cloakwp_api_base}/login?secret={$secret}");
      exit();
    } catch (Exception $e) {
      Utils::write_log("Error while logging in on front-end ({$url}). Error message: ", $e->getMessage(), "\n");
    }
  }


  /** 
   * Whenever you log out of WordPress, we redirect to an API endpoint on our Next front-end (/api/logout) 
   * which sets a cookie that tells cloakwp you're logged out, and it redirects you back to the WP login screen.
   * CloakWP will read this cookie to determine when to show the AdminBar component. We use a redirect rather than
   * a GET request so that it also works while in local development (can't GET request localhost from WP) 
   *
   * @param string CLOAKWP_API_BASE_PATH
   * 
   * @return void
   */
  public function logout_on_frontend()
  {
    $url = CloakWP::get_frontend_url();
    $secret = CloakWP::get_preview_secret();
    $cloakwp_api_base = \CLOAKWP_API_BASE_PATH;

    try {
      wp_redirect("{$url}/api/{$cloakwp_api_base}/logout?secret={$secret}");
      exit();
    } catch (Exception $e) {
      Utils::write_log("Error while logging out on front-end ({$url}). Error message: ", $e->getMessage(), "\n");
    }
  }

  /**
   * @return void
   * 
   * @since    0.6.0
   */
  private function add_frontend_login()
  {
    add_action('wp_login', array($this, 'login_on_frontend'));
  }

  /**
   * @return void
   * 
   * @since    0.6.0
   */
  private function add_frontend_logout()
  {
    add_action('wp_logout', array($this, 'logout_on_frontend'));
  }
}
