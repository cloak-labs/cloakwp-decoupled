<?php

declare(strict_types=1);

namespace CloakWP\Decoupled;

/**
 * Registry of configured DecoupledFrontend instances.
 */
final class FrontendRegistry
{
  /** @var list<Frontend> */
  private array $frontends = [];

  /**
   * @param list<Frontend> $frontends
   */
  public function set(array $frontends): void
  {
    foreach ($frontends as $frontend) {
      if (!$frontend instanceof Frontend) {
        throw new \InvalidArgumentException('Every configured frontend must be a ' . Frontend::class . ' instance.');
      }
    }

    $keys = array_map(static fn(Frontend $frontend): string => $frontend->getKey(), $frontends);
    if (count($keys) !== count(array_unique($keys))) {
      throw new \InvalidArgumentException('Frontend keys must be unique.');
    }

    $this->frontends = array_values($frontends);
  }

  /**
   * @return list<Frontend>
   */
  public function all(): array
  {
    return $this->frontends;
  }

  public function get(string $key): ?Frontend
  {
    foreach ($this->frontends as $frontend) {
      if ($frontend->getKey() === $key) {
        return $frontend;
      }
    }

    return null;
  }

  public function active(): Frontend
  {
    if (empty($this->frontends)) {
      throw new \LogicException(
        'CloakWP Decoupled requires at least one frontend. Configure CMS::frontends() before after_setup_theme priority 20.',
      );
    }

    return $this->frontends[0];
  }

  public function validate(): void
  {
    $this->active();
  }
}
