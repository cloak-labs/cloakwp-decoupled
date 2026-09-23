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
   * @return array{items: list<array<string, mixed>>, total: int, totalPages: int, page: int, perPage: int}
   */
  public function run(
    int $page = 1,
    int $perPage = self::DEFAULT_PER_PAGE,
    array $include = [],
    array $exclude = [],
    bool $includeProject = false,
    string $scatter = self::SCATTER_NONE,
  ): array {
    $page = max(1, $page);
    $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    $scatter = $scatter === self::SCATTER_PROJECT ? self::SCATTER_PROJECT : self::SCATTER_NONE;

    if ($scatter === self::SCATTER_PROJECT) {
      return $this->runScattered($page, $perPage, $include, $exclude, $includeProject);
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
   * @return array{items: list<array<string, mixed>>, total: int, totalPages: int, page: int, perPage: int}
   */
  private function runScattered(
    int $page,
    int $perPage,
    array $include,
    array $exclude,
    bool $includeProject,
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
    $entries = [];
    foreach ($ids as $id) {
      $projectId = $projectIds[$id] ?? 0;
      $entries[] = [
        'id' => $id,
        'group' => $projectId > 0 ? 'project:' . $projectId : 'image:' . $id,
      ];
    }

    $scattered = ImageScatter::reorder($entries);
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
