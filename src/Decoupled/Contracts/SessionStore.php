<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

interface SessionStore
{
  public function saveRefreshToken(int $userId, string $tokenHash, int $expiresAt): void;

  /**
   * Atomically look up and delete a refresh token.
   */
  public function consumeRefreshToken(string $tokenHash): ?int;

  public function revokeRefreshToken(string $tokenHash): void;

  public function revokeAllRefreshTokens(int $userId): void;

  public function saveHandshakeCode(string $codeHash, int $userId, string $purpose, int $expiresAt): void;

  /**
   * @return array{userId: int, purpose: string}|null
   */
  public function consumeHandshakeCode(string $codeHash): ?array;
}
