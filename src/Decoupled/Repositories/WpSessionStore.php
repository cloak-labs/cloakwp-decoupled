<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Repositories;

use CloakWP\Decoupled\Contracts\SessionStore;

/**
 * Refresh tokens live in a site option keyed by token hash. Handshake codes
 * use transients so they expire without a sweeper.
 */
final class WpSessionStore implements SessionStore
{
  public const REFRESH_OPTION = 'cloakwp_refresh_tokens';

  public const HANDSHAKE_TRANSIENT_PREFIX = 'cloakwp_hs_';

  public function saveRefreshToken(int $userId, string $tokenHash, int $expiresAt): void
  {
    $all = $this->allRefresh();
    $all[$tokenHash] = [
      'userId' => $userId,
      'exp' => $expiresAt,
    ];
    $this->writeRefresh($all);
  }

  public function consumeRefreshToken(string $tokenHash): ?int
  {
    $all = $this->allRefresh();
    $entry = $all[$tokenHash] ?? null;
    if (!is_array($entry)) {
      return null;
    }

    unset($all[$tokenHash]);
    $this->writeRefresh($all);

    if ((int) ($entry['exp'] ?? 0) < time()) {
      return null;
    }

    return (int) ($entry['userId'] ?? 0) ?: null;
  }

  public function revokeRefreshToken(string $tokenHash): void
  {
    $all = $this->allRefresh();
    if (!isset($all[$tokenHash])) {
      return;
    }

    unset($all[$tokenHash]);
    $this->writeRefresh($all);
  }

  public function revokeAllRefreshTokens(int $userId): void
  {
    $kept = [];
    foreach ($this->allRefresh() as $hash => $entry) {
      if (!is_array($entry) || (int) ($entry['userId'] ?? 0) === $userId) {
        continue;
      }
      $kept[$hash] = $entry;
    }
    $this->writeRefresh($kept);
  }

  public function saveHandshakeCode(string $codeHash, int $userId, string $purpose, int $expiresAt): void
  {
    $ttl = max(1, $expiresAt - time());
    set_transient(self::HANDSHAKE_TRANSIENT_PREFIX . $codeHash, [
      'userId' => $userId,
      'purpose' => $purpose,
      'exp' => $expiresAt,
    ], $ttl);
  }

  public function consumeHandshakeCode(string $codeHash): ?array
  {
    $key = self::HANDSHAKE_TRANSIENT_PREFIX . $codeHash;
    $stored = get_transient($key);
    delete_transient($key);
    if (!is_array($stored) || !isset($stored['userId'], $stored['purpose'])) {
      return null;
    }

    if ((int) ($stored['exp'] ?? 0) < time()) {
      return null;
    }

    return [
      'userId' => (int) $stored['userId'],
      'purpose' => (string) $stored['purpose'],
    ];
  }

  /**
   * @return array<string, array{userId: int, exp: int}>
   */
  private function allRefresh(): array
  {
    $stored = get_option(self::REFRESH_OPTION, []);
    if (!is_array($stored)) {
      return [];
    }

    $now = time();
    $kept = [];
    foreach ($stored as $hash => $entry) {
      if (!is_string($hash) || !is_array($entry) || (int) ($entry['exp'] ?? 0) < $now) {
        continue;
      }
      $kept[$hash] = [
        'userId' => (int) ($entry['userId'] ?? 0),
        'exp' => (int) $entry['exp'],
      ];
    }

    return $kept;
  }

  /**
   * @param array<string, array{userId: int, exp: int}> $tokens
   */
  private function writeRefresh(array $tokens): void
  {
    update_option(self::REFRESH_OPTION, $tokens, false);
  }
}
