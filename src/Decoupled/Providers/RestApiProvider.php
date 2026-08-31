<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Core\Utils;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\Acf;
use CloakWP\Decoupled\Support\HtmlEntityDecoder;
use WP_Error;
use WP_REST_Response;

final class RestApiProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isRest()) {
      return;
    }

    $cms->auth()->register();

    $decoder = new HtmlEntityDecoder();
    add_filter('rest_prepare_post', function ($response, $post, $request) use ($decoder) {
      $properties = apply_filters('cloakwp/decode_properties', ['title.rendered'], $response, $post, $request);
      $decoder->decodeResponseData($response->data, $properties);
      return $response;
    }, 10, 3);

    if (Acf::isActive()) {
      add_filter('acf/settings/rest_api_format', fn() => 'standard');
      $this->enableRelativeAcfLinks($cms);
    }

    $this->enableCleanParam();
  }

  private function enableCleanParam(): void
  {
    $cleanFn = function (WP_REST_Response|WP_Error $response) {
      if (is_wp_error($response)) {
        return $response;
      }

      if (!isset($_GET['clean']) || $_GET['clean'] === 'false') {
        return $response;
      }

      $original = $response->data;
      $modified = $response->data;

      unset(
        $modified['date_gmt'],
        $modified['modified_gmt'],
        $modified['featured_media'],
        $modified['comment_status'],
        $modified['ping_status'],
        $modified['guid'],
        $modified['categories'],
        $modified['tags'],
      );

      if (!empty($modified['blocks_data'])) {
        unset($modified['content']);
      }

      if (isset($modified['meta']) && ($modified['meta']['footnotes'] ?? null) == '') {
        unset($modified['meta']);
      }

      if (isset($response->data['title'])) {
        $modified['title'] = html_entity_decode($response->data['title']['rendered'], ENT_QUOTES, 'UTF-8');
      }
      if (isset($modified['excerpt']['rendered'])) {
        $modified['excerpt'] = html_entity_decode($response->data['excerpt']['rendered'], ENT_QUOTES, 'UTF-8');
      }
      if (isset($modified['content']['rendered'])) {
        $modified['content'] = html_entity_decode($response->data['content']['rendered'], ENT_QUOTES, 'UTF-8');
      }

      $response->data = apply_filters('cloakwp/clean_rest_response', $modified, $original);
      return $response;
    };

    add_action('init', function () use ($cleanFn) {
      $allPostTypes = array_merge(Utils::getCustomPostTypes(), Utils::getPublicPostTypes());
      $allPostTypes[] = 'revision';
      foreach ($allPostTypes as $postType) {
        add_filter("rest_prepare_{$postType}", $cleanFn, 50, 3);
      }
    }, 99);
  }

  private function enableRelativeAcfLinks(CMS $cms): void
  {
    $transformer = $cms->frontendUrls();

    add_filter('acf/format_value/type=link', function ($value) use ($transformer) {
      if (is_array($value) && !empty($value['url']) && is_string($value['url'])) {
        $value['url'] = $transformer->makeRelative($value['url']);
        return $value;
      }

      if (is_string($value)) {
        return $transformer->makeRelative($value);
      }

      return $value;
    }, 20, 3);
  }
}
