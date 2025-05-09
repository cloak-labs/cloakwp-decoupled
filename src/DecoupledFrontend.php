<?php

namespace CloakWP;

use CloakWP\Core\Utils;
use Exception;
use WP_Post;

class DecoupledFrontend
{
  protected string $key;
  protected string $url;
  protected array $settings;

  private function __construct(string $key, string $url)
  {
    $this->key = $key;
    $this->url = $this->removeWrappingSlashes($url);
    $this->settings = [
      'apiBasePath' => 'api',
      'apiRouterBasePath' => 'cloakwp',
      'blockPreviewPath' => 'preview-block',
      'authSecret' => '',
      'deployments' => [],
      'apiRouteUrl' => null,
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
  private function removeWrappingSlashes(string $url)
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
    $this->settings['apiBasePath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function apiRouterBasePath(string $path): static
  {
    $this->settings['apiRouterBasePath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function blockPreviewPath(string $path): static
  {
    $this->settings['blockPreviewPath'] = $this->removeWrappingSlashes($path);
    return $this;
  }

  public function authSecret(string $secret): static
  {
    $this->settings['authSecret'] = $secret;
    return $this;
  }

  public function deployments(array $urls): static
  {
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
   * Revalidates/rebuilds the pages corresponding to the provided $paths
   * 
   * @param array $paths - Can be a mixed array containing either: (1) a string representation of the frontend path (eg. "/reviews"), (2) a WP post ID (eg. 42), or (3) a WP post object
   */
  public function revalidatePages(array $paths)
  {
    // only allow revalidation in non-development environments and when not in maintenance mode
    if (\WP_ENV != "development" && !DecoupledCMS::$isMaintenanceMode) {
      foreach ($paths as $path) {
        if (is_object($path)) { // it's a post object
          if ($path->ID) {
            $path = Utils::getPostPathname($path->ID);
          } else {
            continue; // invalid path
          }
        } else if (is_int($path)) { // it's a post ID
          $path = Utils::getPostPathname($path);
        } else if (!is_string($path)) {
          continue; // invalid path
        }

        $urlsToRevalidate = [$this->url, ...$this->settings['deployments']];

        foreach ($urlsToRevalidate as $url) {
          if (!is_string($url))
            continue; // invalid deployment url

          try {
            wp_remote_get("$url/{$this->settings['apiBasePath']}/{$this->settings['apiRouterBasePath']}/revalidate/?pathname=$path&secret={$this->settings['authSecret']}");
          } catch (Exception $e) {
            // todo: is echo the right thing to do here? perhaps save the error in an array and process it after we've finished revalidating all paths/environments, then throw an exception?
            echo 'Error while regenerating static page for frontend "', $this->url, '" -- error message: ', $e->getMessage(), "\n";
          }
        }
      }
    }
  }

  /**
   * This method is a simple & quick way to enable on-demand ISR for all posts. It assumes that each post
   * has its own accompanying front-end page (which isn't always true, but it won't break things). If 
   * you want to revalidate other pages when a particular post type is updated, you'll have to do that 
   * yourself, doing something like:
   *    
   *    PostType::make('testimonial')
   *      ->onSave(function ($postId) {
   *        myFrontendInstance->revalidatePages([$postId, '/testimonials', '/']);
   *      })
   * 
   * This example ^ means that whenever a testimonial post is created/updated, we rebuild the individual 
   * page for that testimonial, as well as the /testimonials listing page, and the home page (which for 
   * example might display recent testimonials).
   */
  public function enableDefaultOnDemandISR(): static
  {
    if (\WP_ENV != "development") {
      add_action('save_post', function ($postId) {
        $this->revalidatePages([$postId]);
      }, 10, 1);
    }

    return $this;
  }

  public function getPostPreviewUrl($post)
  {
    $revisionId = '';
    $postId = '';

    if ($post instanceof WP_Post) {
      // $post is a WP_Post object
      if ($post->post_parent) {
        $revisionId = $post->ID; // the ID of the post revision, not the master post
        $postId = $post->post_parent; // the revision's parent == the post we're previewing
      } else {
        $postId = $post->ID;
      }
    }
    // else if (is_string($post)) {
    //   // $post is a preview URL string (unknown why it can change -- probably a WP version thing)
    //   $query_string = parse_url($post, PHP_URL_QUERY);
    //   if ($query_string) {
    //     parse_str($query_string, $params);
    //     $postId = $params['preview_id'];
    //   }

    //   if (!$postId) {
    //     $postId = get_the_ID();
    //   }

    //   // $revisionId = $post->ID; // the ID of the post revision, not the master post
    // } else {
    //   return $post;
    // }

    $path = Utils::getPostPathname($postId);

    $postType = get_post_type($postId); // the master/parent post's post type --> important for cloakwp to retrieve the correct revision data  
    return "{$this->getApiRouteUrl()}/{$this->settings['apiBasePath']}/{$this->settings['apiRouterBasePath']}/preview?revisionId=$revisionId&postId=$postId&postType=$postType&pathname=$path&secret={$this->settings['authSecret']}";
  }

  public function redirectToFrontendPreview()
  {
    if (isset($_GET["preview"]) && $_GET["preview"] == true) {
      $postId = $_GET["p"] ?? $_GET["preview_id"];
      $path = Utils::getPostPathname($postId);
      // wp_is_post_revision($postId) // todo: check if it's a revision and if not, get the latest revision and include `?revisionId=$revisionId` in url below:
      $postType = get_post_type($postId); // the master/parent post's post type --> important for cloakwp to retrieve the correct revision data  
      wp_redirect("{$this->getApiRouteUrl()}/{$this->settings['apiBasePath']}/{$this->settings['apiRouterBasePath']}/preview?postId=$postId&postType=$postType&pathname=$path&secret={$this->settings['authSecret']}");
      exit();
    }
  }

  public function apiRouteUrl(callable|string $url): static
  {
    $this->settings['apiRouteUrl'] = is_callable($url) ? $url() : $url;
    return $this;
  }

  public function getApiRouteUrl()
  {
    return $this->settings['apiRouteUrl'] ?? $this->url;
  }

  public function getUrl()
  {
    return $this->url;
  }

  public function getKey()
  {
    return $this->key;
  }

  public function getSettings(string $setting = null)
  {
    if ($setting) {
      if (isset($this->settings[$setting]))
        return $this->settings[$setting];
      return null;
    }

    return $this->settings;
  }
}
