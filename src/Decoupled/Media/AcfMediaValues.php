<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

use CloakWP\Decoupled\Providers\AcfPostFiltersProvider;

/** Avoid building ACF media arrays that the CloakWP formatter immediately discards. */
final class AcfMediaValues
{
  public static function wrap(string $type, \Closure $formatter, int $priority): void
  {
    $native = acf_get_field_type($type);
    if (!$native || get_class($native) !== 'acf_field_' . $type) {
      return;
    }
    $hook = 'acf/format_value/type=' . $type;
    $original = [$native, 'format_value'];
    // Removing and re-adding a callback changes its order within a priority.
    // Leave a shared priority untouched so custom callbacks see the same values.
    if (has_filter($hook, $original) !== 10 || count($GLOBALS['wp_filter'][$hook]->callbacks[10] ?? []) !== 1) {
      return;
    }

    $wrapper = null;
    $wrapper = static function ($value, $postId, $field) use ($original, $type, $hook, $formatter, $priority, &$wrapper) {
      if (!defined('REST_REQUEST') || !REST_REQUEST || ($field['return_format'] ?? '') !== 'array'
        || !self::hasDefaultPipeline($hook, $wrapper, $formatter, $priority)
        || has_filter('acf/pre_load_attachment') || has_filter('acf/load_attachment') || has_filter('all')
        || !apply_filters('cloakwp/acf_media/optimize', true, $field, $postId)
      ) {
        return $original($value, $postId, $field);
      }

      if ($type === 'image') {
        $post = is_numeric($value) ? get_post((int) $value) : null;
        if (!$post || $post->post_type !== 'attachment') {
          return $original($value, $postId, $field);
        }
        return ['ID' => $original($value, $postId, array_replace($field, ['return_format' => 'id']))];
      }

      // A gallery's attachment posts are only used to validate/order its IDs.
      // Additional ACF query callbacks may consume enriched posts, so keep their
      // original path. Other WordPress query filters continue running normally.
      if (!self::hasDefaultQueryPipeline()) {
        return $original($value, $postId, $field);
      }
      $queryFilter = static function ($args) {
        if (($args['post_type'] ?? null) === 'attachment') {
          $args['cloakwp_virtual_fields'] = 'discard';
        }
        return $args;
      };
      add_filter('acf/acf_get_posts/args', $queryFilter, PHP_INT_MAX);
      try {
        $ids = $original($value, $postId, array_replace($field, ['return_format' => 'id']));
      } finally {
        remove_filter('acf/acf_get_posts/args', $queryFilter, PHP_INT_MAX);
      }
      return is_array($ids) ? array_map(static fn($id) => ['ID' => $id], $ids) : $ids;
    };
    remove_filter($hook, $original, 10);
    add_filter($hook, $wrapper, 10, 3);
  }

  private static function hasDefaultQueryPipeline(): bool
  {
    if (has_filter('acf/acf_get_posts/results')) {
      return false;
    }
    foreach ($GLOBALS['wp_filter']['acf/acf_get_posts/args']->callbacks ?? [] as $callbacks) {
      foreach ($callbacks as $entry) {
        if ($entry['function'] !== [AcfPostFiltersProvider::class, 'queryArgs']) {
          return false;
        }
      }
    }
    if (!function_exists('register_virtual_fields')) {
      return !has_filter('the_posts');
    }
    $registrationFile = (new \ReflectionFunction('register_virtual_fields'))->getFileName();
    foreach ($GLOBALS['wp_filter']['the_posts']->callbacks ?? [] as $callbacks) {
      foreach ($callbacks as $entry) {
        $callback = $entry['function'];
        if ($callback === '_close_comments_for_old_posts') {
          continue;
        }
        if (!$callback instanceof \Closure || (new \ReflectionFunction($callback))->getFileName() !== $registrationFile) {
          return false;
        }
      }
    }
    return true;
  }

  private static function hasDefaultPipeline(string $hook, \Closure $wrapper, \Closure $formatter, int $priority): bool
  {
    $callbacks = $GLOBALS['wp_filter'][$hook]->callbacks ?? [];
    if (count($callbacks) !== 2 || count($callbacks[10] ?? []) !== 1 || count($callbacks[$priority] ?? []) !== 1) {
      return false;
    }
    return reset($callbacks[10])['function'] === $wrapper
      && reset($callbacks[$priority])['function'] === $formatter;
  }
}
