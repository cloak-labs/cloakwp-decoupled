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
 * This class provides utilities to convert Gutenberg/ACF Block data to JSON, in preparation to expose this data to the REST API.
 *
 * @since      0.7.0
 * @package    CloakWP
 * @subpackage CloakWP/includes
 * @author     Cloak Labs 
 */

class BlockTransformer
{
  private $acf_fields = [];
  private $current_block_name = [];

  public function __construct()
  {
    Utils::add_hook_variations('filter', 'cloakwp/rest/blocks/response_format', array('name', 'type'));
    Utils::add_hook_variations('filter', 'cloakwp/rest/blocks/acf_response_format', array('name', 'type', 'blockName'), 2);
  }

  /**
   * Get blocks from html string.
   *
   * @param string $content Content to parse.
   * @param int    $post_id Post int.
   *
   * @return array
   */
  public function getBlocksFromPost(int|\WP_Post|array $post)
  {
    $postObj = Utils::get_wp_post_object($post);
    $content = $postObj->post_content;
    $output = [];
    $blocks = parse_blocks($content);

    foreach ($blocks as $block) {
      $this->acf_fields = []; // reset
      $block_data = $this->convertBlockToObject($block, $postObj->id);
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
  public function convertBlockToObject(array $block, $post_id = 0)
  {
    if (!$block['blockName']) {
      return false;
    }

    $raw_block_object = new WP_Block($block);
    $attrs = $block['attrs'];
    $block_type = 'core';
    $this->current_block_name = $block['blockName'];

    if ($raw_block_object && $raw_block_object->block_type) {

      $attributes = $raw_block_object->block_type->attributes;
      $supports = $raw_block_object->block_type->supports;

      if ($supports && isset($supports['anchor']) && $supports['anchor']) {
        $attributes['anchor'] = [
          'type' => 'string',
          'source' => 'attribute',
          'attribute' => 'id',
          'selector' => '*',
          'default' => '',
        ];
      }

      if ($attributes) {
        foreach ($attributes as $key => $attribute) {

          $enable_acf_block_transform = apply_filters('cloakwp/rest/blocks/enable_acf_block_transform', true);

          // Special processing of ACF Blocks:
          if ($key == 'data') {
            $block_type = 'acf';
            if ($enable_acf_block_transform) {
              $fields = $attrs[$key];
              $this->transform_acf_fields($fields); // $this->acf_fields gets added back to the block object further below
            }
          }

          if (($block_type == 'core' || ($block_type == 'acf' && !$enable_acf_block_transform)) && !isset($attrs[$key])) {
            // Regular attribute handling:
            $attr_value = $this->get_attribute($attribute, $raw_block_object->inner_html, $post_id);
            if ($attr_value)
              $attrs[$key] = $attr_value;
          }
        }
      }
    }

    // Remove unwanted attributes from `attrs`
    unset($attrs['data'], $attrs['name'], $attrs['mode']);

    // Initialize formatted block data to return
    $formatted_block = array(
      'name' => $block['blockName'],
      'type' => $block_type,
      'attrs' => $attrs
    );

    // Add ACF fields under 'data' property
    if ($this->acf_fields)
      $formatted_block['data'] = $this->acf_fields;

    // Add 'innerBlocks' if they exist
    if (!empty($block['innerBlocks'])) {
      $inner_blocks = $block['innerBlocks'];
      $formatted_block['innerBlocks'] = [];
      foreach ($inner_blocks as $_block) {
        $formatted_block['innerBlocks'][] = $this->convertBlockToObject($_block, $post_id);
      }
    }

    // the "rendered" property is not usually useful when doing headless the "right" way, so we don't include it by default:
    $condition = $block_type == 'core';
    $include_rendered = apply_filters('cloakwp/rest/blocks/include_rendered', $condition, $formatted_block);
    if ($include_rendered) {
      $rendered = $raw_block_object->render();
      $formatted_block['rendered'] = do_shortcode($rendered);
    }

    /* 
      Allow devs to override/customize the returned Block data via the filter below. You can also 
      append `/name=block_name`, `/type=acf`, or `/type=core` to the filter to target blocks more granularly.
    */
    $final_block = apply_filters('cloakwp/rest/blocks/response_format', $formatted_block, $raw_block_object);
    return $final_block;
  }


  /* 
    transform_acf_fields

    Loops over and transforms ACF fields from an ACF Block into proper/use-able format, 
    sometimes exanding data such as IDs into full data objects, to prevent having to 
    run multiple REST API requests to fetch a block's full data
  */
  private function transform_acf_fields($fields)
  {
    // $this->acf_fields = $fields; return; // uncomment this and visit a post REST API endpoint when you want to see the shape of the original, non-transformed ACF data that we're working with
    $parent_fields_found = [];

    foreach ($fields as $key => $value) { // loop through fields in 'data' (ACF fields)
      if (str_starts_with($key, '_') && str_starts_with($value, 'field_')) { // pick out the fields that have the ACF field names 
        $acf_field_object = get_field_object($value); // contains all info about ACF field, except the value (see below)

        $field_name = ltrim($key, '_');
        $field_value = $fields[$field_name];

        // When no field object is found, set as empty array to suppress notices
        if (!$acf_field_object)
          $acf_field_object = [];
        if (!isset($acf_field_object['type']))
          $acf_field_object['type'] = '';
        $type = $acf_field_object['type'];

        /* 
          By default, ACF relationship fields return an array of post IDs. Below we modify it to return the related posts' 
          full data so we can eliminate the need to make multiple separate requests on decoupled front-end:
        */
        $relational_field_types = array('relationship', 'page_link', 'post_object');
        $is_relational_field = in_array($type, $relational_field_types);
        if ($is_relational_field) {
          if ($field_value) {
            $related_posts = [];
            if (!is_array($field_value))
              $field_value = array($field_value); // convert to array if it isn't already

            // loop through array of related page/post IDs and retrieve their full data
            foreach ($field_value as $related_post) {
              $related_post = get_post($related_post);
              Utils::write_log('Relationship post id:');
              Utils::write_log($related_post->ID);

              $related_post->author = Utils::get_pretty_author($related_post->post_author); // replace default 'post_author' (ID) with basic 'author' object
              $related_post->pathname = Utils::get_post_pathname($related_post->ID); // front-end route for post
              $related_post->featured_image = get_the_post_thumbnail_url($related_post->ID, 'full');
              $related_post->acf = get_fields($related_post->ID);
              $related_post->id = $related_post->ID;

              // We remove a bunch of fields that are usually useless -- user can add any of these (and more) back by using the 'cloakwp/rest/blocks/acf_response_format/type=relationship' filter hook
              $properties_to_remove = [
                'ID', // replaced by lowercase 'id' field above (uppercase is weird)
                'post_author', // replaced by 'author' field above 
                'post_date_gmt',
                'comment_status',
                'ping_status',
                'to_ping',
                'pinged',
                'post_modified_gmt',
                'post_content_filtered',
                'guid',
                'post_mime_type',
                'comment_count',
                'filter',
                'post_password',
                'post_parent',
                'post_content' // removing post_content is potentially controversial -- but it adds a lot of weight to payload sizes, so we prefer for users to add it back via the 'cloakwp/rest/blocks/acf_response_format/type=relationship' filter hook if they need it
              ];

              foreach ($properties_to_remove as $p) {
                unset($related_post->{$p});
              }

              if ($type == 'post_object')
                $related_posts = $related_post;
              else
                $related_posts[] = $related_post;
            }
            $field_value = $related_posts;
          } else {
            $field_value = null;
          }
        }

        /* 
          By default, an ACF image field just returns the image ID in the API response. Here we modify it to return an
          object with the image's src, alt description, width, height, and boolean indicating if the image was 
          resized (not sure if the latter is very useful)
        */
        if ($type == 'image') {
          $image_id = $field_value;
          $img_src = wp_get_attachment_image_src($image_id, 'full');
          $isSrcValid = is_array($img_src);
          $alt_tag = get_post_meta($image_id, '_wp_attachment_image_alt', true);
          $field_value = array(
            'id' => $image_id,
            'src' => $isSrcValid ? $img_src[0] : $img_src,
            'alt' => $alt_tag,
            'width' => $isSrcValid ? $img_src[1] : $img_src,
            'height' => $isSrcValid ? $img_src[2] : $img_src,
            'is_resized' => $isSrcValid ? $img_src[3] : $img_src,
          );
        }

        /* 
          Convert true_false field values from 1/0 to true/false
        */
        if ($type == 'true_false') {
          $field_value = [
            "0" => false,
            "1" => true
          ][$field_value];
        }

        $acf_field_object['value'] = $field_value; // finally, insert the value of the field for the current page/post into the ACF field object --> now we have all the info we need about each ACF field within one object

        /* 
          For ACF repeater and group fields, we will format their sub_fields to be in a proper format
          AFTER this foreach is done processing. This ensures that all sub_fields get the same response
          formats as top-level fields; eg. an image field that is a repeater sub_field still gets the 
          special treatment that you see a few lines up ^^
        */
        if ($type == 'repeater' || $type == 'group' || $type == 'flexible_content') {
          /* 
            repeaters and groups are not done processing, so we set them to their full ACF field
            Objects at this point; they will eventually get processed and set to their final values.
          */
          $is_top_level_field = str_starts_with($acf_field_object['parent'], 'group_');
          if ($is_top_level_field) {
            /* 
              if the repeater/group has a "parent" value of "group_*", it means it's a top-level field 
              within the Field Group, whereas a parent value of "field_*" means it's nested within another 
              Group/Repeater --> in which case we don't set it up for processing at this point; top-level
              group/repeater fields will trigger the processing of any children groups/repeaters for us.
            */
            $parent_fields_found[] = $acf_field_object;
          }

          $this->acf_fields[$field_name] = $acf_field_object; // will get formatted later
        } else {
          // all other fields are done processing at this point, so we set them to their final values
          $filter_variation_values = array(
            'type' => $type,
            'name' => $field_name,
            'blockName' => $this->current_block_name,
          );
          $final_value = apply_filters('cloakwp/rest/blocks/acf_response_format', $field_value, $acf_field_object, $filter_variation_values);
          $this->acf_fields[$field_name] = $final_value;
        }
      } else {
        // if we end up here, we have to assume the field is formatted correctly by default, so we simply add it to the block's final ACF data response:
        $this->acf_fields[$key] = $value;
      }
    } // END looping through the Block's ACF fields

    // we wait until all fields have been processed above, and if any repeaters or groups were found in the process, now we clean up how their subfields get formatted in the API response:
    foreach ($parent_fields_found as $parent) { // note: $parent == a full ACF field object
      $this->transform_acf_parent_field($parent);
    }
  }


  /** Special handling for formatting Repeater/Group fields:
   * 
   * eg. default data structure for repeaters (obviously not very useful):
   *  {
   *    cards: 2, // indicates the # of rows in repeater (don't ask me why)
   *    cards_0_sub_field_1: 'value 1 for repeater row #1',
   *    cards_0_sub_field_2: 'value 2 for repeater row #1',
   *    cards_1_sub_field_1: 'value 1 for repeater row #2',
   *    cards_1_sub_field_2: 'value 2 for repeater row #2',
   *    ...
   *  }
   * 
   *  ... will get transformed into:
   *  {
   *    cards: [
   *      { 
   *        sub_field_1: 'value 1 for repeater row #1',
   *        sub_field_2: 'value 2 for repeater row #1',
   *      },
   *      { 
   *        sub_field_1: 'value 1 for repeater row #2',
   *        sub_field_2: 'value 2 for repeater row #2',
   *      },
   *    ]
   *  }
   */
  private function transform_acf_parent_field($parent_field_obj, $grandparent_field_name = '')
  {
    // return $this->acf_fields; // uncomment to test the default data response format for repeater/group fields
    $og_parent_value = $parent_field_obj['value'];
    $final_parent_value = [];
    $field_name = $parent_field_obj['name'];
    $field_type = $parent_field_obj['type']; // 'repeater' or 'group' or 'flexible_content'
    $is_inner_blocks = $parent_field_obj['is_inner_blocks'] ?? false; // it's a CloakWP InnerBlocks field, an extension of the Flexible Content field type

    $num_sub_groups = 1;
    if ($field_type == 'repeater')
      $num_sub_groups = $og_parent_value ?? 0; // for repeaters, og_parent_value == the number (integer) of repeater blocks
    else if ($field_type == 'flexible_content')
      $num_sub_groups = is_array($og_parent_value) ? count($og_parent_value) : 0; // for flexible_content fields, og_parent_value == an array of the layout names used by that instance, in correct order

    $sub_fields = [];
    if (isset($parent_field_obj['sub_fields']))
      $sub_fields = $parent_field_obj['sub_fields'];

    $prefix_base = $grandparent_field_name . $field_name . '_';
    $sub_field_prefix = $prefix_base;

    $count = 0;
    while ($count < $num_sub_groups) { // loop through repeater's blocks
      if ($field_type == 'repeater' || $field_type == 'flexible_content')
        $sub_field_prefix = $prefix_base . $count . '_';

      if ($field_type == 'flexible_content') {
        $layouts = $parent_field_obj['layouts'];
        if (is_array($layouts)) {
          $result = array_filter($layouts, function ($layout) use ($og_parent_value, $count) {
            return $layout['name'] == $og_parent_value[$count];
          });

          if (!empty($result)) {
            $result = reset($result);
            $sub_fields = $result['sub_fields'];
          }
        }
      }

      $formatted_sub_fields = $this->transform_acf_sub_fields($sub_fields, $sub_field_prefix);

      if ($field_type == 'flexible_content') {
        $formatted_sub_fields = [
          'name' => $og_parent_value[$count], // layout name
          'data' => $formatted_sub_fields
        ];

        if ($is_inner_blocks) {
          $formatted_sub_fields['type'] = 'acf';
          $formatted_sub_fields = apply_filters('cloakwp/rest/blocks/response_format', $formatted_sub_fields, null);
        }
      }

      if ($field_type == 'group') {
        $final_parent_value = $formatted_sub_fields;
      } else {
        $final_parent_value[] = $formatted_sub_fields;
      }
      $count++;
    }

    $filter_variation_values = array(
      'type' => $field_type,
      'name' => $field_name,
      'blockName' => $this->current_block_name,
    );

    $final_value = apply_filters('cloakwp/rest/blocks/acf_response_format', $final_parent_value, $parent_field_obj, $filter_variation_values);

    if ($grandparent_field_name) { // nested repeaters/groups (recursion) return early --> their final_value will get added to its parent repeater/group
      return $final_value;
    }

    $this->acf_fields[$field_name] = $final_value; // finally, replace repeater/group's value with the formatted sub_fields
  }


  // Special handling for formatting Repeater/Group sub_fields:
  private function transform_acf_sub_fields($sub_fields, $sub_field_prefix)
  {

    // TODO: figure out why infinite loop with repeater field is happening

    $final_group = [];
    $current_field = 0;
    $LIMIT = 1000; // limit to 1000 sub_fields to prevent infinite loops (rare case)
    foreach ($sub_fields as $sub_field) { // loop through repeater sub fields
      $current_field++;
      if ($current_field == $LIMIT)
        break;

      $sub_field_name = $sub_field['name'];
      if (!$sub_field_name)
        continue; // skips over and excludes ACF "message" fields from API response

      $field_type = $sub_field['type'];
      $sub_field_api_default_name = $sub_field_prefix . $sub_field_name; // this string is the field key for the current sub_field in the default Block API Response (before transformation occurs)

      if ($field_type == 'repeater') {
        $nestedRepeater = $this->acf_fields[$sub_field_api_default_name]; // note: $nestedRepeater is different than $sub_field because its 'value' property was set by us earlier, whereas $sub_field['value'] == null --> this 'value' is required to make the repeater while loop work properly
        $sub_field_value = $this->transform_acf_parent_field($nestedRepeater, $sub_field_prefix);
      } else if ($field_type == 'group' || $field_type == 'flexible_content') {
        $sub_field_value = $this->transform_acf_parent_field($sub_field, $sub_field_prefix);
      } else {
        // if (!isset($this->acf_fields[$sub_field_api_default_name])) break; // prevent 
        $sub_field_value = $this->acf_fields[$sub_field_api_default_name];
      }

      $final_group[$sub_field_name] = $sub_field_value;
      unset($this->acf_fields[$sub_field_api_default_name]); // remove sub_field from top-level, as we're nesting it within its parent value
    }
    return $final_group;
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
  public function get_attribute($attribute, $html, $post_id = 0)
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
          $nodes = $dom->query($attribute['selector'])->getIterator();
          $counter = 0;
          foreach ($nodes as $node) {
            foreach ($attribute['query'] as $key => $current_attribute) {
              $current_value = $this->get_attribute($current_attribute, $node->toString(), $post_id);
              if (null !== $current_value) {
                $value[$counter][$key] = $current_value;
              }
            }
            $counter++;
          }
        }
      } else {
        $dom = pQuery::parseStr(trim($html));
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
