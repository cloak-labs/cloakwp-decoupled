<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\Acf;

/**
 * Ensures ACF relational fields respect virtual-field filters.
 */
final class AcfPostFiltersProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore() || !Acf::isActive()) {
      return;
    }

    add_filter('acf/acf_get_posts/args', function ($args) {
      return wp_parse_args($args, ['suppress_filters' => false]);
    }, 10, 1);
  }
}
