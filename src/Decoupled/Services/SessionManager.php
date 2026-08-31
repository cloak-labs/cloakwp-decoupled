<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

use CloakWP\Decoupled\Contracts\SessionStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class SessionManager
{
  public const SECRET_HEADER = 'X-CloakWP-Secret';

  public const ACCESS_TTL = 900;

  public const REFRESH_TTL = 1_209_600;

  public const HANDSHAKE_TTL = 60;

  public const PURPOSE_LOGIN = 'login';

  public const PURPOSE_LOGOUT = 'logout';

  private \Closure $secretResolver;

  private \Closure $allowedOrigins;

  private \Closure $wordpressOrigin;

  private \Closure $clock;

  /** @var callable */
  private mixed $authenticator;

  /** @var callable */
  private mixed $setAuthCookie;

  /** @var callable */
  private mixed $logout;

  private ?\Closure $authorizationCodeRedeemer;

  public function __construct(
    private readonly SessionStore $store,
    private readonly SessionTokenCodec $tokens,
    callable $secretResolver,
    callable $allowedOrigins,
    callable $wordpressOrigin,
    ?callable $clock = null,
    ?callable $authenticator = null,
    ?callable $setAuthCookie = null,
    ?callable $logout = null,
    ?callable $authorizationCodeRedeemer = null,
  ) {
    $this->secretResolver = \Closure::fromCallable($secretResolver);
    $this->allowedOrigins = \Closure::fromCallable($allowedOrigins);
    $this->wordpressOrigin = \Closure::fromCallable($wordpressOrigin);
    $this->clock = \Closure::fromCallable($clock ?? 'time');
    // Pluggable WP functions are not loaded when MU-plugins construct CMS.
    $this->authenticator = $authenticator ?? 'wp_authenticate';
    $this->setAuthCookie = $setAuthCookie ?? 'wp_set_auth_cookie';
    $this->logout = $logout ?? 'wp_logout';
    $this->authorizationCodeRedeemer = $authorizationCodeRedeemer !== null
      ? \Closure::fromCallable($authorizationCodeRedeemer)
      : null;
  }

  public function authorize(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    $secretError = $this->assertSecret($request->get_header(self::SECRET_HEADER));
    if ($secretError !== null) {
      return $secretError;
    }

    $grant = (string) ($request->get_param('grant_type') ?? '');
    return match ($grant) {
      'password' => $this->passwordGrant($request),
      'refresh_token' => $this->refreshGrant($request),
      'authorization_code' => $this->authorizationCodeGrant($request),
      default => new WP_Error(
        'unsupported_grant_type',
        'grant_type must be password, refresh_token, or authorization_code.',
        ['status' => 400],
      ),
    };
  }

  public function logout(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    $secretError = $this->assertSecret($request->get_header(self::SECRET_HEADER));
    if ($secretError !== null) {
      return $secretError;
    }

    $refreshToken = (string) ($request->get_param('refresh_token') ?? '');
    if ($refreshToken === '') {
      return new WP_Error('invalid_request', 'A refresh_token is required.', ['status' => 400]);
    }

    $userId = $this->store->consumeRefreshToken($this->hash($refreshToken));
    if ($userId === null) {
      return new WP_Error('invalid_grant', 'Refresh token is invalid or expired.', ['status' => 401]);
    }

    $this->store->revokeAllRefreshTokens($userId);

    return rest_ensure_response([
      'wpLogoutCode' => $this->issueHandshakeCode($userId, self::PURPOSE_LOGOUT),
    ]);
  }

  public function establishSession(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    return $this->establish($request, self::PURPOSE_LOGIN, function (int $userId): void {
      ($this->setAuthCookie)($userId, true);
    });
  }

  public function establishLogout(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    return $this->establish($request, self::PURPOSE_LOGOUT, function (): void {
      ($this->logout)();
    });
  }

  /**
   * @return array{sub: int, exp: int}|null
   */
  public function verifyAccessToken(string $token): ?array
  {
    return $this->tokens->verifyAccessToken($token);
  }

  public function revokeAllRefreshTokens(int $userId): void
  {
    $this->store->revokeAllRefreshTokens($userId);
  }

  public function generateAuthorizationCode(): WP_Error
  {
    return new WP_Error(
      'authorization_code_not_issued',
      'Authorization-code generation is not implemented. Use the password grant until redirect login ships.',
      ['status' => 501],
    );
  }

  private function passwordGrant(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    $username = (string) ($request->get_param('username') ?? '');
    $password = (string) ($request->get_param('password') ?? '');
    if ($username === '' || $password === '') {
      return new WP_Error('invalid_request', 'Username and password are required.', ['status' => 400]);
    }

    $user = ($this->authenticator)($username, $password);
    if (is_wp_error($user) || !$user instanceof WP_User) {
      return new WP_Error('invalid_grant', 'Invalid username or password.', ['status' => 401]);
    }

    do_action('wp_login', $user->user_login, $user);

    return rest_ensure_response($this->issueSession($user));
  }

  private function refreshGrant(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    $refreshToken = (string) ($request->get_param('refresh_token') ?? '');
    if ($refreshToken === '') {
      return new WP_Error('invalid_request', 'A refresh_token is required.', ['status' => 400]);
    }

    $userId = $this->store->consumeRefreshToken($this->hash($refreshToken));
    if ($userId === null) {
      return new WP_Error('invalid_grant', 'Refresh token is invalid, expired, or already used.', ['status' => 401]);
    }

    $user = get_userdata($userId);
    if (!$user instanceof WP_User) {
      return new WP_Error('invalid_grant', 'The refresh token user no longer exists.', ['status' => 401]);
    }

    return rest_ensure_response($this->issueSession($user));
  }

  private function authorizationCodeGrant(WP_REST_Request $request): WP_REST_Response|WP_Error
  {
    if ($this->authorizationCodeRedeemer === null) {
      return new WP_Error(
        'authorization_code_unavailable',
        'No authorization-code issuer is configured.',
        ['status' => 501],
      );
    }

    $code = (string) ($request->get_param('code') ?? '');
    if ($code === '') {
      return new WP_Error('invalid_request', 'An authorization code is required.', ['status' => 400]);
    }

    $userId = ($this->authorizationCodeRedeemer)($code);
    if (!is_int($userId) || $userId < 1) {
      return new WP_Error('invalid_grant', 'Authorization code is invalid or expired.', ['status' => 401]);
    }

    $user = get_userdata($userId);
    if (!$user instanceof WP_User) {
      return new WP_Error('invalid_grant', 'Authorization code user no longer exists.', ['status' => 401]);
    }

    do_action('wp_login', $user->user_login, $user);

    return rest_ensure_response($this->issueSession($user));
  }

  /**
   * @return array{
   *   accessToken: string,
   *   accessTokenExpiration: int,
   *   refreshToken: string,
   *   refreshTokenExpiration: int,
   *   wpLoginCode: string,
   *   user: array{id: int, name: string}
   * }
   */
  private function issueSession(WP_User $user): array
  {
    $now = ($this->clock)();
    $accessExp = $now + self::ACCESS_TTL;
    $refreshExp = $now + self::REFRESH_TTL;
    $refreshToken = $this->randomToken();
    $this->store->saveRefreshToken((int) $user->ID, $this->hash($refreshToken), $refreshExp);

    return [
      'accessToken' => $this->tokens->issueAccessToken((int) $user->ID, self::ACCESS_TTL),
      'accessTokenExpiration' => $accessExp,
      'refreshToken' => $refreshToken,
      'refreshTokenExpiration' => $refreshExp,
      'wpLoginCode' => $this->issueHandshakeCode((int) $user->ID, self::PURPOSE_LOGIN),
      'user' => [
        'id' => (int) $user->ID,
        'name' => (string) $user->display_name,
      ],
    ];
  }

  private function issueHandshakeCode(int $userId, string $purpose): string
  {
    $code = $this->randomToken();
    $this->store->saveHandshakeCode(
      $this->hash($code),
      $userId,
      $purpose,
      ($this->clock)() + self::HANDSHAKE_TTL,
    );

    return $code;
  }

  /**
   * @param callable(int): void $onEstablish
   */
  private function establish(
    WP_REST_Request $request,
    string $purpose,
    callable $onEstablish,
  ): WP_REST_Response|WP_Error {
    $code = (string) ($request->get_param('code') ?? '');
    $redirect = (string) ($request->get_param('redirect') ?? '');
    if ($code === '' || $redirect === '') {
      return new WP_Error('invalid_request', 'code and redirect are required.', ['status' => 400]);
    }

    if (!$this->isAllowedRedirect($redirect)) {
      return new WP_Error('invalid_redirect', 'Redirect origin is not allowlisted.', ['status' => 401]);
    }

    $handshake = $this->store->consumeHandshakeCode($this->hash($code));
    if ($handshake === null || $handshake['purpose'] !== $purpose) {
      return new WP_Error('invalid_grant', 'Handshake code is invalid, expired, or already used.', ['status' => 401]);
    }

    $onEstablish($handshake['userId']);

    return new WP_REST_Response(null, 302, ['Location' => $redirect]);
  }

  public function isAllowedRedirect(string $redirect): bool
  {
    $parts = parse_url($redirect);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return false;
    }

    $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }

    $path = $parts['path'] ?? '/';
    $wordpressOrigin = ($this->wordpressOrigin)();
    if ($wordpressOrigin !== '' && $origin === $wordpressOrigin) {
      return str_starts_with($path, '/wp-admin');
    }

    return in_array($origin, ($this->allowedOrigins)(), true);
  }

  private function assertSecret(string $provided): ?WP_Error
  {
    try {
      $secret = ($this->secretResolver)();
    } catch (\RuntimeException $exception) {
      return new WP_Error('session_unconfigured', $exception->getMessage(), ['status' => 503]);
    }

    if ($provided === '' || !hash_equals($secret, $provided)) {
      return new WP_Error('invalid_secret', 'Invalid session secret.', ['status' => 401]);
    }

    return null;
  }

  private function hash(string $value): string
  {
    return hash('sha256', $value);
  }

  private function randomToken(): string
  {
    return bin2hex(random_bytes(32));
  }
}
