<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\BlockParser\BlockParser;
use CloakWP\Decoupled\CMS;
use CloakWP\HookModifiers;

final class BlockParserProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
    if ($cms->getBlockParser() === null) {
      $cms->blockParser(new BlockParser());
    }
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    HookModifiers::make(['post_type'])
      ->forFilter('cloakwp/eloquent/posts')
      ->register();
  }
}
