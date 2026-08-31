<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

interface ServiceProvider
{
  /**
   * Compose services after theme configuration, without attaching hooks.
   */
  public function register(CMS $cms): void;

  /**
   * Attach WordPress hooks after every provider has registered.
   */
  public function boot(CMS $cms): void;
}
