<?php

namespace CloakWP\API;

use CloakWP\Utils;
use WP_Block;
use pQuery;

/**
 * Fired during plugin activation
 *
 * @link       https://https://github.com/cloak-labs
 * @since      0.7.0
 *
 * @package    CloakWP
 * @subpackage CloakWP/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class provides utilities to convert Gutenberg Block data to JSON, in preparation to expose this data to the REST API.
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class BlockTransformer
{

  public function __construct()
  {
    // $this->bootstrap();
  }

  // TODO: move code from WP_REST_Blocks\Data here

  /**
   * Get blocks from html string.
   *
   * @param string $content Content to parse.
   * @param int    $post_id Post int.
   *
   * @return array
   */
  public static function get_blocks($content, $post_id = 0)
  {
    $output = [];
    $blocks = parse_blocks($content);

    foreach ($blocks as $block) {
      $block_data = self::convert_block_to_object($block, $post_id);
      if ($block_data) {
        $output[] = $block_data;
      }
    }

    return $output;
  }

  /**
   * Process a block, getting all extra fields.
   *
   * @param array $block Block data.
   * @param int   $post_id Post ID.
   *
   * @return array|false
   */
  public static function convert_block_to_object(array $block, $post_id = 0)
  {
    if (!$block['blockName']) {
      return false;
    }

    $block_object = new WP_Block($block);
    $attr         = $block['attrs'];
    if ($block_object && $block_object->block_type) {
      $attributes = $block_object->block_type->attributes;
      $supports   = $block_object->block_type->supports;
      if ($supports && isset($supports['anchor']) && $supports['anchor']) {
        $attributes['anchor'] = [
          'type'      => 'string',
          'source'    => 'attribute',
          'attribute' => 'id',
          'selector'  => '*',
          'default'   => '',
        ];
      }

      if ($attributes) {
        foreach ($attributes as $key => $attribute) {

          // ACF fields special modifications: makes REST API responses include more ACF field information and better data formatting for certain field types (relationships, repeaters, images, etc.).
          if ($key == 'data') {

            $data = $attr[$key];
            $modifiedData = [];
            $repeaterFieldsFound = []; // if a repeater field is found, it'll get added to this array for processing after the following foreach completes

            foreach ($data as $key => $value) { // loop through fields in 'data' (ACF fields)
              if (str_starts_with($key, '_') && str_starts_with($value, 'field_')) { // pick out the fields that have the ACF field names 
                $acf_field_object = get_field_object($value); // contains all info about ACF field, except the value (see below)

                $field_name = ltrim($key, '_');
                $field_value = $data[$field_name];

                // When no field object is found, set as empty array to suppress notices
                if (!$acf_field_object) {
                  $acf_field_object = [];
                }

                if (!isset($acf_field_object['type'])) {
                  $acf_field_object['type'] = '';
                }

                $type = $acf_field_object['type'];

                // by default, ACF relationship fields return an array of post IDs. Here we modify it to return the related posts' full data so we can eliminate the need to make multiple separate requests on headless front-end:
                if ($type == 'relationship' || $type == 'page_link' || $type == 'post_object') {
                  $attr['hasRelationshipFields'] = true;

                  if ($field_value) {
                    $related_posts = [];
                    if (!is_array($field_value)) {
                      $field_value = array($field_value); // convert to array if it isn't already
                    }

                    // loop through array of related page/post IDs and retrieve their full data
                    foreach ($field_value as $related_post_id) {
                      $related_post = get_post($related_post_id);
                      $featured_image = get_the_post_thumbnail_url($related_post_id, 'full'); // add in the post's featured image (not included in post data by default)
                      $acf_fields = get_fields($related_post_id); // add in post's ACF data

                      // $slug = basename(get_permalink($related_post_id)); 
                      // $related_post->slug_test = $slug; // for testing purposes to see if post_name always matches the real slug.. can remove this later

                      $related_post->pathname = Utils::get_post_pathname($related_post_id);
                      $related_post->featured_image = $featured_image;
                      $related_post->acf = $acf_fields;
                      $related_posts[] = $related_post;
                    }
                    $field_value = $related_posts;
                  } else {
                    $field_value = null;
                  }
                }

                // by default, an ACF image field just returns the image ID in the API response. Here we modify it to return an object with the image's src, alt description, width, height, and boolean indicating if the image was resized (not sure if the latter is very useful)
                if ($type == 'image') {
                  $image_id = $field_value;
                  $img_src = wp_get_attachment_image_src($image_id, 'full');
                  $alt_tag = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                  $field_value = array(
                    'src' => $img_src[0],
                    'alt' => $alt_tag,
                    'width' => $img_src[1],
                    'height' => $img_src[2],
                    'is_resized' => $img_src[3],
                  );
                }

                $acf_field_object['value'] = $field_value; // finally, insert the value of the field for the current page/post into the ACF field object --> now we have all the info we need about each ACF field within one object

                // For ACF repeater fields, we will format the sub_fields to be a bit more readable via code after this foreach is done processing.. this sets that up for later:
                if ($type == 'repeater') {
                  $repeaterFieldsFound[] = $acf_field_object;
                }

                $field_types_to_return_full_acf_object = ['repeater', 'relationship', 'page_link', 'post_object'];

                if (in_array($type, $field_types_to_return_full_acf_object)) {
                  $modifiedData[$field_name] = $acf_field_object; // certain field types will equal full ACF object rather than just the field's value
                } else {
                  $modifiedData[$field_name] = $field_value; // these fields will just be key:value
                }
              }
            } // END looping through data's key:values (ACF fields)

            // we wait until all fields have been processed above, and if any repeaters were found in the process, now we clean up how their subfields get formatted in the API response
            if ($repeaterFieldsFound) {
              foreach ($repeaterFieldsFound as $repeater) { // loop through repeaters (each one being a full ACF field object)
                $modifiedData = self::fix_acf_repeater_fields($repeater, $modifiedData);
              }
            }

            $attr['data'] = $modifiedData; // finally, set the data object which will be returned in API responses
          }

          // Regular attribute handling:
          if (!isset($attr[$key])) {
            $attr[$key] = self::get_attribute($attribute, $block_object->inner_html, $post_id);
          }
        }
      }
    }

    $block['rendered'] = $block_object->render();
    $block['rendered'] = do_shortcode($block['rendered']);
    $block['attrs'] = $attr;
    if (!empty($block['innerBlocks'])) {
      $inner_blocks = $block['innerBlocks'];
      $block['innerBlocks'] = [];
      foreach ($inner_blocks as $_block) {
        $block['innerBlocks'][] = self::convert_block_to_object($_block, $post_id);
      }
    }

    return $block;
  }

  /**
   * Given an ACF object for a repeater field, and the current state of the API response data, this function fixes the formatting for repeater sub-fields so they're formatted in the API response as you'd expect
   */
  private static function fix_acf_repeater_fields($repeater, $apiData, $parentRepeaterName = '', $parentRepeaterIndex = '')
  {
    // return $apiData; // uncomment to test the default data response format for repeater fields
    $repeater_name = $repeater['name'];
    $num_repeater_blocks = $repeater['value'];

    $final_repeater_value = [];
    $count = 0;
    while ($count < $num_repeater_blocks) { // loop through repeater's blocks/children
      $repeater_block = [];

      foreach ($repeater['sub_fields'] as $sub_field) { // loop through repeater sub fields
        $sub_field_name = $sub_field['name'];
        $is_nested_repeater = $sub_field['sub_fields'];

        $parent_repeater_prefix = $parentRepeaterName ? $parentRepeaterName . '_' . $parentRepeaterIndex . '_' : '';

        $sub_field_api_default_name = $parent_repeater_prefix . $repeater_name . '_' . $count . '_' . $sub_field_name; // this string is the default key for the current sub_field

        if ($is_nested_repeater) {
          $nestedRepeater = $apiData[$repeater_name . '_' . $count . '_' . $sub_field_name];
          $sub_field_object = self::fix_acf_repeater_fields($nestedRepeater, $apiData, $repeater_name, $count);
        } else {
          $sub_field_object = $apiData[$sub_field_api_default_name];
        }
        $repeater_block[$sub_field_name] = $sub_field_object;
        unset($apiData[$sub_field_api_default_name]); // remove sub_field from top-level, as we're nesting it within its parent value
      }

      $final_repeater_value[] = $repeater_block;
      $count++;
    }

    if ($parentRepeaterName) { // nested repeater --> add final repeater data within its parent repeater
      return $final_repeater_value;
    } else {
      $apiData[$repeater_name]['value'] = $final_repeater_value; // finally, replace repeater's value with the formatted array of repeater blocks
    }

    return $apiData;
  }


  /**
   * Get attribute.
   *
   * @param array  $attribute Attributes.
   * @param string $html HTML string.
   * @param int    $post_id Post Number. Default 0.
   *
   * @return mixed
   */
  public static function get_attribute($attribute, $html, $post_id = 0)
  {
    $value = null;
    if (isset($attribute['source'])) {
      if (isset($attribute['selector'])) {
        $dom = pQuery::parseStr(trim($html));
        if ('attribute' === $attribute['source']) {
          $value = $dom->query($attribute['selector'])->attr($attribute['attribute']);
        } elseif ('html' === $attribute['source']) {
          $value = $dom->query($attribute['selector'])->html();
        } elseif ('text' === $attribute['source']) {
          $value = $dom->query($attribute['selector'])->text();
        } elseif ('query' === $attribute['source'] && isset($attribute['query'])) {
          $nodes   = $dom->query($attribute['selector'])->getIterator();
          $counter = 0;
          foreach ($nodes as $node) {
            foreach ($attribute['query'] as $key => $current_attribute) {
              $current_value = self::get_attribute($current_attribute, $node->toString(), $post_id);
              if (null !== $current_value) {
                $value[$counter][$key] = $current_value;
              }
            }
            $counter++;
          }
        }
      } else {
        $dom  = pQuery::parseStr(trim($html));
        $node = $dom->query();
        if ('attribute' === $attribute['source']) {
          $current_value = $node->attr($attribute['attribute']);
          if (null !== $current_value) {
            $value = $current_value;
          }
        } elseif ('html' === $attribute['source']) {
          $value = $node->html();
        } elseif ('text' === $attribute['source']) {
          $value = $node->text();
        }
      }

      if ($post_id && 'meta' === $attribute['source'] && isset($attribute['meta'])) {
        $value = get_post_meta($post_id, $attribute['meta'], true);
      }
    }

    if (is_null($value) && isset($attribute['default'])) {
      $value = $attribute['default'];
    }

    if (isset($attribute['type']) && rest_validate_value_from_schema($value, $attribute)) {
      $value = rest_sanitize_value_from_schema($value, $attribute);
    }

    return $value;
  }
}
