<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

use CloakWP\Decoupled\Frontend;

interface FrontendResolver
{
  /** @return list<Frontend> */
  public function getFrontends(): array;

  public function getActiveFrontend(): Frontend;

  public function getFrontend(string $key): ?Frontend;
}
