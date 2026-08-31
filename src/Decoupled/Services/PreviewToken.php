<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

final class PreviewToken
{
  private \Closure $secretResolver;
  private \Closure $clock;

  public function __construct(
    ?callable $secretResolver = null,
    ?callable $clock = null,
  ) {
    $this->secretResolver = \Closure::fromCallable($secretResolver ?? static function (): string {
      if (!defined('CLOAKWP_AUTH_SECRET') || !is_string(\CLOAKWP_AUTH_SECRET) || \CLOAKWP_AUTH_SECRET === '') {
        throw new \RuntimeException(
          'CLOAKWP_AUTH_SECRET must be defined before CloakWP can issue block preview tokens.',
        );
      }

      return \CLOAKWP_AUTH_SECRET;
    });
    $this->clock = \Closure::fromCallable($clock ?? 'time');
  }

  public function issue(string $previewKey, string $pathname, int $ttl = 43200): string
  {
    if (
      $previewKey === ''
      || $pathname === ''
      || !str_starts_with($pathname, '/')
      || $ttl < 60
    ) {
      throw new \InvalidArgumentException(
        'Preview key, absolute pathname, and a token TTL of at least 60 seconds are required.',
      );
    }

    $payload = [
      'previewKey' => $previewKey,
      'pathname' => $pathname,
      'exp' => ($this->clock)() + $ttl,
    ];
    $encodedPayload = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $encodedPayload, ($this->secretResolver)(), true);

    return $encodedPayload . '.' . $this->base64UrlEncode($signature);
  }

  /**
   * @return array{previewKey: string, pathname: string, exp: int}|null
   */
  public function verify(string $token): ?array
  {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
      return null;
    }

    [$encodedPayload, $encodedSignature] = $parts;
    $signature = $this->base64UrlDecode($encodedSignature);
    $payloadJson = $this->base64UrlDecode($encodedPayload);
    if ($signature === null || $payloadJson === null) {
      return null;
    }

    $expected = hash_hmac('sha256', $encodedPayload, ($this->secretResolver)(), true);
    if (!hash_equals($expected, $signature)) {
      return null;
    }

    try {
      $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      return null;
    }

    if (
      !is_array($payload)
      || !is_string($payload['previewKey'] ?? null)
      || !is_string($payload['pathname'] ?? null)
      || !is_int($payload['exp'] ?? null)
      || $payload['exp'] < ($this->clock)()
    ) {
      return null;
    }

    return [
      'previewKey' => $payload['previewKey'],
      'pathname' => $payload['pathname'],
      'exp' => $payload['exp'],
    ];
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
