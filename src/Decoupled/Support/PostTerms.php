<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/** Reuse WordPress's primed relationships for the default post-term query. */
final class PostTerms
{
  public static function get(int $postId, string $taxonomy): array|\WP_Error
  {
    // Custom query arguments/filters can change membership or order. Keep their
    // original wp_get_post_terms pipeline instead of substituting cached terms.
    foreach (['all', 'parse_term_query', 'pre_get_terms', 'get_terms_defaults',
      'get_terms_args', 'get_terms_fields', 'get_terms_orderby',
      'terms_clauses', 'terms_pre_query', 'list_terms_exclusions',
      'wp_get_object_terms_args', 'get_object_terms'] as $hook) {
      if (has_filter($hook)) {
        return wp_get_post_terms($postId, $taxonomy);
      }
    }
    foreach (['get_terms' => '_post_format_get_terms',
      'wp_get_object_terms' => '_post_format_wp_get_object_terms'] as $hook => $default) {
      foreach ($GLOBALS['wp_filter'][$hook]->callbacks ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
          if ($callback['function'] !== $default) {
            return wp_get_post_terms($postId, $taxonomy);
          }
        }
      }
    }
    $definition = get_taxonomy($taxonomy);
    if ($taxonomy === 'post_format' || !$definition || !empty($definition->args)) {
      return wp_get_post_terms($postId, $taxonomy);
    }

    $terms = get_object_term_cache($postId, $taxonomy);
    return $terms === false ? wp_get_post_terms($postId, $taxonomy) : $terms;
  }
}
