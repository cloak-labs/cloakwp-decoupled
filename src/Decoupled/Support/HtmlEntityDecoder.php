<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/**
 * Decodes HTML entities in nested REST response properties so JS frameworks
 * (e.g. React) render characters like "&" correctly instead of "&amp;".
 */
final class HtmlEntityDecoder
{
  /**
   * Decode a single nested property on $data using dot notation
   * (e.g. "title.rendered").
   *
   * @param array<string, mixed> $data
   */
  public function decodeNestedProperty(array &$data, string $property): void
  {
    $parts = explode('.', $property);
    $current = &$data;

    foreach ($parts as $part) {
      if (!is_array($current) || !isset($current[$part])) {
        // Property doesn't exist in the response, so we can't decode it
        return;
      }
      $current = &$current[$part];
    }

    if (is_string($current)) {
      $current = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
    }
  }

  /**
   * Decode multiple nested properties on a REST response data array.
   *
   * @param array<string, mixed> $data
   * @param list<string> $properties Dot-notation property paths.
   */
  public function decodeResponseData(array &$data, array $properties): void
  {
    foreach ($properties as $property) {
      $this->decodeNestedProperty($data, $property);
    }
  }
}
