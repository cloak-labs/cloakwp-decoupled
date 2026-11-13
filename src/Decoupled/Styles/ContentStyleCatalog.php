<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Styles;

use CloakWP\Decoupled\CMS;
use Crosswind\WordPress\Catalog;

require_once dirname(__DIR__, 5) . '/crosswind/packages/wordpress/src/Catalog.php';

/**
 * Cloak registration of the Crosswind WordPress catalog.
 * Passes Cloak's block parser so reusable blocks expand the way the frontend sees them.
 */
final class ContentStyleCatalog extends Catalog
{
  private bool $parserReady = false;

  public function register(): self
  {
    if (!$this->parserReady) {
      $this->blockParser(static fn (object $post): array => CMS::getInstance()->getBlockParser()->parseBlocksFromPost($post));
      $this->parserReady = true;
    }
    return parent::register();
  }
}
