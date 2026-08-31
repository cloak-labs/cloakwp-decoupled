<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

final class DraftSlugsProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isBackoffice()) {
      return;
    }

    add_action('save_post', [$cms, 'handleSlugsForDrafts'], 10, 3);
  }
}
