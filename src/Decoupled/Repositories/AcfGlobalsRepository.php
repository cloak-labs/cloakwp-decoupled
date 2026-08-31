<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Repositories;

use CloakWP\Decoupled\Contracts\GlobalsRepository;
use CloakWP\Decoupled\Support\Acf;

/**
 * Reads site-wide values from ACF Options pages when ACF is installed.
 * ACF's internal store name remains `options`; that is not part of CloakWP's API.
 */
final class AcfGlobalsRepository implements GlobalsRepository
{
  public function all(): array
  {
    if (!Acf::isActive() || !function_exists('get_fields')) {
      return [];
    }

    $fields = get_fields('options');

    return is_array($fields) ? $fields : [];
  }

  public function exists(string $slug): bool
  {
    if (!Acf::isActive() || !function_exists('get_field_object')) {
      return false;
    }

    return is_array(get_field_object($slug, 'options'));
  }

  public function get(string $slug): mixed
  {
    if (!Acf::isActive() || !function_exists('get_field')) {
      return null;
    }

    return get_field($slug, 'options');
  }
}
