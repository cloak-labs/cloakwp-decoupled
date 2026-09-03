<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

/**
 * Pure helpers for mapping image attachments to published portfolio projects.
 *
 * WP attachment `post_parent` is the strongest signal, but most library images
 * are unattached — those are resolved from project gallery/image fields and
 * Gutenberg blocks. This class does no I/O so the lookup can stay batched.
 */
final class ProjectImageIndex
{
  /**
   * @param array<int, list<int>> $projectImageIds projectId => image IDs
   * @return array<int, int> imageId => projectId (lowest project ID wins)
   */
  public static function imageToProjectMap(array $projectImageIds): array
  {
    $projectIds = array_keys($projectImageIds);
    sort($projectIds, SORT_NUMERIC);

    $map = [];
    foreach ($projectIds as $projectId) {
      $projectId = (int) $projectId;
      if ($projectId <= 0) {
        continue;
      }
      foreach ($projectImageIds[$projectId] ?? [] as $imageId) {
        $imageId = (int) $imageId;
        if ($imageId > 0 && !isset($map[$imageId])) {
          $map[$imageId] = $projectId;
        }
      }
    }

    return $map;
  }

  /**
   * @param array<int, true> $publishedProjects projectId => true
   * @param array<int, int> $imageToProject imageId => projectId from galleries/blocks
   */
  public static function resolveProjectId(
    int $imageId,
    int $parentId,
    array $imageToProject,
    array $publishedProjects,
  ): ?int {
    if ($parentId > 0 && isset($publishedProjects[$parentId])) {
      return $parentId;
    }

    $fromIndex = $imageToProject[$imageId] ?? null;
    if (is_int($fromIndex) && $fromIndex > 0 && isset($publishedProjects[$fromIndex])) {
      return $fromIndex;
    }

    return null;
  }

  /**
   * @return list<int>
   */
  public static function idsFromMetaValue(mixed $value): array
  {
    if ($value === null || $value === '' || $value === false) {
      return [];
    }

    if (is_int($value) || (is_string($value) && is_numeric(trim($value)))) {
      $id = (int) $value;

      return $id > 0 ? [$id] : [];
    }

    if (is_string($value)) {
      $unserialized = self::maybeUnserialize($value);
      if ($unserialized !== $value) {
        return self::idsFromMetaValue($unserialized);
      }

      if (str_contains($value, ',')) {
        $ids = [];
        foreach (explode(',', $value) as $part) {
          $id = (int) trim($part);
          if ($id > 0) {
            $ids[] = $id;
          }
        }

        return $ids;
      }

      return [];
    }

    if (is_object($value)) {
      $id = (int) ($value->ID ?? $value->id ?? 0);

      return $id > 0 ? [$id] : [];
    }

    if (!is_array($value)) {
      return [];
    }

    $ids = [];
    foreach ($value as $item) {
      foreach (self::idsFromMetaValue($item) as $id) {
        $ids[] = $id;
      }
    }

    return array_values(array_unique($ids));
  }

  /**
   * @param list<array<string, mixed>> $blocks parse_blocks() shape
   * @return list<int>
   */
  public static function idsFromBlocks(array $blocks): array
  {
    $ids = [];
    foreach ($blocks as $block) {
      if (!is_array($block)) {
        continue;
      }

      $attrs = $block['attrs'] ?? [];
      if (is_array($attrs)) {
        if (isset($attrs['id']) && is_numeric($attrs['id'])) {
          $id = (int) $attrs['id'];
          if ($id > 0) {
            $ids[] = $id;
          }
        }

        $data = $attrs['data'] ?? null;
        if (is_array($data)) {
          foreach (self::idsFromAcfBlockData($data) as $id) {
            $ids[] = $id;
          }
        }
      }

      $inner = $block['innerBlocks'] ?? [];
      if (is_array($inner) && $inner !== []) {
        foreach (self::idsFromBlocks($inner) as $id) {
          $ids[] = $id;
        }
      }
    }

    return array_values(array_unique($ids));
  }

  /**
   * @param array<string, mixed> $data
   * @return list<int>
   */
  private static function idsFromAcfBlockData(array $data): array
  {
    $ids = [];
    foreach ($data as $key => $value) {
      if (is_string($key) && str_starts_with($key, '_')) {
        continue;
      }

      if (is_array($value)) {
        foreach (self::idsFromMetaValue($value) as $id) {
          $ids[] = $id;
        }
        continue;
      }

      $keyStr = (string) $key;
      if (
        is_numeric($value)
        && preg_match('/(image|images|gallery|thumbnail|photo|media)/i', $keyStr) === 1
      ) {
        $id = (int) $value;
        if ($id > 0) {
          $ids[] = $id;
        }
      }
    }

    return $ids;
  }

  private static function maybeUnserialize(string $value): mixed
  {
    $trimmed = trim($value);
    if (
      $trimmed === ''
      || (
        !str_starts_with($trimmed, 'a:')
        && !str_starts_with($trimmed, 'i:')
        && !str_starts_with($trimmed, 's:')
      )
    ) {
      return $value;
    }

    $unserialized = @unserialize($trimmed, ['allowed_classes' => false]);

    return $unserialized === false ? $value : $unserialized;
  }
}
