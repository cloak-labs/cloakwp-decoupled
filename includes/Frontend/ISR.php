<?php

namespace CloakWP\Frontend;

use CloakWP\CloakWP;
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
 * This class defines all code necessary to run ISR.
 *
 * @since      0.6.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */
class ISR
{

  public function __construct()
  {
    $this->enable_isr();
  }

  /**
   * On-demand Incremental Static Regeneration (ISR) 
   * 
   * rebuild static pages/posts immediately upon saving your changes in WP
   * 
   * 
   * @param int $post_ID
   * @param object $post
   * @param bool CLOAKWP_API_BASE_PATH
   * 
   * @return void
   * 
   * @since    0.6.0
   */
  public static function revalidate_on_save($post_ID, $post)
  {
    $front_end_url = CloakWP::get_frontend_url();
    $cloakwp_api_base = \CLOAKWP_API_BASE_PATH;

    // use this filter to add URLs for environments that you wish to enable on-demand ISR for (useful for testing one-off Vercel deployments or when running a production build locally) 
    $environments_to_revalidate = apply_filters('cloakwp/urls_to_revalidate', [$front_end_url, "http://localhost:3000"], $post_ID, $post);
    $pathname = parse_url(get_permalink($post_ID), PHP_URL_PATH);
    $secret = CloakWP::get_preview_secret();
    
    foreach ($environments_to_revalidate as $url) {
      try {
        wp_remote_get("{$url}/api/{$cloakwp_api_base}/revalidate/?pathname={$pathname}&secret={$secret}");
      } catch (Exception $e) {
        echo 'Error while regenerating static page for url "', $url, '" -- error message: ', $e->getMessage(), "\n";
      }
    }
  }


  /**
   * On-demand Incremental Static Regeneration (ISR) 
   * 
   * rebuild static pages/posts immediately upon saving your changes in WP
   * 
   * @return void
   * 
   * @since    0.6.0
   */
  public function enable_isr()
  {
    add_action('save_post_page', array($this, 'revalidate_on_save'), 10, 2);
  }
}
