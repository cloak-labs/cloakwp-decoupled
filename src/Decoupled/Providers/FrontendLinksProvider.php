<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

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
