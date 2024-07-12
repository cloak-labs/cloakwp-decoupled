<?php

declare(strict_types=1);

use CloakWP\Utils;


if (!function_exists('register_virtual_fields')) {
  function register_virtual_fields(array|string $postTypes, array $virtualFields)
  {
    $MAX_RECURSIVE_DEPTH = 3; // prevents infinite loops (limiting recursion to the specified number) caused by the `value` method of a VirtualField triggering one of the filters used below

    if (!is_array($postTypes))
      $postTypes = [$postTypes];

    // add virtual fields to post objects returned by `get_posts` and/or `WP_Query`:
    add_filter("the_posts", function ($posts, $query) use ($postTypes, $virtualFields, $MAX_RECURSIVE_DEPTH) {
      if (!is_array($posts) || !count($posts))
        return $posts;

      return array_map(function (\WP_Post $post) use ($postTypes, $virtualFields, $MAX_RECURSIVE_DEPTH) {
        if (in_array($post->post_type, $postTypes)) {
          // add each virtual field to post object:
          foreach ($virtualFields as $_field) {
            if ($_field->_getRecursiveIterationCount() < $MAX_RECURSIVE_DEPTH) {
              $field = $_field->getSettings();
              if (in_array('core', $field['excludedFrom']))
                continue;

              $post->{$field['name']} = $_field->getValue($post);
              $_field->_resetRecursiveIterationCount();
            }
          }
        }

        return $post;
      }, $posts);
    }, 20, 2);

    // add virtual fields to post REST API responses:
    add_action('rest_api_init', function () use ($postTypes, $virtualFields) {
      foreach ($virtualFields as $_field) {
        $field = $_field->getSettings();
        if (in_array('rest', $field['excludedFrom']))
          continue;

        register_rest_field(
          $postTypes,
          $field['name'],
          array(
            'get_callback' => function ($post) use ($_field) {
              $postObj = Utils::get_wp_post_object($post);
              return $_field->getValue($postObj);
            },
            'update_callback' => null,
            'schema' => null,
          )
        );
      }
    }, 1);

    // add virtual fields to post "revisions" REST API responses (requires different approach than above):
    add_filter('rest_prepare_revision', function ($response, $post) use ($postTypes, $virtualFields) {
      $parentPost = get_post($post->post_parent); // get the parent's post object
      if (!in_array($parentPost->post_type, $postTypes))
        return $response;

      $data = $response->get_data();

      $parentPost->post_content = $post->post_content; // swap parent's content for revision's content, before we pass $parentPost into getValue()

      foreach ($virtualFields as $_field) {
        $field = $_field->getSettings();
        if (in_array('rest_revisions', $field['excludedFrom']))
          continue;

        $data[$field['name']] = $_field->getValue($parentPost);
      }

      return rest_ensure_response($data);
    }, 10, 2);

    // add virtual fields to ACF relational fields data
    // $addVirtualFieldsToAcfRelationalFields = function ($value, $post_id, $field) use ($postTypes, $virtualFields, &$addVirtualFieldsToAcfRelationalFields) {
    //   if (!$value)
    //     return $value;

    //   if (!is_array($value))
    //     $value = [$value];

    //   // Remove the filter to prevent infinite loop:
    //   // remove_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    //   // Add virtual fields to posts:
    //   $modifiedPosts = array_map(function (WP_Post $relatedPost) use ($postTypes, $virtualFields) {
    //     if (!in_array($relatedPost->post_type, $postTypes))
    //       return $relatedPost;

    //     // add each virtual field to related post object:
    //     foreach ($virtualFields as $_field) {
    //       $field = $_field->getSettings();
    //       if (in_array('acf', $field['excludedFrom']))
    //         continue;

    //       $relatedPost->{$field['name']} = $_field->getValue($relatedPost);
    //     }

    //     return $relatedPost;
    //   }, $value);

    //   // Re-add the filter after the VirtualField `getValue` functions have executed:
    //   // add_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    //   return $modifiedPosts;
    // };

    // add_filter('acf/format_value/type=relationship', $addVirtualFieldsToAcfRelationalFields, 10, 3);

    // add virtual fields to post responses via CloakWP Eloquent package
    add_filter('cloakwp/eloquent/posts', function ($posts) use ($postTypes, $virtualFields) {
      if (!is_array($posts) || !count($posts))
        return $posts;

      if ($posts['ID'])
        $posts = [$posts];

      return array_map(function ($post) use ($postTypes, $virtualFields) {
        if (!is_array($post))
          return $post;

        if (in_array($post['post_type'], $postTypes)) {
          // add each virtual field to post object:
          /** @var \CloakWP\VirtualFields\VirtualField $_field */
          foreach ($virtualFields as $_field) {

            $field = $_field->getSettings();
            if (in_array('core', $field['excludedFrom']))
              continue;

            $post[$field['name']] = $_field->getValue(Utils::get_wp_post_object($post));
          }
        }

        return $post;
      }, $posts);
    }, 10, 1);
  }
}