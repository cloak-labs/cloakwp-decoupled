<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

final class MaintenanceProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->maintenance()->isRestApiLocked()) {
      return;
    }

    add_filter('rest_pre_dispatch', static function ($result, $server, $request) {
      if (is_user_logged_in()) {
        return $result;
      }

      $nonce = is_object($request) && method_exists($request, 'get_header')
        ? (string) $request->get_header('X-WP-Nonce')
        : '';
      if ($nonce !== '' && wp_verify_nonce($nonce, 'wp_rest')) {
        return $result;
      }

      return new \WP_Error('maintenance_mode', 'Site under maintenance', ['status' => 503]);
    }, 10, 3);
  }
}
