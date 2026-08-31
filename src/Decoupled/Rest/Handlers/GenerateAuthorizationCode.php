<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Services\SessionManager;
use WP_Error;

/**
 * Reserved for a later redirect/SSO login strategy. The issuer that would
 * mint a one-minute code from an existing wp-admin session is not shipped.
 */
final class GenerateAuthorizationCode
{
  public function __construct(private readonly SessionManager $session)
  {
  }

  public function __invoke(): WP_Error
  {
    return $this->session->generateAuthorizationCode();
  }
}
