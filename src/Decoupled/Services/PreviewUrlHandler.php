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
    $token = $this->tokens->issue($previewKey, $pathname, $ttl);
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
    $token = $this->tokens->issue(
      'post-' . $postId,
      $pathname,
      (int) $frontend->getSettings('previewTokenTtl'),
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
   * @param array<string, scalar> $query
   */
  private function withQuery(string $url, array $query): string
  {
    return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }
}
