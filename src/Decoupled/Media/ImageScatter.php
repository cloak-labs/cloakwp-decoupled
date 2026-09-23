<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Media;

/**
 * Reorders a newest-first image list so one project's photos are not a clump.
 *
 * Greedy: always place the newest image that still fits in the trailing
 * viewport window. A desktop masonry viewport is about 4 columns by 3 rows,
 * so that window is 12 images, with at most 2 from the same project.
 * When the library does not have enough projects to satisfy the window,
 * placement rotates instead of dumping the rest of the newest clump.
 */
final class ImageScatter
{
  public const VIEWPORT_WINDOW = 12;

  public const MAX_PER_PROJECT_IN_VIEWPORT = 2;

  /**
   * @param list<array{id: int, group: string}> $entries newest first
   * @return list<array{id: int, group: string}>
   */
  public static function reorder(
    array $entries,
    int $window = self::VIEWPORT_WINDOW,
    int $maxPerGroup = self::MAX_PER_PROJECT_IN_VIEWPORT,
  ): array {
    $window = max(1, $window);
    $maxPerGroup = max(1, $maxPerGroup);
    $count = count($entries);
    if ($count < 2) {
      return $entries;
    }

    $used = array_fill(0, $count, false);
    $out = [];
    $counts = [];
    $windowGroups = [];
    $cursor = 0;

    for ($placed = 0; $placed < $count; $placed++) {
      $pick = self::firstThatFits($entries, $used, $cursor, $counts, $maxPerGroup);
      if ($pick === null) {
        $pick = self::fallback($entries, $used, $cursor, $counts, $windowGroups, $out);
      }
      if ($pick === null) {
        break;
      }

      $used[$pick] = true;
      while ($cursor < $count && $used[$cursor]) {
        $cursor++;
      }

      $group = $entries[$pick]['group'];
      $out[] = $entries[$pick];
      $counts[$group] = ($counts[$group] ?? 0) + 1;
      $windowGroups[] = $group;
      if (count($windowGroups) > $window) {
        $expired = array_shift($windowGroups);
        $counts[$expired] = ($counts[$expired] ?? 1) - 1;
        if ($counts[$expired] <= 0) {
          unset($counts[$expired]);
        }
      }
    }

    return $out;
  }

  /**
   * @param list<array{id: int, group: string}> $entries
   * @param list<bool> $used
   * @param array<string, int> $counts
   */
  private static function firstThatFits(
    array $entries,
    array $used,
    int $cursor,
    array $counts,
    int $maxPerGroup,
  ): ?int {
    $count = count($entries);
    for ($index = $cursor; $index < $count; $index++) {
      if ($used[$index]) {
        continue;
      }
      $group = $entries[$index]['group'];
      if (($counts[$group] ?? 0) < $maxPerGroup) {
        return $index;
      }
    }

    return null;
  }

  /**
   * @param list<array{id: int, group: string}> $entries
   * @param list<bool> $used
   * @param array<string, int> $counts
   * @param list<string> $windowGroups
   * @param list<array{id: int, group: string}> $out
   */
  private static function fallback(
    array $entries,
    array $used,
    int $cursor,
    array $counts,
    array $windowGroups,
    array $out,
  ): ?int {
    $lastGroup = $out === [] ? null : $out[array_key_last($out)]['group'];
    $lastPos = [];
    foreach ($windowGroups as $position => $group) {
      $lastPos[$group] = $position;
    }

    $best = null;
    $bestScore = null;
    $count = count($entries);
    for ($index = $cursor; $index < $count; $index++) {
      if ($used[$index]) {
        continue;
      }
      $group = $entries[$index]['group'];
      $score = [
        $group === $lastGroup ? 1 : 0,
        $counts[$group] ?? 0,
        $lastPos[$group] ?? -1,
        $index,
      ];
      if ($bestScore === null || self::scoreIsLower($score, $bestScore)) {
        $best = $index;
        $bestScore = $score;
      }
    }

    return $best;
  }

  /**
   * @param list<int> $score
   * @param list<int> $best
   */
  private static function scoreIsLower(array $score, array $best): bool
  {
    foreach ($score as $index => $value) {
      $other = $best[$index] ?? 0;
      if ($value === $other) {
        continue;
      }

      return $value < $other;
    }

    return false;
  }
}
