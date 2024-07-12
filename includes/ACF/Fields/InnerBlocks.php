<?php

namespace CloakWP\ACF\Fields;

use CloakWP\ACF\BlockRegistry;
use Extended\ACF\Fields\Accordion;
use Extended\ACF\Fields\FlexibleContent;
use Extended\ACF\Fields\Layout;
use Extended\ACF\Key;

class InnerBlocks extends FlexibleContent
{
  private array $includes = [];
  private array $excludes = [];
  public bool $excludeFromLayouts = true;

  public function __construct(string $label, string|null $name = null)
  {
    // copied from base Field class:
    $this->settings = [
      'label' => $label,
      'name' => $name ?? Key::sanitize($label),
      'button_label' => 'Add block',
      'is_inner_blocks' => true, // used in CloakWP BlockTransformer
      'layouts' => [],
    ];
  }

  public function include(array $includedBlocks): self
  {
    $this->includes = $includedBlocks;
    return $this;
  }

  public function exclude(array $excludedBlocks): self
  {
    $this->excludes = $excludedBlocks;
    return $this;
  }

  private function createLayoutsFromBlocks($parentKey): array
  {
    $blocks = BlockRegistry::getInstance()->getBlocks();
    $final_layouts = [];

    // if there are blocks in the $blocks array
    if (!empty($blocks)) {

      // define field filtering function to exclude certain incompatible fields from being nested in an InnerBlock/layout:
      $filterExcludedFields = function ($fields) use (&$filterExcludedFields) {
        $count = -1; // Initialize the index
        // exclude 'Blocks' fields from Layouts to avoid infinite loop/recursion
        $fields = array_filter($fields, function ($field) use (&$count, $fields) {
          $count++;
          if (isset($field->excludeFromLayouts) && $field->excludeFromLayouts === true) {
            // `excludeFromLayouts` is a static property that can be set on custom fields to exclude them from InnerBlocks -- the InnerBlocks field itself uses this to prevent infinite recursion; TODO: allow InnerBlocks within InnerBlocks but limit nested recursion to a specified depth to prevent infinite loop.
            return false;
          }

          $fieldClass = get_class($field);

          // Exclude top-level wrapping Accordion fields because the ACF Flexible Content field UI already wraps layouts in accordion:
          if (($count == 0 || $count == count($fields) - 1) && $fieldClass == Accordion::class) {
            return false;
          }

          return true;
        });

        // Recursively filter sub_fields:
        $fields = array_map(function ($field) use ($filterExcludedFields) {
          $property = (new \ReflectionClass($field))->getProperty('settings');
          $property->setAccessible(true);

          $settings = $field->settings;
          if (isset($settings['sub_fields'])) {
            $field->fields($filterExcludedFields($settings['sub_fields']));
          }
          return $field;
        }, $fields);

        return $fields;
      };

      // loop over each block and create option
      foreach ($blocks as $innerBlock) {
        $settings = $innerBlock->getFieldGroupSettings();
        $included = empty($this->includes) || in_array($settings['name'], $this->includes);

        $is_inner_block_same_as_parent_block = $parentKey == $settings['key'] || str_starts_with($parentKey, $settings['key']); // we don't allow nesting a block within itself
        $excluded = in_array($settings['name'], $this->excludes) || $is_inner_block_same_as_parent_block;

        // filter inner blocks based on whether they've been included or excluded
        if ($included && !$excluded) {
          $fields = $settings['fields'];
          if (is_array($fields)) {
            $fields = $filterExcludedFields($fields);
          }

          // Make a Flexible Content "layout" using each block's fields
          $final_layouts[] = Layout::make($settings['title'], $settings['name'])
            ->layout('block')
            ->fields($fields);
        }
      }
    }

    return $final_layouts;
  }

  private function setLayouts($parentKey)
  {
    $final_layouts = $this->createLayoutsFromBlocks($parentKey);

    if (!empty($final_layouts))
      $this->settings['layouts'] = $final_layouts;
  }

  /** @internal */
  public function get(string|null $parentKey = null): array
  {
    $this->setLayouts($parentKey); // we copied get() from the base Field class just to add this

    $key = $parentKey . '_' . Key::sanitize($this->settings['name']);

    if ($this->type !== null) {
      $this->settings['type'] = $this->type;
    }

    if (isset($this->settings['conditional_logic'])) {
      $this->settings['conditional_logic'] = array_map(
        fn($rules) => $rules->get($parentKey),
        $this->settings['conditional_logic']
      );
    }

    if (isset($this->settings['layouts'])) {

      $array_of_layout_objects = array_filter($this->settings['layouts'], function ($layout) {
        if (is_object($layout) && method_exists($layout, 'get')) {
          return true;
        }
        return false;
      });

      $this->settings['layouts'] = array_map(
        function ($layout) use ($key) {
          return $layout->get($key);
        },
        $array_of_layout_objects
      );

    }

    // Note: `sub_fields` and `collapsed` settings are irrelevant to InnerBlocks fields, so we removed the processing of those here

    $final_key = Key::generate($key, $this->keyPrefix);
    $this->settings['key'] = $final_key;

    // Adjust the ACF formatting of InnerBlocks field values to mimic the standard Block data format: 
    add_filter("acf/format_value/key={$final_key}", function ($value, $post_id, $field) {
      $formattedInnerBlocksValue = [];
      foreach ($value as $layout) {
        // When a Flexible Content layout is from an `InnerBlocks` field, we apply special formatting to mimic a regular Block:
        $name = $layout['acf_fc_layout'];
        unset($layout['acf_fc_layout']);

        $defaultBlock = [
          'name' => $name,
          'type' => 'acf',
          'attrs' => [],
          'data' => $layout
        ];

        $formattedBlock = apply_filters('cloakwp/rest/blocks/response_format', $defaultBlock, ['name' => $name, 'type' => 'acf']);
        $formattedInnerBlocksValue[] = $formattedBlock;
      }

      return $formattedInnerBlocksValue;
    }, 10, 3);

    return $this->settings;
  }
}
