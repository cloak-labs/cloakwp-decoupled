<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

final class GetFrontpage
{
  public function __invoke(): mixed
  {
    $pageId = (int) get_option('page_on_front');
    if ($pageId <= 0) {
      return new \WP_Error('404', esc_html__('No Static Frontpage set', 'cloakwp'), ['status' => 404]);
    }

    $response = rest_do_request(new \WP_REST_Request('GET', '/wp/v2/pages/' . $pageId));
    if ($response === null) {
      return new \WP_Error('404', esc_html__('No Static Frontpage set', 'cloakwp'), ['status' => 404]);
    }

    return $response;
  }
}
