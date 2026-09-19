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
    $slug = $this->slugFromRequest($request);
    if ($slug === '' || !$this->exposure->allows($slug)) {
      return new \WP_Error('global_not_exposed', 'Global is not exposed.', ['status' => 403]);
    }

    if (!$this->repository->exists($slug)) {
      return new \WP_Error('global_not_found', 'Global not found.', ['status' => 404]);
    }

    return rest_ensure_response($this->repository->get($slug));
  }

  /**
   * Accept both /globals/{global_slug} and the pre-2.0 /options/{option_slug} alias.
   */
  private function slugFromRequest(mixed $request): string
  {
    if (!is_object($request) || !method_exists($request, 'get_param')) {
      return '';
    }

    foreach (['global_slug', 'option_slug'] as $param) {
      $value = $request->get_param($param);
      if (is_string($value) && $value !== '') {
        return $value;
      }
    }

    return '';
  }
}
