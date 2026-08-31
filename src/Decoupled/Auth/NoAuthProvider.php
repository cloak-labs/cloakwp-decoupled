<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Auth;

use CloakWP\Decoupled\Contracts\AuthProvider;

final class NoAuthProvider implements AuthProvider
{
  public function register(): void
  {
  }

  public function authorize(mixed $request = null): bool
  {
    return true;
  }
}
