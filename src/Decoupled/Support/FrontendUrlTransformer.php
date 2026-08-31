<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/**
 * Converts absolute URLs that point at a known frontend origin into path-only
 * URLs so Next.js (and similar) can treat them as internal links.
 *
 * External URLs are left unchanged.
 */
final class FrontendUrlTransformer
{
  /**
   * @param callable(): (?object)|object $activeFrontendResolver A callable that
   *        returns the active frontend (with getUrl() and getSettings('deployments')),
   *        or an object exposing getActiveFrontend() (e.g. CMS).
   */
  public function __construct(
    private readonly mixed $activeFrontendResolver,
  ) {
  }

  /**
   * Convert an absolute URL to a path-only URL when it points at one of this
   * project's known frontend origins (active frontend URL + deployments).
   */
  public function makeFrontendUrlRelative(string $url): string
  {
    if ($url === '') {
      return $url;
    }

    $frontend = $this->resolveActiveFrontend();
    if (!$frontend) {
      return $url;
    }

    $bases = [$frontend->getUrl()];
    $deployments = $frontend->getSettings('deployments');
    if (is_array($deployments)) {
      foreach ($deployments as $deploymentUrl) {
        if (is_string($deploymentUrl) && $deploymentUrl !== '') {
          $bases[] = $deploymentUrl;
        }
      }
    }

    /**
     * Allow themes to add more origins to strip (e.g. production URL while
     * WP_ENV is development).
     *
     * @param string[] $bases Absolute frontend base URLs (trailing slash optional).
     */
    $bases = apply_filters('cloakwp/relative_frontend_urls', $bases);

    foreach ($bases as $base) {
      if (!is_string($base) || $base === '') {
        continue;
      }
      $base = rtrim($base, '/');
      if ($base === '') {
        continue;
      }

      if (strcasecmp($url, $base) === 0 || strcasecmp($url, $base . '/') === 0) {
        return '/';
      }

      // Compare origin case-insensitively; keep the original path casing.
      if (stripos($url, $base . '/') === 0) {
        return substr($url, strlen($base)) ?: '/';
      }
    }

    return $url;
  }

  /**
   * Shorter alias used by service providers.
   */
  public function makeRelative(string $url): string
  {
    return $this->makeFrontendUrlRelative($url);
  }

  private function resolveActiveFrontend(): ?object
  {
    $resolver = $this->activeFrontendResolver;

    if (is_object($resolver) && method_exists($resolver, 'getActiveFrontend')) {
      $frontend = $resolver->getActiveFrontend();
      return is_object($frontend) ? $frontend : null;
    }

    if (is_callable($resolver)) {
      $frontend = $resolver();
      return is_object($frontend) ? $frontend : null;
    }

    return null;
  }
}
