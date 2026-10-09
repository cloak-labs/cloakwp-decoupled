<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/**
 * Indexes ACF's local field definitions by parent to avoid repeated full scans.
 *
 * acf_get_local_fields($parent) uses ACF_Data::query(), which calls
 * wp_list_filter() over every registered field. REST schema construction and
 * nested field loading repeat these scans for groups, repeaters, flexible
 * content and clones, making large registries expensive even without DB queries.
 *
 * This store lazily groups fields by parent and reuses that index while the
 * underlying data is unchanged. Supported parent lookups then return only the
 * matching children, preserving their keys and order, instead of scanning the
 * entire registry again. The improvement reduces PHP CPU work; field loading,
 * value formatting and their filters still run through ACF's normal pipeline.
 *
 * All public state is shared with the original store, including writes through
 * retained handles and multisite switches. Data changes rebuild the index;
 * unsupported queries or data that cannot be safely indexed use native matching.
 * Loaded only when ACF_Data is available.
 *
 * @internal
 */
final class IndexedAcfFieldStore extends \ACF_Data
{
  private ?array $indexedData = null;
  private array $children = [];
  private bool $indexable = true;

  public function __construct(\ACF_Data $store)
  {
    foreach (['cid', 'data', 'aliases', 'multisite', 'site_data', 'site_aliases'] as $property) {
      $this->{$property} =& $store->{$property};
    }
  }

  public function query($args, $operator = 'AND')
  {
    if ($operator !== 'AND' || !is_array($args) || count($args) !== 1
      || !isset($args['parent']) || !is_string($args['parent'])
      || $args['parent'] === '' || is_numeric($args['parent'])
    ) {
      return parent::query($args, $operator);
    }

    // PHP compares unchanged arrays by identity. A write through either store
    // triggers rebuilding, including same-size edits and multisite switches.
    if ($this->indexedData !== $this->data) {
      $this->children = [];
      $this->indexable = true;
      foreach ($this->data as $key => $field) {
        if (!is_array($field) || \ReflectionReference::fromArrayElement($this->data, $key) !== null) {
          $this->indexable = false;
          break;
        }
        if (!array_key_exists('parent', $field)) continue;
        // References can mutate both a snapshot and the live row together,
        // hiding changes from array comparison. Retain native matching there.
        if (\ReflectionReference::fromArrayElement($field, 'parent') !== null) {
          $this->indexable = false;
          break;
        }
        if (!isset($field['parent'])) continue;
        if (!is_string($field['parent']) && !is_int($field['parent'])) {
          $this->indexable = false;
          break;
        }
        $this->children[$field['parent']][$key] = $field;
      }
      $this->indexedData = $this->data;
    }

    return $this->indexable
      ? ($this->children[$args['parent']] ?? [])
      : parent::query($args, $operator);
  }
}
