<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\AcfLinkFormatter;

final class FrontendLinksProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    add_filter('page_link', [$cms, 'convertToDecoupledUrl'], 10, 2);
    add_filter('post_link', [$cms, 'convertToDecoupledUrl'], 10, 2);
    add_filter('post_type_link', [$cms, 'convertToDecoupledUrl'], 10, 2);

    $acfLinks = new AcfLinkFormatter($cms->frontendUrls());
    add_filter('cloakwp/block/data', function (array $block, array $fields) use ($acfLinks): array {
      if (is_array($block['data'] ?? null)) {
        $block['data'] = $acfLinks->formatFields($block['data'], $fields);
      }

      return $block;
    }, 20, 2);

    // Block Parser handles nested buttons and synced patterns before this filter.
    // Keep frontend-origin knowledge here rather than in the generic parser.
    add_filter('cloakwp/block', function (array $block) use ($cms): array {
      if (($block['name'] ?? null) !== 'core/button' || !is_string($block['attrs']['url'] ?? null)) {
        return $block;
      }

      $block['attrs']['url'] = $cms->frontendUrls()->makeRelative(
        $block['attrs']['url'],
        [home_url()],
      );

      return $block;
    });

    $relativizeMenuItem = function ($meta) use ($cms) {
      if (!is_array($meta) || !isset($meta['url']) || !is_string($meta['url'])) {
        return $meta;
      }

      $extraBases = [];
      if (function_exists('home_url')) {
        $home = home_url();
        if (is_string($home) && $home !== '') {
          $extraBases[] = $home;
        }
      }

      $meta['url'] = $cms->frontendUrls()->makeRelative($meta['url'], $extraBases);

      return $meta;
    };

    add_filter('cloakwp/decoupled/menu_item/formatted_meta', $relativizeMenuItem, 10, 2);
    add_filter('cloakwp/eloquent/model/menu_item/formatted_meta', $relativizeMenuItem, 10, 2);

    if ($cms->context()->isBackoffice()) {
      add_action('admin_bar_menu', function (\WP_Admin_Bar $wp_admin_bar) use ($cms) {
        $viewSiteNode = $wp_admin_bar->get_node('view-site');
        $siteNameNode = $wp_admin_bar->get_node('site-name');

        if ($viewSiteNode && $siteNameNode) {
          $url = $cms->getActiveFrontend()->getUrl();
          $viewSiteNode->meta['target'] = '_blank';
          $siteNameNode->meta['target'] = '_blank';
          $viewSiteNode->href = $url;
          $siteNameNode->href = $url;
          $wp_admin_bar->add_node((array) $viewSiteNode);
          $wp_admin_bar->add_node((array) $siteNameNode);
        }
      }, 80);
    }
  }
}
