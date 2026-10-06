<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\IndexedAcfFieldStore;

/** Index native field-parent lookups throughout a REST request. */
final class AcfPerformanceProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if ($cms->context()->isRest()) self::indexFields();
  }

  /** @internal */
  public static function indexFields(): void
  {
    $store = $GLOBALS['acf_stores']['local-fields'] ?? null;
    if (!class_exists('ACF_Data', false) || !is_object($store)
      || get_class($store) !== 'ACF_Data'
      || !apply_filters('cloakwp/acf/index_fields', true)
    ) return;
    $GLOBALS['acf_stores']['local-fields'] = new IndexedAcfFieldStore($store);
  }
}
