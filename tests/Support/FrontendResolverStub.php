<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Support;

use CloakWP\Decoupled\Contracts\FrontendResolver;
use CloakWP\Decoupled\Frontend;

final class FrontendResolverStub implements FrontendResolver
{
  /** @param list<Frontend> $frontends */
  public function __construct(
    private readonly array $frontends,
  ) {
  }

  public function getFrontends(): array
  {
    return $this->frontends;
  }

  public function getActiveFrontend(): Frontend
  {
    if ($this->frontends === []) {
      throw new \LogicException('No frontend configured.');
    }

    return $this->frontends[0];
  }

  public function getFrontend(string $key): ?Frontend
  {
    foreach ($this->frontends as $frontend) {
      if ($frontend->getKey() === $key) {
        return $frontend;
      }
    }

    return null;
  }
}
