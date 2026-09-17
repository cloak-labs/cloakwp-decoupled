<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

/**
 * Convert absolute WP upload URLs to path-only srcs (e.g. /app/uploads/sites/35/...)
 * when building a frontend that serves synced media from its own origin.
 *
 * Enabled when the REST request includes `?relative_images` (any value other
 * than "false"), or when a theme/plugin forces it via the
 * `cloakwp/image_format/relative_urls` filter.
 *
 * Only rewrites URLs whose path contains "/uploads/" so external/CDN URLs stay
 * absolute. Applies to images, videos, PDFs, and any other upload.
 */
final class RelativeUploadUrl
{
  public static function enabled(): bool
  {
    $fromQuery = isset($_GET['relative_images']) && $_GET['relative_images'] !== 'false';
    return (bool) apply_filters('cloakwp/image_format/relative_urls', $fromQuery);
  }

  public static function maybe(string $url): string
  {
    if (!self::enabled() || $url === '') {
      return $url;
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!$path) {
      return $url;
    }

    if (str_contains($path, '/uploads/')) {
      return $path;
    }

    return $url;
  }

  /**
   * Rewrite an ACF file field value: a URL string, or an array with a `url` key.
   */
  public static function maybeFile(mixed $value): mixed
  {
    if (is_string($value)) {
      return self::maybe($value);
    }

    if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
      $value['url'] = self::maybe($value['url']);
    }

    return $value;
  }

  /**
   * Recursively rewrite upload URLs in nested ACF/block data.
   *
   * Needed when Gutenberg stored already-formatted file arrays (name-keyed
   * objects with `url`) so `acf/format_value/type=file` never runs.
   */
  public static function walk(mixed $value): mixed
  {
    if (is_string($value)) {
      return self::maybe($value);
    }

    if (!is_array($value)) {
      return $value;
    }

    foreach ($value as $key => $child) {
      $value[$key] = self::walk($child);
    }

    return $value;
  }

  /**
   * ACF's file format_value() bails on non-numeric values. Gutenberg sometimes
   * stores a full attachment array; pass the ID through so formatting still runs.
   */
  public static function coerceFileToId(mixed $value): mixed
  {
    if (!is_array($value)) {
      return $value;
    }

    $id = $value['ID'] ?? $value['id'] ?? null;
    if (is_numeric($id)) {
      return (int) $id;
    }

    return $value;
  }
}
