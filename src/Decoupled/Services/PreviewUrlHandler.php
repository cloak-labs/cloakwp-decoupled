<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

use CloakWP\Core\Utils;
use CloakWP\Decoupled\Frontend;

final class PreviewUrlHandler
{
  public function __construct(
    private readonly PreviewToken $tokens,
  ) {
  }

  public function forBlock(Frontend $frontend, string $previewKey, string $pathname): string
  {
    $ttl = (int) $frontend->getSettings('previewTokenTtl');
    $token = $this->tokens->issue($previewKey, $pathname, $ttl, $this->wpOrigin());
    $path = (string) $frontend->getSettings('blockPreviewPath');

    return $this->withQuery(
      rtrim($frontend->getUrl(), '/') . '/' . ltrim($path, '/'),
      [
        'token' => $token,
      ],
    );
  }

  public function forPost(Frontend $frontend, mixed $post): string
  {
    $postId = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
    $revisionId = 0;
    if (is_object($post) && (int) ($post->post_parent ?? 0) > 0) {
      $revisionId = $postId;
      $postId = (int) $post->post_parent;
    }

    if ($postId <= 0) {
      throw new \InvalidArgumentException('A valid post is required to build a frontend preview URL.');
    }

    $pathname = Utils::getPostPathname($postId);
    if (!is_string($pathname) || $pathname === '' || !str_starts_with($pathname, '/')) {
      $pathname = '/';
    }
    $token = $this->tokens->issue(
      'post-' . $postId,
      $pathname,
      (int) $frontend->getSettings('previewTokenTtl'),
      $this->wpOrigin(),
    );

    return $this->withQuery(
      $frontend->getApiRouterUrl() . '/preview',
      [
        'revisionId' => $revisionId ?: '',
        'postId' => $postId,
        'postType' => get_post_type($postId),
        'pathname' => $pathname,
        'token' => $token,
      ],
    );
  }

  public function forCurrentRequest(Frontend $frontend): ?string
  {
    if (empty($_GET['preview'])) {
      return null;
    }

    $postId = (int) ($_GET['p'] ?? $_GET['preview_id'] ?? 0);
    if ($postId <= 0) {
      return null;
    }

    return $this->forPost($frontend, get_post($postId) ?? $postId);
  }

  /**
   * Origin of the WP admin that is embedding the preview iframe.
   *
   * Use the current request host, not `home_url()`: local wp-admin against a
   * staging DB still lives on wp.localhost while `home` is the staging URL.
   */
  private function wpOrigin(): ?string
  {
    $fromRequest = $this->requestOrigin();
    if ($fromRequest !== null) {
      return $fromRequest;
    }

    if (!function_exists('home_url') || !function_exists('wp_parse_url')) {
      return null;
    }

    $parts = wp_parse_url(home_url('/'));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return null;
    }

    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }

    return $origin;
  }

  private function requestOrigin(): ?string
  {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host) || $host === '') {
      return null;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $scheme = 'http';
    if ((function_exists('is_ssl') && is_ssl()) || $forwarded === 'https') {
      $scheme = 'https';
    }

    return $scheme . '://' . $host;
  }

  /**
   * @param array<string, scalar> $query
   */
  private function withQuery(string $url, array $query): string
  {
    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }
}
