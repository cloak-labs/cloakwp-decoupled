<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/** Normalizes ACF Link and Page Link values without rewriting unrelated URLs. */
final class AcfLinkFormatter
{
  public function __construct(private readonly FrontendUrlTransformer $urls)
  {
  }

  public function format(mixed $value): mixed
  {
    if (is_array($value) && is_string($value['url'] ?? null)) {
      $value['url'] = $this->urls->makeRelative($value['url'], [home_url()]);
    } elseif (is_string($value)) {
      $value = $this->urls->makeRelative($value, [home_url()]);
    }

    return $value;
  }

  /** Page Link fields can return a single URL or a list when multiple is enabled. */
  public function formatPageLink(mixed $value): mixed
  {
    return is_array($value)
      ? array_map([$this, 'format'], $value)
      : $this->format($value);
  }

  /**
   * Gutenberg can supply raw Link values without calling acf/format_value.
   * Walk registered field definitions after Block Parser rebuilds nested data.
   *
   * @param array<string, mixed> $data
   * @param array<array<string, mixed>> $fields
   * @return array<string, mixed>
   */
  public function formatFields(array $data, array $fields): array
  {
    foreach ($fields as $field) {
      $name = $field['name'] ?? null;
      if (!is_string($name) || !array_key_exists($name, $data)) {
        continue;
      }

      $type = $field['type'] ?? null;
      $value = $data[$name];
      if ($type === 'link') {
        $data[$name] = $this->format($value);
      } elseif ($type === 'page_link') {
        $data[$name] = $this->formatPageLink($value);
      } elseif ($type === 'group' && is_array($value)) {
        $data[$name] = $this->formatFields($value, $field['sub_fields'] ?? []);
      } elseif (($type === 'repeater' || $type === 'flexible_content') && is_array($value)) {
        foreach ($value as $index => $row) {
          if (!is_array($row)) {
            continue;
          }

          $subFields = $field['sub_fields'] ?? [];
          if ($type === 'flexible_content') {
            $subFields = [];
            foreach ($field['layouts'] ?? [] as $layout) {
              if (($layout['name'] ?? null) === ($row['acf_fc_layout'] ?? null)) {
                $subFields = $layout['sub_fields'] ?? [];
                break;
              }
            }
          }

          $data[$name][$index] = $this->formatFields($row, $subFields);
        }
      }
    }

    return $data;
  }
}
