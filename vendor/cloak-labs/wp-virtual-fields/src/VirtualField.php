<?php

declare(strict_types=1);

namespace CloakWP\VirtualFields;

use InvalidArgumentException;

class VirtualField
{
  protected string $name;
  protected $value;
  protected array $excludedFrom = [];
  protected int $recursizeIterationCount = 0;

  public function __construct(string $field_name)
  {
    $this->name = $field_name; // todo: sanitize $field_name to ensure it's a valid format for a field name
  }

  public static function make(string $field_name): static
  {
    return new static($field_name);
  }

  /** Either provide a static value, or a callback function that receives 
   *  a WP_Post object and returns the value.
   */
  public function value(mixed $value): static
  {
    if (is_callable($value)) {
      $this->value = function ($args) use ($value) {
        $this->recursizeIterationCount++;
        $result = $value($args);
        return $result;
      };
    } else {
      $this->value = $value;
    }

    return $this;
  }

  /** For internal use only. */
  public function _resetRecursiveIterationCount()
  {
    $this->recursizeIterationCount = 0;
  }
  /** For internal use only. */
  public function _getRecursiveIterationCount(): int
  {
    return $this->recursizeIterationCount;
  }

  public function excludeFrom(array $excludeFrom): static
  {
    $allowedValues = ['rest', 'core', 'rest_revisions', 'acf'];
    $invalidValues = array_diff($excludeFrom, $allowedValues);

    if (!empty($invalidValues)) {
      $allowedValuesList = implode(', ', $allowedValues);
      $invalidValuesList = implode(', ', $invalidValues);
      throw new InvalidArgumentException("Invalid value(s) in the 'excludeFrom' array: $invalidValuesList. Allowed values are: $allowedValuesList");
    }

    $this->excludedFrom = $excludeFrom;
    return $this;
  }

  public function getSettings(): array
  {
    return [
      'name' => $this->name,
      'excludedFrom' => $this->excludedFrom,
    ];
  }

  public function getValue($args)
  {
    $v = $this->value;
    return is_callable($v) ? $v($args) : $v;
  }
}