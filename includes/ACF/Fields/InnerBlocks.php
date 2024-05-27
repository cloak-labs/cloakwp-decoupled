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
            $count = -1; // Initialize the index
            // exclude 'Blocks' fields from Layouts to avoid infinite loop/recursion
            $fields = array_filter($fields, function ($field) use (&$count, $fields) {
              $count++;
              if (isset ($field->excludeFromLayouts) && $field->excludeFromLayouts === true) {
                return false;
              }

              if (($count == 0 || $count == count($fields) - 1) && get_class($field) == Accordion::class) {
                return false;
              }
              return true;
            });
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

    $this->settings['key'] = Key::generate($key, $this->keyPrefix);

    return $this->settings;
  }
}
