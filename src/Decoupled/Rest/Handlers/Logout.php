<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Services\SessionManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Logout
{
  public function __construct(private readonly SessionManager $session)
  {
  }

  public function __invoke(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    return $this->session->logout($request);
  }
}
