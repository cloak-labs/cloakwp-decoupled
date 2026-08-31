<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Rest\Handlers;

use CloakWP\Decoupled\Contracts\MenuRepository;

final class GetMenu
{
  public function __construct(
    private readonly MenuRepository $menus,
  ) {
  }

  public function __invoke(mixed $request): mixed
  {
    $slug = is_object($request) && method_exists($request, 'get_param')
      ? (string) $request->get_param('menu_slug')
      : '';
    $menu = $slug === '' ? null : $this->menus->findBySlug($slug);
    if ($menu === null) {
      return new \WP_Error('menu_not_found', 'Menu not found.', ['status' => 404]);
    }

    return rest_ensure_response($menu);
  }
}
