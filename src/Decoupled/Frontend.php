<?php

declare(strict_types=1);

namespace CloakWP\Decoupled;

use CloakWP\Decoupled\Services\PreviewUrlHandler;
use CloakWP\Decoupled\Services\RevalidationManager;

final class Frontend
{
  protected string $key;
  protected string $url;
  protected array $settings;
  private bool $frozen = false;
  private ?PreviewUrlHandler $previewUrls = null;
  private ?RevalidationManager $revalidation = null;

  private function __construct(string $key, string $url)
  {
    if ($key === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
      throw new \InvalidArgumentException('Frontend key and absolute URL are required.');
    }
    $this->key = $key;
    $this->url = $this->removeWrappingSlashes($url);
    $this->settings = [
      'apiBasePath' => 'api',
      'apiRouterBasePath' => 'cloakwp',
      'blockPreviewPath' => 'preview-block',
      'authSecret' => '',
      'deployments' => [],
      'apiRouteUrl' => null,
      'previewTokenTtl' => 43200,
      'revalidationTimeout' => 5,
      'revalidateEntriesOnSave' => false,
    ];
  }

  public static function make(string $key, string $url): static
  {
    return new static($key, $url);
  }

  /**
   * Given a URL path, this removes the first and last slash (if they exist)
   * Examples:
   *   - "/blog/post-xyz/" => "blog/post-xyz"
   *   - "pathname/page/" => "pathname/page"
   *   - "/pathname/page" => "pathname/page"
   *   - "/pathname/" => "pathname"
   */
  private function removeWrappingSlashes(string $url): string
  {
    if (empty($url))
      return $url;
    if (substr($url, -1) === "/")
      $url = substr($url, 0, -1); // remove trailing slash
    if (substr($url, 0, 1) === "/")
      $url = substr($url, 1); // remove forward slash
    return $url;
  }

  public function apiBasePath(string $path): static
  {
    $this->assertConfigurable();
    $this->settings['apiBasePath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function apiRouterBasePath(string $path): static
  {
    $this->assertConfigurable();
    $this->settings['apiRouterBasePath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function blockPreviewPath(string $path): static
  {
    $this->assertConfigurable();
    $this->settings['blockPreviewPath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function authSecret(string $secret): static
  {
    $this->assertConfigurable();
    $this->settings['authSecret'] = $secret;
    return $this;
  }

  public function deployments(array $urls): static
  {
    $this->assertConfigurable();
    $formattedUrls = [];
    foreach ($urls as $url) {
      if (!is_string($url))
        continue;
      $formattedUrls[] = $this->removeWrappingSlashes($url);
    }

    $this->settings['deployments'] = $formattedUrls;
    return $this;
  }

  /**
   * Revalidates the frontend paths represented by the provided values.
   *
   * @param array $paths A mixed array of path strings, WordPress entry IDs, or WordPress entry objects.
   */
  public function revalidatePaths(array $paths): void
  {
    if ($this->revalidation === null) {
      throw new \LogicException('Frontend revalidation is unavailable before CloakWP Decoupled is registered.');
    }

    $this->revalidation->revalidate($this, $paths);
  }

  /**
   * This method is a simple & quick way to revalidate all entries on save. It assumes that each entry
   * has its own accompanying frontend path (which isn't always true, but it won't break things). If
   * you want to revalidate other paths when a particular post type is updated, you'll have to do that
   * yourself, doing something like:
   *    
   *    ContentType::make('testimonial')
   *      ->afterChange(function ($postId) {
   *        myFrontendInstance->revalidatePaths([$postId, '/testimonials', '/']);
   *      })
   * 
   * This example means that whenever a testimonial is created or updated, we rebuild its individual
   * path, the /testimonials listing path, and the home page (which for
   * example might display recent testimonials).
   */
  public function revalidateEntriesOnSave(): static
  {
    $this->assertConfigurable();
    $this->settings['revalidateEntriesOnSave'] = true;

    return $this;
  }

  public function previewTokenTtl(int $seconds): static
  {
    $this->assertConfigurable();
    if ($seconds < 60) {
      throw new \InvalidArgumentException('Preview token TTL must be at least 60 seconds.');
    }
    $this->settings['previewTokenTtl'] = $seconds;

    return $this;
  }

  public function revalidationTimeout(int $seconds): static
  {
    $this->assertConfigurable();
    if ($seconds < 1 || $seconds > 30) {
      throw new \InvalidArgumentException('Revalidation timeout must be between 1 and 30 seconds.');
    }
    $this->settings['revalidationTimeout'] = $seconds;

    return $this;
  }

  public function getPostPreviewUrl(mixed $post): string
  {
    if ($this->previewUrls === null) {
      throw new \LogicException('Frontend preview URLs are unavailable before CloakWP Decoupled is registered.');
    }

    return $this->previewUrls->forPost($this, $post);
  }

  public function redirectToFrontendPreview(): void
  {
    $url = $this->previewUrls?->forCurrentRequest($this);
    if ($url !== null) {
      wp_safe_redirect($url);
      exit;
    }
  }

  public function apiRouteUrl(callable|string $url): static
  {
    $this->assertConfigurable();
    $this->settings['apiRouteUrl'] = $url;
    return $this;
  }

  public function getApiRouteUrl(): string
  {
    $url = $this->settings['apiRouteUrl'] ?? null;
    if (is_callable($url)) {
      $url = $url();
    }

    return is_string($url) && $url !== '' ? $this->removeWrappingSlashes($url) : $this->url;
  }

  public function getApiRouterUrl(?string $baseUrl = null): string
  {
    $base = $baseUrl ?? $this->getApiRouteUrl();
    return "{$base}/{$this->settings['apiBasePath']}/{$this->settings['apiRouterBasePath']}";
  }

  public function getUrl(): string
  {
    return $this->url;
  }

  public function getKey(): string
  {
    return $this->key;
  }

  public function getSettings(?string $setting = null): mixed
  {
    if ($setting) {
      if (isset($this->settings[$setting]))
        return $this->settings[$setting];
      return null;
    }

    return $this->settings;
  }

  /**
   * @internal Bound once by the CMS composition root.
   */
  public function bindServices(PreviewUrlHandler $previewUrls, RevalidationManager $revalidation): void
  {
    $this->previewUrls = $previewUrls;
    $this->revalidation = $revalidation;
  }

  /**
   * @internal Configuration is frozen when CMS boot begins.
   */
  public function freeze(): void
  {
    $this->frozen = true;
  }

  private function assertConfigurable(): void
  {
    if ($this->frozen) {
      throw new \LogicException("Frontend '{$this->key}' configuration is frozen after boot.");
    }
  }
}
