<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Contracts;

interface ImageFormatter
{
  public function format(mixed $imageId): mixed;
}
