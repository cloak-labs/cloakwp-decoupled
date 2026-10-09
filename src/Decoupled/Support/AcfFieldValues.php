<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

/** Read retained ACF values without formatting fields the consumer will discard. */
final class AcfFieldValues
{
  /** @param list<string> $excluded */
  public static function except(int|string $postId, array $excluded): array|false
  {
    return self::read($postId, null, $excluded);
  }

  /** @param list<string> $included Retains saved, registered root fields only. */
  public static function only(int|string $postId, array $included): array|false
  {
    return self::read($postId, $included, []);
  }

  private static function read(int|string $postId, ?array $included, array $excluded): array|false
  {
    $fields = get_field_objects($postId, false, false);
    if (!$fields) {
      return false;
    }
    $values = [];
    foreach ($fields as $name => $field) {
      if (($included === null || in_array($name, $included, true)) && !in_array($name, $excluded, true)) {
        $values[$name] = get_field($field['key'], $postId);
      }
    }
    return $values;
  }
}
