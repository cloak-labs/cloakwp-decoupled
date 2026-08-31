<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Contracts\MenuRepository;

final class ListMenus
{
  public function __construct(
    private readonly MenuRepository $menus,
  ) {
  }

  public function __invoke(mixed $request): mixed
  {
    $location = is_object($request) && method_exists($request, 'get_param')
      ? $request->get_param('location')
      : null;
    if (is_string($location) && $location !== '') {
      $menu = $this->menus->atLocation($location);
      if ($menu === null) {
        return new \WP_Error(
          'menus_not_found',
          "No menus are assigned to the location, '{$location}'.",
          ['status' => 404],
        );
      }

      return rest_ensure_response($menu);
    }

    $menus = $this->menus->all();
    if ($menus === []) {
      return new \WP_Error('menus_not_found', 'No menus exist.', ['status' => 404]);
    }

    return rest_ensure_response($menus);
  }
}
