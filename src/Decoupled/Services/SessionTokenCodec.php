<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

/**
 * HMAC-SHA256 JWTs for CloakWP access tokens (sub + exp). Signature-only on
 * the hot path; jwt-auth tokens use a different secret and will not verify.
 */
final class SessionTokenCodec
{
  private \Closure $secretResolver;

  private \Closure $clock;

  public function __construct(
    callable $secretResolver,
    ?callable $clock = null,
  ) {
    $this->secretResolver = \Closure::fromCallable($secretResolver);
    $this->clock = \Closure::fromCallable($clock ?? 'time');
  }

  public function issueAccessToken(int $userId, int $ttl = 900): string
  {
    if ($userId < 1 || $ttl < 60) {
      throw new \InvalidArgumentException('A positive user ID and access TTL of at least 60 seconds are required.');
    }

    return $this->encode([
      'typ' => 'access',
      'sub' => (string) $userId,
      'exp' => ($this->clock)() + $ttl,
    ]);
  }

  /**
   * @return array{sub: int, exp: int}|null
   */
  public function verifyAccessToken(string $token): ?array
  {
    if ($token === '') {
      return null;
    }

    try {
      $secret = ($this->secretResolver)();
    } catch (\RuntimeException) {
      return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      return null;
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $signature = $this->base64UrlDecode($encodedSignature);
    if ($signature === null) {
      return null;
    }

    $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
    if (!hash_equals($expected, $signature)) {
      return null;
    }

    $payloadJson = $this->base64UrlDecode($encodedPayload);
    if ($payloadJson === null) {
      return null;
    }

    try {
      $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      return null;
    }

    if (
      !is_array($payload)
      || ($payload['typ'] ?? null) !== 'access'
      || !isset($payload['sub'], $payload['exp'])
    ) {
      return null;
    }

    $exp = $payload['exp'];
    if (!is_int($exp) && !is_numeric($exp)) {
      return null;
    }
    if ((int) $exp < ($this->clock)()) {
      return null;
    }

    $sub = (int) $payload['sub'];
    if ($sub < 1) {
      return null;
    }

    return [
      'sub' => $sub,
      'exp' => (int) $exp,
    ];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function encode(array $payload): string
  {
    $header = $this->base64UrlEncode(
      (string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    $body = $this->base64UrlEncode(
      (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    $signature = hash_hmac('sha256', $header . '.' . $body, ($this->secretResolver)(), true);

    return $header . '.' . $body . '.' . $this->base64UrlEncode($signature);
  }

  private function base64UrlEncode(string $value): string
  {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private function base64UrlDecode(string $value): ?string
  {
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
      $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
  }
}
