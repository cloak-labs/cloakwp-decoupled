<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

use CloakWP\Core\Utils;

/**
 * Resolves image-library items to the portfolio project they belong to.
 *
 * Scatter grouping includes published and draft projects, so a draft shoot
 * does not stay clumped. The public `related` link is only attached for
 * published projects.
 *
 * Cost when `include_project` or scatter-by-project is on:
 * - 1 extra column from the attachments query (`post_parent`) instead of `fields=ids`
 * - A cached reverse index of projects (2 queries on miss: posts + meta cache)
 * Per-image lookups are in-memory after that.
 */
final class ProjectImageLookup
{
  public const CACHE_GROUP = 'cloakwp';

  public const CACHE_TTL = 3600;

  /**
   * @var array<string, array{images: array<int, int>, projects: array<int, array{title: string, subtitle?: string, href: string}>, assignable: array<int, true>}>
   */
  private static array $memoryCache = [];

  private static bool $hooksRegistered = false;

  /**
   * @param list<array<string, mixed>> $items
   * @param array<int, int> $parents imageId => post_parent
   * @return list<array<string, mixed>>
   */
  public function attach(array $items, array $parents): array
  {
    $this->registerHooks();

    if ($items === []) {
      return $items;
    }

    $imageIds = [];
    foreach ($items as $item) {
      $imageId = (int) ($item['id'] ?? 0);
      if ($imageId > 0) {
        $imageIds[] = $imageId;
      }
    }

    $index = $this->index();
    $projectIds = $this->resolveIds($imageIds, $parents, $index, true);

    foreach ($items as &$item) {
      $imageId = (int) ($item['id'] ?? 0);
      $projectId = $projectIds[$imageId] ?? null;
      if ($projectId === null) {
        continue;
      }

      $related = $index['projects'][$projectId] ?? null;
      if (is_array($related) && ($related['title'] ?? '') !== '' && ($related['href'] ?? '') !== '') {
        $item['related'] = $related;
      }
    }
    unset($item);

    return $items;
  }

  /**
   * @param list<int> $imageIds
   * @param array<int, int> $parents imageId => post_parent
   * @return array<int, int> imageId => projectId
   */
  public function projectIds(array $imageIds, array $parents): array
  {
    $this->registerHooks();

    return $this->resolveIds($imageIds, $parents, $this->index());
  }

  /**
   * @param list<int> $imageIds
   * @param array<int, int> $parents
   * @param array{images: array<int, int>, projects: array<int, array{title: string, subtitle?: string, href: string}>, assignable?: array<int, true>} $index
   * @return array<int, int>
   */
  private function resolveIds(array $imageIds, array $parents, array $index, bool $publishedOnly = false): array
  {
    $assignable = [];
    if (!$publishedOnly && isset($index['assignable']) && is_array($index['assignable'])) {
      foreach ($index['assignable'] as $projectId => $ok) {
        if ($ok) {
          $assignable[(int) $projectId] = true;
        }
      }
    }
    if ($assignable === []) {
      foreach (array_keys($index['projects']) as $projectId) {
        $assignable[(int) $projectId] = true;
      }
    }

    $out = [];
    foreach ($imageIds as $imageId) {
      $imageId = (int) $imageId;
      if ($imageId <= 0 || isset($out[$imageId])) {
        continue;
      }

      $projectId = ProjectImageIndex::resolveProjectId(
        $imageId,
        (int) ($parents[$imageId] ?? 0),
        $index['images'],
        $assignable,
      );
      if ($projectId !== null) {
        $out[$imageId] = $projectId;
      }
    }

    return $out;
  }

  public static function flushCache(): void
  {
    self::$memoryCache = [];
    if (function_exists('wp_cache_delete')) {
      wp_cache_delete(self::cacheKey(), self::CACHE_GROUP);
    }
  }

  public static function flushCacheForPost(mixed $postId = null): void
  {
    $id = (int) $postId;
    if ($id <= 0) {
      return;
    }
    $post = function_exists('get_post') ? get_post($id) : null;
    if (!is_object($post)) {
      return;
    }
    $type = (string) ($post->post_type ?? '');
    if ($type !== '' && in_array($type, self::projectPostTypes(), true)) {
      self::flushCache();
    }
  }

  /**
   * @return array{images: array<int, int>, projects: array<int, array{title: string, subtitle?: string, href: string}>}
   */
  private function index(): array
  {
    $key = self::cacheKey();
    if (isset(self::$memoryCache[$key])) {
      return self::$memoryCache[$key];
    }

    if (function_exists('wp_cache_get')) {
      $cached = wp_cache_get($key, self::CACHE_GROUP);
      if (is_array($cached) && isset($cached['images'], $cached['projects'], $cached['assignable'])) {
        self::$memoryCache[$key] = $cached;

        return $cached;
      }
    }

    $built = $this->buildIndex();
    self::$memoryCache[$key] = $built;
    if (function_exists('wp_cache_set')) {
      wp_cache_set($key, $built, self::CACHE_GROUP, self::CACHE_TTL);
    }

    return $built;
  }

  /**
   * @return array{images: array<int, int>, projects: array<int, array{title: string, subtitle?: string, href: string}>, assignable: array<int, true>}
   */
  private function buildIndex(): array
  {
    $projects = $this->projectPosts();
    $summaries = [];
    $projectImageIds = [];
    $assignable = [];

    foreach ($projects as $project) {
      $id = (int) ($project->ID ?? 0);
      if ($id <= 0) {
        continue;
      }

      $assignable[$id] = true;
      $projectImageIds[$id] = [];
      if ((string) ($project->post_status ?? '') !== 'publish') {
        continue;
      }

      $summary = $this->summaryFor($project);
      if ($summary !== null) {
        $summaries[$id] = $summary;
      }
    }

    if ($projectImageIds === []) {
      return ['images' => [], 'projects' => [], 'assignable' => []];
    }

    $projectIds = array_keys($projectImageIds);
    if (function_exists('update_meta_cache')) {
      update_meta_cache('post', $projectIds);
    }

    $metaKeys = self::imageMetaKeys();
    foreach ($projectIds as $projectId) {
      $ids = [];
      foreach ($metaKeys as $metaKey) {
        $value = function_exists('get_post_meta') ? get_post_meta($projectId, $metaKey, true) : null;
        foreach (ProjectImageIndex::idsFromMetaValue($value) as $imageId) {
          $ids[] = $imageId;
        }
      }

      $content = '';
      foreach ($projects as $project) {
        if ((int) ($project->ID ?? 0) === $projectId) {
          $content = (string) ($project->post_content ?? '');
          break;
        }
      }
      if ($content !== '' && function_exists('parse_blocks')) {
        $blocks = parse_blocks($content);
        if (is_array($blocks)) {
          foreach (ProjectImageIndex::idsFromBlocks($blocks) as $imageId) {
            $ids[] = $imageId;
          }
        }
      }

      $projectImageIds[$projectId] = array_values(array_unique($ids));
    }

    return [
      'images' => ProjectImageIndex::imageToProjectMap($projectImageIds),
      'projects' => $summaries,
      'assignable' => $assignable,
    ];
  }

  /**
   * Published projects can be linked. Drafts are included so scatter can
   * separate a shoot that has not been published yet.
   *
   * @return list<object>
   */
  private function projectPosts(): array
  {
    if (!function_exists('get_posts')) {
      return [];
    }

    $posts = get_posts([
      'post_type' => self::projectPostTypes(),
      'post_status' => ['publish', 'draft'],
      'posts_per_page' => -1,
      'no_found_rows' => true,
      'ignore_sticky_posts' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
    ]);

    return is_array($posts) ? array_values($posts) : [];
  }

  /**
   * @return array{title: string, subtitle?: string, href: string}|null
   */
  private function summaryFor(object $project): ?array
  {
    $id = (int) ($project->ID ?? 0);
    $title = function_exists('get_the_title')
      ? (string) get_the_title($id)
      : (string) ($project->post_title ?? '');
    $title = self::plainText($title);
    if ($title === '') {
      return null;
    }

    $href = $this->pathnameFor($id);
    if ($href === '') {
      return null;
    }

    $related = [
      'title' => $title,
      'href' => $href,
    ];

    $subtitle = $this->subtitleFor($id);
    if ($subtitle !== '') {
      $related['subtitle'] = $subtitle;
    }

    return $related;
  }

  private function pathnameFor(int $projectId): string
  {
    if (class_exists(Utils::class) && method_exists(Utils::class, 'getPostPathname')) {
      $pathname = Utils::getPostPathname($projectId);
      if (is_string($pathname) && $pathname !== '') {
        return $pathname;
      }
    }

    if (!function_exists('get_permalink')) {
      return '';
    }

    $permalink = get_permalink($projectId);
    if (!is_string($permalink) || $permalink === '') {
      return '';
    }

    $path = function_exists('wp_parse_url')
      ? wp_parse_url($permalink, PHP_URL_PATH)
      : parse_url($permalink, PHP_URL_PATH);

    return is_string($path) ? $path : '';
  }

  private function subtitleFor(int $projectId): string
  {
    if (!function_exists('get_post_meta')) {
      return '';
    }

    foreach (self::subtitleMetaKeys() as $key) {
      $value = get_post_meta($projectId, $key, true);
      if (is_string($value)) {
        $text = self::plainText($value);
        if ($text !== '') {
          return $text;
        }
      }
    }

    return '';
  }

  private function registerHooks(): void
  {
    if (self::$hooksRegistered || !function_exists('add_action')) {
      return;
    }
    self::$hooksRegistered = true;
    add_action('save_post', [self::class, 'flushCacheForPost']);
    add_action('deleted_post', [self::class, 'flushCacheForPost']);
  }

  /**
   * @return list<string>
   */
  public static function projectPostTypes(): array
  {
    $types = ['project'];
    if (function_exists('apply_filters')) {
      $filtered = apply_filters('cloakwp/image-library/project_post_types', $types);
      if (is_array($filtered) && $filtered !== []) {
        $types = array_values(array_filter(array_map('strval', $filtered)));
      }
    }

    return $types !== [] ? $types : ['project'];
  }

  /**
   * @return list<string>
   */
  public static function imageMetaKeys(): array
  {
    $keys = [
      '_thumbnail_id',
      'project_images',
      'before_images',
      'after_images',
      'featured_images',
      'in_progress_images',
    ];

    if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
      foreach (self::projectPostTypes() as $type) {
        $groups = acf_get_field_groups(['post_type' => $type]);
        if (!is_array($groups)) {
          continue;
        }
        foreach ($groups as $group) {
          $fields = acf_get_fields($group);
          if (!is_array($fields)) {
            continue;
          }
          foreach ($fields as $field) {
            if (!is_array($field)) {
              continue;
            }
            $name = (string) ($field['name'] ?? '');
            $fieldType = (string) ($field['type'] ?? '');
            if ($name !== '' && in_array($fieldType, ['image', 'gallery'], true)) {
              $keys[] = $name;
            }
          }
        }
      }
    }

    if (function_exists('apply_filters')) {
      $filtered = apply_filters('cloakwp/image-library/project_image_meta_keys', $keys);
      if (is_array($filtered) && $filtered !== []) {
        $keys = array_values(array_filter(array_map('strval', $filtered)));
      }
    }

    return array_values(array_unique($keys));
  }

  /**
   * @return list<string>
   */
  public static function subtitleMetaKeys(): array
  {
    $keys = ['subtitle', 'location'];
    if (function_exists('apply_filters')) {
      $filtered = apply_filters('cloakwp/image-library/project_subtitle_keys', $keys);
      if (is_array($filtered) && $filtered !== []) {
        $keys = array_values(array_filter(array_map('strval', $filtered)));
      }
    }

    return $keys !== [] ? $keys : ['subtitle', 'location'];
  }

  private static function cacheKey(): string
  {
    $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

    return 'cloakwp_project_image_index_v2_' . $blogId;
  }

  private static function plainText(string $value): string
  {
    if (function_exists('wp_strip_all_tags')) {
      return trim((string) wp_strip_all_tags($value));
    }

    return trim(strip_tags($value));
  }
}
