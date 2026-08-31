<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Core\Enqueue\Assets;
use CloakWP\Core\Gutenberg\AllowedBlocks;
use CloakWP\Decoupled\CMS;

final class ConfiguredResourcesProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    $assets = $cms->configuredAssets();
    if ($assets !== []) {
      Assets::enqueue($assets);
    }

    $blocks = $cms->configuredBlocks();
    if ($blocks !== []) {
      $cms->registerConfiguredBlocks();
    }

    $allowedBlocks = $cms->configuredAllowedCoreBlocks();
    if ($allowedBlocks !== null) {
      AllowedBlocks::make($allowedBlocks)->register();
    }
  }
}
