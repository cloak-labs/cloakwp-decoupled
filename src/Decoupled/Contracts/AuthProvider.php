<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

interface AuthProvider
{
  /**
   * Register the provider's authentication hooks.
   */
  public function register(): void;

  /**
   * REST permission callback for routes that require configured authentication.
   */
  public function authorize(mixed $request = null): mixed;
}
