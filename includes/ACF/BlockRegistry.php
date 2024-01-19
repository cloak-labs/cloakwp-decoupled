<?php

namespace CloakWP\ACF;

/**
 * A singleton that stores and makes accessible all Stores all `CloakWP\ACF\Block` instances throughout the project
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
    $this->blocks[] = $block;

    return $this;
  }

  /**
   * Returns an array of all registered Block instances.
   * 
   * @return Block[] Array of Block instances.
   */
  public function getBlocks(): array
  {
    return $this->blocks;
  }
}