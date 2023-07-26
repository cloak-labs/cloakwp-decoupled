<?php

namespace CloakWP\Frontend;

use CloakWP\CloakWP;

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
 * This class defines all code necessary to run headless preview mode.
 *
 * @since      0.6.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */
class Preview
{

  public function __construct()
  {
    $this->add_frontend_preview_link();
    $this->add_frontend_preview_redirect();
  }

  /** 
   * Modify 'Preview' links on posts, pages to point to frontend URL
   * Request to NextJS API Route generates the frontend page using preview data and redirects to it
   * Requires a 'preview secret' as a query param to match on Wordpress and NextJS server
   * 
   * @param bool CLOAKWP_API_BASE_PATH
   * @param string $preview_link
   * @param object $post
   * 
   * @return string
   * 
   * @since    0.6.0
   */
  public function get_preview_url($post)
  {
    $cloakwp_api_base = \CLOAKWP_API_BASE_PATH;
    $revisionId = $post->ID; // the ID of the post revision, not the master post
    $postId = $post->post_parent; // the revision's parent == the post we're previewing
    $postType = get_post_type($postId); // the master/parent post's post type --> important for cloakwp to retrieve the correct revision data  
    $secret = CloakWP::get_preview_secret();
    $front_end_url = CloakWP::get_frontend_url();
    return "{$front_end_url}/api/{$cloakwp_api_base}/preview?revisionId={$revisionId}&postId={$postId}&postType={$postType}&secret={$secret}";
  }

  /** 
   * Redirect WP preview page to Next preview --> this is in addition to our 'preview_post_link' filter above that changes the preview link (which doesn't work all the time due to known bugs).
   * If somehow our 'preview_post_link' filter doesn't work and the admin user ends up on the default WP preview URL, this redirects them to our Next preview API route
   * 
   * @param string CLOAKWP_API_BASE_PATH
   * 
   * @return void
   * 
   * @since    0.6.0
   */
  public static function preview_post_redirect()
  {
    if (isset($_GET["preview"]) && $_GET["preview"] == true) {
      $front_end_url = CloakWP::get_frontend_url();
      $cloakwp_api_base = \CLOAKWP_API_BASE_PATH;
      $secret = CloakWP::get_preview_secret();
      $postId = $_GET["p"];
      $postType = get_post_type($postId); // the master/parent post's post type --> important for cloakwp to retrieve the correct revision data  
      wp_redirect("{$front_end_url}/api/{$cloakwp_api_base}/preview?postId={$postId}&postType={$postType}&secret={$secret}");
      exit();
    }
  }

  /**
   * @return void
   * 
   * @since    0.6.0
   */
  private function add_frontend_preview_link()
  {
    add_filter('preview_post_link', array($this, 'get_preview_url'), 10);
  }

  /**
   * @return void
   * 
   * @since    0.6.0
   */
  private function add_frontend_preview_redirect()
  {
    add_action('template_redirect', array($this, 'preview_post_redirect'));
  }
}
