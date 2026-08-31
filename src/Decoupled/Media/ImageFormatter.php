<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

use CloakWP\Decoupled\Contracts\ImageFormatter as ImageFormatterContract;

/**
 * Formats WordPress attachment IDs (or URLs) into a size map consumable by
 * decoupled frontends — URL, width, height per size, plus alt/caption/title.
 *
 * Pass `?relative_images=1` on REST requests (or filter
 * `cloakwp/image_format/relative_urls`) to get path-only upload srcs instead
 * of absolute URLs — useful when syncing WP uploads into a Next.js public/
 * folder.
 */
final class ImageFormatter implements ImageFormatterContract
{
  /**
   * Contract alias for formatImage().
   */
  public function format(mixed $imageId): mixed
  {
    return $this->formatImage($imageId);
  }

  /**
   * Whether formatImage() should emit path-only srcs (e.g. /app/uploads/sites/36/...)
   * instead of absolute URLs.
   *
   * Enabled when the REST request includes `?relative_images` (any value other
   * than "false"), or when a theme/plugin forces it via the
   * `cloakwp/image_format/relative_urls` filter.
   */
  protected function shouldUseRelativeImageUrls(): bool
  {
    $fromQuery = isset($_GET['relative_images']) && $_GET['relative_images'] !== 'false';
    return (bool) apply_filters('cloakwp/image_format/relative_urls', $fromQuery);
  }

  /**
   * Optionally convert an absolute image URL to its path component for
   * local-media builds. Only rewrites URLs whose path contains "/uploads/" so
   * external/CDN URLs stay absolute.
   */
  protected function maybeRelativeImageUrl(string $url): string
  {
    if (!$this->shouldUseRelativeImageUrls()) {
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
   * By default, WordPress exposes images via the REST API as image IDs, which
   * is not very useful for decoupled frontends — it requires making a
   * separate/additional REST API request for each image to get its URL, size,
   * alt text, etc. This method is the source-of-truth for formatting all image
   * data for the REST API.
   */
  public function formatImage(mixed $imageId): mixed
  {
    if (!$imageId) {
      return $imageId;
    }
    if (is_array($imageId)) {
      return $imageId;
    }

    // Handle case where $imageId is actually an image URL
    if (is_string($imageId) && filter_var($imageId, FILTER_VALIDATE_URL)) {
      // Convert URL to post ID
      $found_id = attachment_url_to_postid($imageId);
      if ($found_id) {
        $imageId = $found_id;
      } else {
        // Width and height are intentionally omitted for unknown external URLs.
        // Discovering them would require fetching an attacker-controlled URL
        // during a REST request, creating an SSRF risk.
        return [
          'full' => [
            'src' => $this->maybeRelativeImageUrl($imageId),
          ],
        ];
      }
    }

    $imageId = intval($imageId); // coerces strings into integers if they start with numeric data

    $result = [];

    // IMPORTANT: the array of sizes must be ordered from smallest to largest
    // in order for exclusion logic further below to work properly:
    $sizes = apply_filters('cloakwp/image_format/sizes', ['medium', 'large', 'full'], $imageId);

    foreach ($sizes as $size) {
      $img = wp_get_attachment_image_src($imageId, $size);
      if (is_array($img)) {
        $url = $img[0]; // Image URL
        $width = $img[1]; // Width of the image
        $height = $img[2]; // Height of the image

        // Include URL, width, and height in the result
        $result[$size] = [
          'src' => $this->maybeRelativeImageUrl($url),
          'width' => $width,
          'height' => $height,
        ];
      } else {
        // Handle cases where the image size does not exist
        $result[$size] = false;
      }
    }

    // Now we remove larger sizes if they have the same width as a previous size
    // (i.e. the original uploaded image was small, so it's unnecessary to include
    // larger versions if the size doesn't actually change):
    $previousWidth = null;
    $keepSizes = [];

    foreach ($sizes as $size) {
      if (isset($result[$size]) && $result[$size]) {
        if ($previousWidth === null) {
          $previousWidth = $result[$size]['width'];
          $keepSizes[] = $size;
        } else {
          if ($result[$size]['width'] === $previousWidth) {
            // Stop processing further sizes
            break;
          } else {
            $previousWidth = $result[$size]['width'];
            $keepSizes[] = $size;
          }
        }
      }
    }

    // Keep only the sizes that passed the width check
    $filteredResult = [];
    foreach ($keepSizes as $size) {
      $filteredResult[$size] = $result[$size];
    }

    if ($alt = get_post_meta($imageId, '_wp_attachment_image_alt', true)) {
      $filteredResult['alt'] = $alt;
    }

    // Get caption safely without modifying global post object
    $post = get_post($imageId);
    if ($post) {
      if ($caption = $post->post_excerpt) {
        $filteredResult['caption'] = $caption;
      }
      if ($title = $post->post_title) {
        $filteredResult['title'] = $title;
      }
    }

    return $filteredResult;
  }
}
