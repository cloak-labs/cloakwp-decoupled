<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Contracts\GlobalsRepository;
use CloakWP\Decoupled\Services\GlobalsExposure;

final class GetGlobal
{
  public function __construct(
    private readonly GlobalsRepository $repository,
    private readonly GlobalsExposure $exposure,
  ) {
  }

  public function __invoke(mixed $request): mixed
  {
    $slug = is_object($request) && method_exists($request, 'get_param')
      ? (string) $request->get_param('global_slug')
      : '';
    if ($slug === '' || !$this->exposure->allows($slug)) {
      return new \WP_Error('global_not_exposed', 'Global is not exposed.', ['status' => 403]);
    }

    if (!$this->repository->exists($slug)) {
      return new \WP_Error('global_not_found', 'Global not found.', ['status' => 404]);
    }

    return rest_ensure_response($this->repository->get($slug));
  }
}
