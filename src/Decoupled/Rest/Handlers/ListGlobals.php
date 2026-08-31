<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Contracts\GlobalsRepository;
use CloakWP\Decoupled\Services\GlobalsExposure;

final class ListGlobals
{
  public function __construct(
    private readonly GlobalsRepository $repository,
    private readonly GlobalsExposure $exposure,
  ) {
  }

  public function __invoke(): mixed
  {
    if (!$this->exposure->exposesAnything()) {
      return new \WP_Error(
        'globals_not_exposed',
        'No globals have been explicitly exposed through CloakWP Decoupled.',
        ['status' => 403],
      );
    }

    $globals = $this->exposure->filter($this->repository->all());
    if ($globals === []) {
      return new \WP_Error('globals_not_found', 'Zero exposed globals exist.', ['status' => 404]);
    }

    return rest_ensure_response($globals);
  }
}
