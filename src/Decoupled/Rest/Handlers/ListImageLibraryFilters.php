<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Core\Media\LibraryFilters;

final class ListImageLibraryFilters
{
  public function __invoke(mixed $request = null): mixed
  {
    return rest_ensure_response(LibraryFilters::publicSchema());
  }
}
