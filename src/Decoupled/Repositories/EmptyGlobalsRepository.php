<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Repositories;

use CloakWP\Decoupled\Contracts\GlobalsRepository;

/**
 * Default globals source: nothing is stored until the application supplies
 * a repository (for example {@see AcfGlobalsRepository} when using ACF).
 */
final class EmptyGlobalsRepository implements GlobalsRepository
{
  public function all(): array
  {
    return [];
  }

  public function exists(string $slug): bool
  {
    return false;
  }

  public function get(string $slug): mixed
  {
    return null;
  }
}
