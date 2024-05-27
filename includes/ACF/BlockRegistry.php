<?php

namespace CloakWP\ACF;

use CloakWP\Utils;

/**
 * A singleton that stores and makes accessible all `CloakWP\ACF\Block` instances throughout the project
 */
class BlockRegistry
{
  /** Stores the BlockRegistry Singleton instance. */
  private static $instance;

  /** Stores all CloakWP\ACF\Block instances. */
  protected array $blocks = [];

  private function __construct()
  {
  }

  /** Returns the BlockRegistry Singleton instance. */
  public static function getInstance(): static
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  public function addBlock(Block $block): static
  {
    // By using deep_copy here, we're saving the *original* Block class instances in the BlockRegistry, therefore any subsequent changes to those Class instances won't affect the versions saved in the BlockRegistry
    $this->blocks[] = Utils::deep_copy($block);

    return $this;
  }

  /**
   * Returns an array of all registered Block instances.
   * 
   * @return Block[] Array of Block instances.
   */
  public function getBlocks(): array
  {
    return Utils::deep_copy($this->blocks);
  }
}