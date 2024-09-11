<?php

namespace CloakWP\BlockParser\Helpers;

use pQuery;

class AttributeParser
{
  public function getAttribute(array $attribute, string $html, int $postId = 0)
  {
    $value = null;

    if (isset($attribute['source'])) {
      $value = $this->getAttributeBySource($attribute, $html, $postId);
    }

    if (is_null($value) && isset($attribute['default'])) {
      $value = $attribute['default'];
    }

    if (isset($attribute['type']) && rest_validate_value_from_schema($value, $attribute)) {
      $value = rest_sanitize_value_from_schema($value, $attribute);
    }

    // Remove empty string or empty array values
    if ($value === '' || (is_array($value) && empty($value))) {
      return null;
    }

    return $value;
  }

  protected function getAttributeBySource(array $attribute, string $html, int $postId): mixed
  {
    $source = $attribute['source'];
    $dom = pQuery::parseStr(trim($html));

    if (isset($attribute['selector'])) {
      return $this->getAttributeWithSelector($attribute, $dom, $source);
    }

    return $this->getAttributeWithoutSelector($attribute, $dom, $source, $postId);
  }

  protected function getAttributeWithSelector(array $attribute, $dom, string $source): mixed
  {
    $selector = $attribute['selector'];

    switch ($source) {
      case 'attribute':
        return $dom->query($selector)->attr($attribute['attribute']);
      case 'html':
        return $dom->query($selector)->html();
      case 'text':
        return $dom->query($selector)->text();
      case 'query':
        return $this->handleQuerySource($attribute, $dom);
    }

    return null;
  }

  protected function getAttributeWithoutSelector(array $attribute, $dom, string $source, int $postId): mixed
  {
    $node = $dom->query();

    switch ($source) {
      case 'attribute':
        return $node->attr($attribute['attribute']);
      case 'html':
        return $node->html();
      case 'text':
        return $node->text();
      case 'meta':
        return $this->handleMetaSource($attribute, $postId);
    }

    return null;
  }

  protected function handleQuerySource(array $attribute, $dom): ?array
  {
    $result = [];
    $nodes = $dom->query($attribute['selector'])->getIterator();

    foreach ($nodes as $index => $node) {
      $nodeResult = [];
      foreach ($attribute['query'] as $key => $subAttribute) {
        $value = $this->getAttribute($subAttribute, $node->toString());
        if ($value !== null) {
          $nodeResult[$key] = $value;
        }
      }
      if (!empty($nodeResult)) {
        $result[$index] = $nodeResult;
      }
    }

    return !empty($result) ? $result : null;
  }

  protected function handleMetaSource(array $attribute, int $postId): mixed
  {
    $value = isset($attribute['meta']) ? get_post_meta($postId, $attribute['meta'], true) : null;
    return $value !== '' ? $value : null;
  }
}