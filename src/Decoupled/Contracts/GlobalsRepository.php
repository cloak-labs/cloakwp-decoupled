<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

/**
 * Source of site-wide values exposed at /cloakwp/globals.
 *
 * Globals are content and settings every page may need (company details,
 * default layout, social links). They are not WordPress `get_option()`
 * rows and not ACF-specific; replace the default repository when the
 * project stores this data somewhere else.
 */
interface GlobalsRepository
{
  /**
   * @return array<string, mixed>
   */
  public function all(): array;

  public function exists(string $slug): bool;

  public function get(string $slug): mixed;
}
