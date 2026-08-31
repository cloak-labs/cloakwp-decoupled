<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

interface MenuRepository
{
  /**
   * @return list<array<string, mixed>>
   */
  public function all(): array;

  /**
   * @return array<string, mixed>|null
   */
  public function atLocation(string $location): ?array;

  /**
   * @return array<string, mixed>|null
   */
  public function findBySlug(string $slug): ?array;
}
