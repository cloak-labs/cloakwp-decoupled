<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Support;

use CloakWP\Decoupled\Contracts\SessionStore;

final class InMemorySessionStore implements SessionStore
{
  /** @var array<string, array{userId: int, exp: int}> */
  public array $refresh = [];

  /** @var array<string, array{userId: int, purpose: string, exp: int}> */
  public array $handshakes = [];

  public function __construct(private readonly \Closure $clock)
  {
  }

  public function saveRefreshToken(int $userId, string $tokenHash, int $expiresAt): void
  {
    $this->refresh[$tokenHash] = [
      'userId' => $userId,
      'exp' => $expiresAt,
    ];
  }

  public function consumeRefreshToken(string $tokenHash): ?int
  {
    $entry = $this->refresh[$tokenHash] ?? null;
    unset($this->refresh[$tokenHash]);
    if ($entry === null || $entry['exp'] < ($this->clock)()) {
      return null;
    }

    return $entry['userId'];
  }

  public function revokeRefreshToken(string $tokenHash): void
  {
    unset($this->refresh[$tokenHash]);
  }

  public function revokeAllRefreshTokens(int $userId): void
  {
    foreach ($this->refresh as $hash => $entry) {
      if ($entry['userId'] === $userId) {
        unset($this->refresh[$hash]);
      }
    }
  }

  public function saveHandshakeCode(string $codeHash, int $userId, string $purpose, int $expiresAt): void
  {
    $this->handshakes[$codeHash] = [
      'userId' => $userId,
      'purpose' => $purpose,
      'exp' => $expiresAt,
    ];
  }

  public function consumeHandshakeCode(string $codeHash): ?array
  {
    $entry = $this->handshakes[$codeHash] ?? null;
    unset($this->handshakes[$codeHash]);
    if ($entry === null || $entry['exp'] < ($this->clock)()) {
      return null;
    }

    return [
      'userId' => $entry['userId'],
      'purpose' => $entry['purpose'],
    ];
  }
}
