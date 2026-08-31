<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

/**
 * Tracks which site-wide fields may be exposed through REST resources.
 */
final class GlobalsExposure
{
  private bool $all = false;

  /** @var list<string> */
  private array $allowed = [];

  /** @param list<string> $names */
  public function allow(array $names): void
  {
    $this->all = false;
    $this->allowed = array_values(array_unique(array_filter(
      $names,
      static fn(mixed $name): bool => is_string($name) && $name !== '',
    )));
  }

  public function allowAll(): void
  {
    $this->all = true;
    $this->allowed = [];
  }

  public function allows(string $name): bool
  {
    return $this->all || in_array($name, $this->allowed, true);
  }

  public function exposesAnything(): bool
  {
    return $this->all || $this->allowed !== [];
  }

  /** @return array<string, mixed> */
  public function filter(array $globals): array
  {
    if ($this->all) {
      return $globals;
    }

    return array_intersect_key($globals, array_flip($this->allowed));
  }
}
