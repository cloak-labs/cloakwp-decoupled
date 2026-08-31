<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

final class CorsProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) use ($cms) {
      $origin = $cms->originFromUrl((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
      if ($origin === '' || !in_array($origin, $this->allowedOrigins($cms), true)) {
        return $value;
      }

      header('Access-Control-Allow-Origin: ' . $origin);
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: cache-control, X-WP-Nonce, Content-Type, Authorization, X-CloakWP-Secret, Access-Control-Allow-Headers, Accept');
      header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages', false);
      header('Vary: Origin', false);

      return $value;
    });

    add_action('send_headers', function () {
      if (!did_action('rest_api_init') && ($_SERVER['REQUEST_METHOD'] ?? '') == 'HEAD') {
        header('Access-Control-Expose-Headers: Link');
        header('Access-Control-Allow-Methods: HEAD');
      }
    });
  }

  /** @return list<string> */
  public function allowedOrigins(CMS $cms): array
  {
    return $cms->frontendOrigins();
  }
}
