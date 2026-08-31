<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

final class PreviewProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    add_filter('preview_post_link', function ($preview_link, $post) use ($cms) {
      return $cms->getActiveFrontend()->getPostPreviewUrl($post);
    }, 10, 2);

    add_action('template_redirect', function () use ($cms) {
      $cms->getActiveFrontend()->redirectToFrontendPreview();
    });
  }
}
