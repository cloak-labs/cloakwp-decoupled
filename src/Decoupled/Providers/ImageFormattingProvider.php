<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\Acf;

final class ImageFormattingProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    $formatter = $cms->images();

    if (Acf::isActive()) {
      add_filter('acf/format_value/type=image', function ($value) use ($formatter) {
        if (is_array($value)) {
          return $formatter->format($value['ID']);
        }

        return $value;
      }, 20, 3);

      add_filter('acf/format_value/type=gallery', function ($value, $postId) use ($formatter) {
        if (!is_array($value)) {
          return $value;
        }

        $gallery = [];
        foreach ($value as $image) {
          $gallery[] = $formatter->format(is_array($image) ? $image['ID'] : $image);
        }

        return $gallery;
      }, 99, 3);
    }

    add_filter('cloakwp/eloquent/posts/post_type=attachment', function ($attachments) use ($formatter) {
      $formatted = [];
      foreach ($attachments as $attachment) {
        $formatted[] = $formatter->format($attachment['ID']);
      }

      return $formatted;
    });
  }
}
