<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Auth;

use CloakWP\Decoupled\Contracts\AuthProvider;

/**
 * Optional REST authentication via cloakwp/jwt-auth.
 *
 * Legacy machine-auth fallback for deployed sites still sending `WP_JWT`.
 * CloakWP session access tokens are verified first (priority 5); jwt-auth
 * continues to accept its own Bearer tokens afterward.
 */
final class JwtAuthProvider implements AuthProvider
{
  private \Closure $authFactory;

  public function __construct(
    private readonly int $expirationSeconds = 3600,
    ?callable $authFactory = null,
  ) {
    if ($expirationSeconds < 60 || $expirationSeconds > 86400) {
      throw new \InvalidArgumentException('JWT expiration must be between 60 seconds and 24 hours.');
    }

    $this->authFactory = \Closure::fromCallable(
      $authFactory ?? function (): object {
        $this->assertDependencyInstalled();
        return new \CloakWP\JWTAuth\JWTAuth();
      },
    );
  }

  public function register(): void
  {
    $this->assertDependencyInstalled();

    add_filter('jwt_auth_expire', fn(): int => time() + $this->expirationSeconds, 10, 1);

    \CloakWP\JWTAuth\JWTAuthRegistrar::getInstance();
  }

  public function authorize(mixed $request = null): mixed
  {
    $auth = ($this->authFactory)();
    $payload = $auth->validate_token(false);

    if (!$auth->is_error_response($payload)) {
      return true;
    }

    if (is_wp_error($payload)) {
      return $payload;
    }

    $data = is_object($payload) && method_exists($payload, 'get_data')
      ? $payload->get_data()
      : [];
    $status = is_object($payload) && method_exists($payload, 'get_status')
      ? (int) $payload->get_status()
      : 401;

    return new \WP_Error(
      is_array($data) && is_string($data['code'] ?? null)
        ? $data['code']
        : 'cloakwp_unauthorized',
      is_array($data) && is_string($data['message'] ?? null)
        ? $data['message']
        : 'Authentication is required.',
      ['status' => $status ?: 401],
    );
  }

  private function assertDependencyInstalled(): void
  {
    if (
      !class_exists(\CloakWP\JWTAuth\JWTAuthRegistrar::class)
      || !class_exists(\CloakWP\JWTAuth\JWTAuth::class)
    ) {
      throw new \RuntimeException(
        'JwtAuthProvider requires the optional cloakwp/jwt-auth package. Install it before selecting this provider.',
      );
    }
  }
}
