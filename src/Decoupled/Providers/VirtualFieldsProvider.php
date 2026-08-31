<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Core\Utils;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\Acf;
use CloakWP\VirtualFields\VirtualField;

final class VirtualFieldsProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isCore()) {
      return;
    }

    add_action('init', function () use ($cms) {
      $customPostTypes = Utils::getCustomPostTypes();
      $publicPostTypes = Utils::getPublicPostTypes();
      $allPostTypes = array_merge($customPostTypes, $publicPostTypes);
      $gutenbergPostTypes = Utils::getEditorPostTypes();
      $formatter = $cms->images();

      register_virtual_fields($publicPostTypes, [
        VirtualField::make('pathname')
          ->value(fn($post) => Utils::getPostPathname(is_array($post) ? $post['id'] : $post->ID)),
      ]);

      $postFields = [
        VirtualField::make('featured_image')
          ->value(function ($post) use ($formatter) {
            if ($post === null) {
              return;
            }
            $postId = is_array($post) ? $post['id'] : $post->ID;
            return $formatter->format(get_post_thumbnail_id($postId));
          }),
        VirtualField::make('author')
          ->value(function ($post) {
            if ($post === null) {
              return;
            }
            $authorId = is_array($post) ? $post['author'] : $post->post_author;
            return Utils::getPrettyAuthor($authorId);
          }),
      ];

      if (Acf::isActive()) {
        $postFields[] = VirtualField::make('acf')
          ->value(function ($post, array $state = []) {
            if ($post === null) {
              return;
            }
            $postId = is_array($post) ? $post['id'] : $post->ID;
            $fields = get_fields($postId);

            if (!is_array($fields) || !$fields) {
              return $fields;
            }

            foreach ($fields as $fieldName => $fieldValue) {
              if (!is_string($fieldName) || stripos($fieldName, 'relationship') === false) {
                continue;
              }

              $containsProcessingId = function ($value) use ($state, &$containsProcessingId): bool {
                if ($value instanceof \WP_Post) {
                  $id = $value->ID ?? null;
                  if (!is_int($id)) {
                    return false;
                  }
                  $status = $state[$id] ?? null;
                  return $status === 'processing' || $status === 'processed';
                }

                if (is_array($value)) {
                  $id = $value['ID'] ?? $value['id'] ?? null;
                  if (is_numeric($id)) {
                    $status = $state[(int) $id] ?? null;
                    if ($status === 'processing' || $status === 'processed') {
                      return true;
                    }
                  }
                  foreach ($value as $v) {
                    if ($containsProcessingId($v)) {
                      return true;
                    }
                  }
                }

                if (is_object($value)) {
                  $id = $value->ID ?? $value->id ?? null;
                  if (is_numeric($id)) {
                    $status = $state[(int) $id] ?? null;
                    return $status === 'processing' || $status === 'processed';
                  }
                }

                return false;
              };

              if ($containsProcessingId($fieldValue)) {
                unset($fields[$fieldName]);
              }
            }

            return $fields;
          });
      }

      $postFields[] = VirtualField::make('taxonomies')
          ->value(function ($post) {
            if ($post === null) {
              return;
            }

            $post = Utils::asPostObject($post);
            $taxonomies = get_object_taxonomies($post->post_type);
            $taxonomiesData = [];

            foreach ($taxonomies as $taxonomy) {
              $terms = wp_get_post_terms($post->ID, $taxonomy);
              $termsData = [];
              foreach ($terms as $term) {
                $termsData[] = [
                  'name' => $term->name,
                  'slug' => $term->slug,
                  'id' => $term->term_id,
                ];
              }
              $taxonomiesData[$taxonomy]['slug'] = $taxonomy;
              $taxonomiesData[$taxonomy]['terms'] = $termsData;
            }

            return $taxonomiesData;
          });

      register_virtual_fields($allPostTypes, $postFields);

      if ($gutenbergPostTypes) {
        register_virtual_fields($gutenbergPostTypes, [
          VirtualField::make('blocks_data')
            ->value(function ($post) use ($cms) {
              if (!$post) {
                return [];
              }
              $parser = $cms->getBlockParser();
              return $parser ? $parser->parseBlocksFromPost($post) : [];
            })
            ->excludeFrom(['core', 'acf']),
        ]);
      }
    }, 99);
  }
}
