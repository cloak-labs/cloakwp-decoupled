<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

use CloakWP\Core\Media\LibraryFilters;
use CloakWP\Decoupled\Contracts\ImageFormatter;

/**
 * Paginated image-attachment query shared by REST and the image-library block.
 *
 * Unattached attachments are included. Non-images are never returned.
 */
final class ImageLibraryQuery
{
  public const DEFAULT_PER_PAGE = 20;

  public const MAX_PER_PAGE = 50;

  public const EXCLUDE_PREFIX = 'not:';

  public const SCATTER_NONE = 'none';

  public const SCATTER_PROJECT = 'project';

  /** @var callable(array<string, mixed>): object */
  private $queryFactory;

  public function __construct(
    private readonly ImageFormatter $formatter,
    ?callable $queryFactory = null,
    private readonly ?ProjectImageLookup $projectLookup = null,
  ) {
    $this->queryFactory = $queryFactory ?? static fn(array $args): object => new \WP_Query($args);
  }

  /**
   * @param array<string, string> $include queryVar => value
   * @param array<string, string> $exclude queryVar => value (with or without not: prefix)
   * @param list<array{taxonomy: string, termId: int}> $priorityTerms earlier terms rank higher
   * @return array{items: list<array<string, mixed>>, total: int, totalPages: int, page: int, perPage: int}
   */
  public function run(
    int $page = 1,
    int $perPage = self::DEFAULT_PER_PAGE,
    array $include = [],
    array $exclude = [],
    bool $includeProject = false,
    string $scatter = self::SCATTER_NONE,
    array $priorityTerms = [],
    int $priorityShare = 0,
  ): array {
    $page = max(1, $page);
    $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    $scatter = $scatter === self::SCATTER_PROJECT ? self::SCATTER_PROJECT : self::SCATTER_NONE;

    if ($scatter === self::SCATTER_PROJECT) {
      return $this->runScattered(
        $page,
        $perPage,
        $include,
        $exclude,
        $includeProject,
        $priorityTerms,
        $priorityShare,
      );
    }

    $query = ($this->queryFactory)($this->buildArgs($page, $perPage, $include, $exclude, $includeProject));
    [$ids, $parents] = $this->idsAndParents($query->posts ?? []);
    $items = $this->formatItems($ids, $parents, $includeProject);

    $total = (int) ($query->found_posts ?? count($items));
    $totalPages = (int) ($query->max_num_pages ?? ($perPage > 0 ? (int) ceil($total / $perPage) : 0));

    return [
      'items' => $items,
      'total' => $total,
      'totalPages' => $totalPages,
      'page' => $page,
      'perPage' => $perPage,
    ];
  }

  /**
   * @param array<string, string> $include
   * @param array<string, string> $exclude
   * @return array<string, mixed>
   */
  public function buildArgs(
    int $page,
    int $perPage,
    array $include,
    array $exclude,
    bool $includeProject = false,
  ): array {
    $args = [
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'post_mime_type' => 'image',
      'posts_per_page' => $perPage,
      'paged' => $page,
      'orderby' => 'date',
      'order' => 'DESC',
      'no_found_rows' => false,
      'ignore_sticky_posts' => true,
    ];
    if (!$includeProject) {
      $args['fields'] = 'ids';
    }

    $args = LibraryFilters::applyValues($args, $include);
    $args = LibraryFilters::applyValues($args, $this->normalizeExclude($exclude));

    return $args;
  }

  /**
   * Load every matching image, scatter by project, then slice the requested page.
   * Pagination has to be applied after the reorder so page 2 continues the same sequence.
   *
   * @param array<string, string> $include
   * @param array<string, string> $exclude
   * @param list<array{taxonomy: string, termId: int}> $priorityTerms
   * @return array{items: list<array<string, mixed>>, total: int, totalPages: int, page: int, perPage: int}
   */
  private function runScattered(
    int $page,
    int $perPage,
    array $include,
    array $exclude,
    bool $includeProject,
    array $priorityTerms = [],
    int $priorityShare = 0,
  ): array {
    $args = $this->buildArgs(1, $perPage, $include, $exclude, true);
    $args['posts_per_page'] = -1;
    $args['nopaging'] = true;
    unset($args['paged'], $args['fields'], $args['order']);
    $args['orderby'] = ['date' => 'DESC', 'ID' => 'DESC'];
    $args['no_found_rows'] = true;
    $args['update_post_meta_cache'] = false;
    $args['update_post_term_cache'] = false;

    $query = ($this->queryFactory)($args);
    [$ids, $parents] = $this->idsAndParents($query->posts ?? []);

    $lookup = $this->projectLookup ?? new ProjectImageLookup();
    $projectIds = $lookup->projectIds($ids, $parents);
    $ranks = $this->ranksFor($ids, $priorityTerms, $priorityShare);
    $entries = [];
    foreach ($ids as $id) {
      $projectId = $projectIds[$id] ?? 0;
      $entry = [
        'id' => $id,
        'group' => $projectId > 0 ? 'project:' . $projectId : 'image:' . $id,
      ];
      if (array_key_exists($id, $ranks)) {
        $entry['priority'] = $ranks[$id];
      }
      $entries[] = $entry;
    }

    $scattered = ImageScatter::reorder(
      $entries,
      ImageScatter::VIEWPORT_WINDOW,
      ImageScatter::MAX_PER_PROJECT_IN_VIEWPORT,
      $priorityShare,
    );
    $orderedIds = [];
    foreach ($scattered as $entry) {
      $orderedIds[] = $entry['id'];
    }

    $total = count($orderedIds);
    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
    $pageIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

    return [
      'items' => $this->formatItems($pageIds, $parents, $includeProject),
      'total' => $total,
      'totalPages' => $totalPages,
      'page' => $page,
      'perPage' => $perPage,
    ];
  }

  /**
   * @param list<mixed> $posts
   * @return array{0: list<int>, 1: array<int, int>}
   */
  private function idsAndParents(array $posts): array
  {
    $ids = [];
    $parents = [];
    foreach ($posts as $post) {
      if (is_object($post)) {
        $id = (int) ($post->ID ?? 0);
        $parents[$id] = (int) ($post->post_parent ?? 0);
      } else {
        $id = (int) $post;
      }
      if ($id > 0) {
        $ids[] = $id;
      }
    }

    return [$ids, $parents];
  }

  /**
   * @param list<int> $ids
   * @param array<int, int> $parents
   * @return list<array<string, mixed>>
   */
  private function formatItems(array $ids, array $parents, bool $includeProject): array
  {
    $items = [];
    foreach ($ids as $id) {
      if ($id <= 0) {
        continue;
      }
      $formatted = $this->formatter->format($id);
      if (!is_array($formatted)) {
        continue;
      }
      $formatted['id'] = $id;
      $items[] = $formatted;
    }

    if ($includeProject && $items !== []) {
      $lookup = $this->projectLookup ?? new ProjectImageLookup();
      $items = $lookup->attach($items, $parents);
    }

    return $items;
  }

  /**
   * @return array{include: array<string, string>, exclude: array<string, string>}
   */
  public static function filtersFromRequest(object $request): array
  {
    $params = method_exists($request, 'get_query_params') ? $request->get_query_params() : [];
    if (!is_array($params)) {
      $params = [];
    }

    $include = [];
    $exclude = [];

    foreach (LibraryFilters::all() as $filter) {
      $queryVar = $filter->getQueryVar();
      $in = self::param($request, $params, $queryVar, $filter->id());
      $not = self::param($request, $params, $queryVar . '_not', $filter->id() . '_not');

      if ($in !== '') {
        $include[$queryVar] = $in;
      }
      if ($not !== '' && $filter->allowsExclude()) {
        $exclude[$queryVar] = $not;
      }
    }

    return ['include' => $include, 'exclude' => $exclude];
  }

  public static function pageFromRequest(object $request, array $params = []): int
  {
    $page = self::param($request, $params, 'page');

    return max(1, (int) ($page !== '' ? $page : 1));
  }

  public static function perPageFromRequest(object $request, array $params = []): int
  {
    $perPage = self::param($request, $params, 'per_page');
    $n = (int) ($perPage !== '' ? $perPage : self::DEFAULT_PER_PAGE);

    return min(self::MAX_PER_PAGE, max(1, $n));
  }

  public static function includeProjectFromRequest(object $request, array $params = []): bool
  {
    $raw = self::param($request, $params, 'include_project');

    return $raw === '1' || strtolower($raw) === 'true' || strtolower($raw) === 'yes';
  }

  public static function scatterFromRequest(object $request, array $params = []): string
  {
    $raw = strtolower(self::param($request, $params, 'scatter'));

    return $raw === self::SCATTER_PROJECT ? self::SCATTER_PROJECT : self::SCATTER_NONE;
  }

  /**
   * Ordered "taxonomy:termId" pairs. Unknown tokens are dropped.
   *
   * @param array<string, mixed> $params
   * @return list<array{taxonomy: string, termId: int}>
   */
  public static function priorityFromRequest(object $request, array $params = []): array
  {
    $raw = self::param($request, $params, 'priority');
    if ($raw === '') {
      return [];
    }

    $terms = [];
    foreach (explode(',', $raw) as $part) {
      $part = strtolower(trim($part));
      if (!preg_match('/^([a-z0-9_-]+):([1-9]\d*)$/', $part, $matches)) {
        continue;
      }
      $terms[] = [
        'taxonomy' => $matches[1],
        'termId' => (int) $matches[2],
      ];
    }

    return $terms;
  }

  /**
   * @param array<string, mixed> $params
   */
  public static function priorityShareFromRequest(object $request, array $params = []): int
  {
    $raw = null;
    if (method_exists($request, 'get_param')) {
      $raw = $request->get_param('priority_share');
    }
    if (($raw === null || $raw === '') && array_key_exists('priority_share', $params)) {
      $raw = $params['priority_share'];
    }
    if ($raw === null || $raw === '') {
      return 50;
    }

    return max(0, min(100, (int) $raw));
  }

  /**
   * @param list<int> $imageIds
   * @param list<array{taxonomy: string, termId: int}> $priorityTerms
   * @return array<int, int>
   */
  private function ranksFor(array $imageIds, array $priorityTerms, int $priorityShare): array
  {
    if ($imageIds === [] || $priorityTerms === [] || $priorityShare <= 0) {
      return [];
    }

    $keyRanks = $this->priorityKeyRanks($priorityTerms);
    $taxonomies = [];
    foreach ($priorityTerms as $term) {
      $taxonomy = (string) ($term['taxonomy'] ?? '');
      if ($taxonomy !== '') {
        $taxonomies[] = $taxonomy;
      }
    }

    return ImageScatter::priorityRanks($this->termsByImage($imageIds, $taxonomies), $keyRanks);
  }

  /**
   * Selected terms and their children share a rank. An earlier row wins
   * when the same term is reached twice.
   *
   * @param list<array{taxonomy: string, termId: int}> $priorityTerms
   * @return array<string, int>
   */
  private function priorityKeyRanks(array $priorityTerms): array
  {
    $ranks = [];
    foreach (array_values($priorityTerms) as $rank => $term) {
      $taxonomy = (string) ($term['taxonomy'] ?? '');
      $termId = (int) ($term['termId'] ?? 0);
      if ($taxonomy === '' || $termId <= 0) {
        continue;
      }
      foreach ($this->termAndChildren($taxonomy, $termId) as $id) {
        $key = $taxonomy . ':' . $id;
        if (!isset($ranks[$key])) {
          $ranks[$key] = $rank;
        }
      }
    }

    return $ranks;
  }

  /**
   * @return list<int>
   */
  private function termAndChildren(string $taxonomy, int $termId): array
  {
    $ids = [$termId];
    if (!function_exists('get_term_children')) {
      return $ids;
    }
    $children = get_term_children($termId, $taxonomy);
    if (function_exists('is_wp_error') && is_wp_error($children)) {
      return $ids;
    }
    if (!is_array($children)) {
      return $ids;
    }
    foreach ($children as $child) {
      $child = (int) $child;
      if ($child > 0) {
        $ids[] = $child;
      }
    }

    return $ids;
  }

  /**
   * @param list<int> $imageIds
   * @param list<string> $taxonomies
   * @return array<int, list<string>>
   */
  private function termsByImage(array $imageIds, array $taxonomies): array
  {
    if ($imageIds === [] || $taxonomies === [] || !function_exists('wp_get_object_terms')) {
      return [];
    }
    $terms = wp_get_object_terms($imageIds, array_values(array_unique($taxonomies)), [
      'fields' => 'all_with_object_id',
    ]);
    if (function_exists('is_wp_error') && is_wp_error($terms)) {
      return [];
    }
    if (!is_array($terms)) {
      return [];
    }

    $out = [];
    foreach ($terms as $term) {
      $imageId = (int) (is_object($term) ? ($term->object_id ?? 0) : ($term['object_id'] ?? 0));
      $taxonomy = (string) (is_object($term) ? ($term->taxonomy ?? '') : ($term['taxonomy'] ?? ''));
      $termId = (int) (is_object($term) ? ($term->term_id ?? 0) : ($term['term_id'] ?? 0));
      if ($imageId <= 0 || $taxonomy === '' || $termId <= 0) {
        continue;
      }
      $out[$imageId][] = $taxonomy . ':' . $termId;
    }

    return $out;
  }

  /**
   * @param array<string, string> $exclude
   * @return array<string, string>
   */
  private function normalizeExclude(array $exclude): array
  {
    $out = [];
    foreach ($exclude as $key => $value) {
      $filter = LibraryFilters::byQueryVar((string) $key);
      if ($filter === null || !$filter->allowsExclude()) {
        continue;
      }
      $value = (string) $value;
      if ($value === '') {
        continue;
      }
      $out[$filter->getQueryVar()] = str_starts_with($value, self::EXCLUDE_PREFIX)
        ? $value
        : self::EXCLUDE_PREFIX . $value;
    }

    return $out;
  }

  /**
   * @param array<string, mixed> $params
   */
  private static function param(object $request, array $params, string ...$keys): string
  {
    foreach ($keys as $key) {
      $raw = null;
      if (method_exists($request, 'get_param')) {
        $raw = $request->get_param($key);
      }
      if ($raw === null && array_key_exists($key, $params)) {
        $raw = $params[$key];
      }
      if (is_array($raw)) {
        $raw = implode(',', array_map('strval', $raw));
      }
      if ($raw === null || $raw === '') {
        continue;
      }
      $value = sanitize_text_field((string) $raw);

      return $value === '0' ? '' : $value;
    }

    return '';
  }
}
