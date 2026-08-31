<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/**
 * Detects whether Advanced Custom Fields is available. CloakWP Decoupled
 * never requires ACF; integrations that use it must no-op when it is absent.
 */
final class Acf
{
  public static function isActive(): bool
  {
    return function_exists('get_field');
  }
}
