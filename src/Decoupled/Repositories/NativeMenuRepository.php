<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Repositories;

use CloakWP\Decoupled\Contracts\MenuRepository;

final class NativeMenuRepository implements MenuRepository
{
  public function all(): array
  {
    $menus = wp_get_nav_menus();
    if (!is_array($menus)) {
      return [];
    }

    return array_values(array_map(
      fn(object $menu): array => $this->formatMenu($menu),
      $menus,
    ));
  }

  public function atLocation(string $location): ?array
  {
    $locations = get_nav_menu_locations();
    $menuId = is_array($locations) ? (int) ($locations[$location] ?? 0) : 0;
    if ($menuId <= 0) {
      return null;
    }

    $menu = wp_get_nav_menu_object($menuId);

    return is_object($menu) ? $this->formatMenu($menu) : null;
  }

  public function findBySlug(string $slug): ?array
  {
    $menu = wp_get_nav_menu_object($slug);

    return is_object($menu) ? $this->formatMenu($menu) : null;
  }

  /**
   * @return array<string, mixed>
   */
  private function formatMenu(object $menu): array
  {
    $menuId = (int) ($menu->term_id ?? 0);
    $locations = [];
    foreach (get_nav_menu_locations() as $location => $assignedMenuId) {
      if ((int) $assignedMenuId === $menuId) {
        $locations[] = $location;
      }
    }

    $items = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);
    $items = is_array($items) ? $items : [];

    return [
      'term_id' => $menuId,
      'name' => (string) ($menu->name ?? ''),
      'slug' => (string) ($menu->slug ?? ''),
      'term_group' => (int) ($menu->term_group ?? 0),
      'term_taxonomy_id' => (int) ($menu->term_taxonomy_id ?? $menuId),
      'taxonomy' => 'nav_menu',
      'count' => (int) ($menu->count ?? count($items)),
      'locations' => $locations,
      'menu_items' => $this->buildTree($items),
    ];
  }

  /**
   * @param list<object> $items
   * @return list<array<string, mixed>>
   */
  private function buildTree(array $items, int $parentId = 0): array
  {
    $branch = [];
    foreach ($items as $item) {
      $item = wp_setup_nav_menu_item($item);
      if ((int) ($item->menu_item_parent ?? 0) !== $parentId) {
        continue;
      }

      $meta = $this->formatItem($item);
      $meta['sub_menu_items'] = $this->buildTree($items, (int) $item->ID);
      $branch[] = $meta;
    }

    usort(
      $branch,
      static fn(array $left, array $right): int => ((int) $left['menu_order']) <=> ((int) $right['menu_order']),
    );

    return $branch;
  }

  /**
   * @return array<string, mixed>
   */
  private function formatItem(object $item): array
  {
    $meta = [];
    $rawMeta = get_post_meta((int) $item->ID);
    if (is_array($rawMeta)) {
      foreach ($rawMeta as $key => $values) {
        $name = str_replace('_menu_item_', '', (string) $key);
        $value = is_array($values) ? ($values[0] ?? '') : $values;
        $meta[$name] = maybe_unserialize($value);
      }
    }

    $meta['object_id'] = (string) ($item->object_id ?? $meta['object_id'] ?? '');
    $meta['object'] = (string) ($item->object ?? $meta['object'] ?? '');
    $meta['menu_item_parent'] = (string) ($item->menu_item_parent ?? 0);
    $meta['url'] = (string) ($item->url ?? $meta['url'] ?? '#');
    $meta['title'] = (string) ($item->title ?? $meta['title'] ?? '');
    $meta['target'] = (string) ($item->target ?? $meta['target'] ?? '');
    $meta['attr_title'] = (string) ($item->attr_title ?? $meta['attr_title'] ?? '');
    $meta['description'] = (string) ($item->description ?? $meta['description'] ?? '');
    $classes = $item->classes ?? $meta['classes'] ?? [];
    $meta['classes'] = is_array($classes) ? implode(' ', array_filter($classes)) : (string) $classes;
    $meta['id'] = (int) $item->ID;
    $meta['menu_order'] = (int) ($item->menu_order ?? 0);
    $meta['link_type'] = (string) ($item->type ?? $meta['type'] ?? '');

    unset($meta['xfn'], $meta['_wp_old_date'], $meta['type']);

    return apply_filters('cloakwp/decoupled/menu_item/formatted_meta', $meta, $item);
  }
}
