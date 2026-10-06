<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Media\RelativeUploadUrl;
use CloakWP\Decoupled\Media\AcfMediaValues;
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
      $imageFormat = function ($value) use ($formatter) {
        if (is_array($value)) {
          return $formatter->format($value['ID']);
        }

        return $value;
      };
      add_filter('acf/format_value/type=image', $imageFormat, 20, 3);

      $galleryFormat = function ($value, $postId) use ($formatter) {
        if (!is_array($value)) {
          return $value;
        }

        $gallery = [];
        foreach ($value as $image) {
          $gallery[] = $formatter->format(is_array($image) ? $image['ID'] : $image);
        }

        return $gallery;
      };
      add_filter('acf/format_value/type=gallery', $galleryFormat, 99, 3);
      $optimize = static function () use ($imageFormat, $galleryFormat): void {
        AcfMediaValues::wrap('image', $imageFormat, 20);
        AcfMediaValues::wrap('gallery', $galleryFormat, 99);
      };
      if (did_action('acf/init')) {
        $optimize();
      } else {
        add_action('acf/init', $optimize, 20);
      }

      add_filter('acf/format_value/type=file', static function ($value) {
        return RelativeUploadUrl::coerceFileToId($value);
      }, 9, 3);

      add_filter('acf/format_value/type=file', static function ($value) {
        return RelativeUploadUrl::maybeFile($value);
      }, 20, 3);
    }

    add_filter('cloakwp/block/data', static function ($parsedBlock) {
      if (!is_array($parsedBlock) || !RelativeUploadUrl::enabled()) {
        return $parsedBlock;
      }

      if (isset($parsedBlock['data'])) {
        $parsedBlock['data'] = RelativeUploadUrl::walk($parsedBlock['data']);
      }

      return $parsedBlock;
    }, 20, 1);

    add_filter('cloakwp/eloquent/posts/post_type=attachment', function ($attachments) use ($formatter) {
      $formatted = [];
      foreach ($attachments as $attachment) {
        $formatted[] = $formatter->format($attachment['ID']);
      }

      return $formatted;
    });
  }
}
