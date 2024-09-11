<?php

namespace CloakWP\BlockParser\Transformers;

use WP_Block;

/**
 * Class ACFBlockTransformer
 * 
 * This class is responsible for transforming ACF blocks into a structured array format.
 */
class ACFBlockTransformer extends AbstractBlockTransformer
{
  /** @var string The type of block this transformer handles */
  protected static string $type = 'acf';

  /**
   * Transform an ACF block's data into a more use-able, structured form
   *
   * @param WP_Block $block The WordPress block object
   * @return array The transformed block data
   */
  public function transform(WP_Block $block, int|null $postId = null): array
  {
    $attrs = $block->attributes;
    $acfFields = $this->transformFields($attrs['data'] ?? [], $block);

    $this->removeUnwantedAttributes($attrs);

    return array_merge(
      $this->formatBaseBlock($block, $attrs),
      ['data' => $acfFields]
    );
  }

  /**
   * Transform non-formatted ACF fields into a more use-able, structured form
   *
   * @param array $fields The raw ACF fields
   * @param WP_Block $block The WordPress block object
   * @return array The transformed fields
   */
  protected function transformFields(array $fields, WP_Block $block): array
  {
    $parsedFields = [];
    $blockId = acf_get_block_id($block->attributes['data']);

    if (is_array($block->attributes['data'])) {
      acf_setup_meta($block->attributes['data'], $blockId);
    }

    foreach ($fields as $key => $value) {
      if ($this->isFieldKey($key, $value)) {
        $fieldName = ltrim($key, '_');
        $fieldObject = get_field_object($value) ?: [];
        $fieldValue = $fields[$fieldName];

        if (!$this->isSubField($fieldObject) && !$this->isAccordion($fieldObject)) {
          $parsedFields[$fieldName] = $this->formatFieldValue($fieldName, $fieldValue, $fieldObject, $blockId);
        }
      } elseif (!isset($fields['_' . $key])) {
        $parsedFields[$key] = $value;
      }
    }

    return $parsedFields;
  }

  /**
   * Check if a given key-value pair represents an ACF field key
   *
   * @param string $key The field key
   * @param mixed $value The field value
   * @return bool True if it's a field key, false otherwise
   */
  protected function isFieldKey(string $key, $value): bool
  {
    return str_starts_with($key, '_') && str_starts_with($value, 'field_');
  }

  /**
   * Check if a field is a sub-field of another field
   *
   * @param array $fieldObject The field object
   * @return bool True if it's a sub-field, false otherwise
   */
  protected function isSubField(array $fieldObject): bool
  {
    return isset($fieldObject['parent']) && str_starts_with($fieldObject['parent'], "field_");
  }

  /**
   * Check if a field is an accordion field
   *
   * @param array $fieldObject The field object
   * @return bool True if it's an accordion field, false otherwise
   */
  protected function isAccordion(array $fieldObject): bool
  {
    return ($fieldObject['type'] ?? '') === 'accordion';
  }

  /**
   * Format the value of an ACF field
   *
   * @param string $fieldName The name of the field
   * @param mixed $fieldValue The raw value of the field
   * @param array $fieldObject The field object
   * @param string $blockId The ID of the block
   * @return mixed The formatted field value
   */
  protected function formatFieldValue(string $fieldName, $fieldValue, array $fieldObject, string $blockId)
  {
    $fieldType = $fieldObject['type'] ?? '';

    if ($this->requiresFormatting($fieldType, $fieldValue)) {
      $fieldValue = get_field($fieldName, $blockId);
    }

    return apply_filters('cloakwp/block/field', $fieldValue, $fieldObject, [
      'type' => $fieldType,
      'name' => $fieldName,
      'blockName' => $fieldObject['name'] ?? '',
    ]);
  }

  /**
   * Check if a field requires additional formatting
   *
   * @param string $fieldType The type of the field
   * @param mixed $fieldValue The value of the field
   * @return bool True if the field requires formatting, false otherwise
   */
  protected function requiresFormatting(string $fieldType, $fieldValue): bool
  {
    $typesRequiringFormatting = ['repeater', 'group', 'flexible_content', 'relationship', 'page_link', 'post_object', 'true_false', 'gallery'];
    return in_array($fieldType, $typesRequiringFormatting) || ($fieldType === 'image' && is_int($fieldValue));
  }
}