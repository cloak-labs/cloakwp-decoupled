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
 *
 * An optional priority rank (lower is earlier) and share pull matching
 * images toward the top. A share of 50 keeps about half of the leading
 * images on those ranks, in rank order, until the matches run out.
 */
final class ImageScatter
{
  public const VIEWPORT_WINDOW = 12;

  public const MAX_PER_PROJECT_IN_VIEWPORT = 2;

  /**
   * @param list<array{id: int, group: string, priority?: int}> $entries newest first
   * @return list<array{id: int, group: string, priority?: int}>
   */
  public static function reorder(
    array $entries,
    int $window = self::VIEWPORT_WINDOW,
    int $maxPerGroup = self::MAX_PER_PROJECT_IN_VIEWPORT,
    int $priorityShare = 0,
  ): array {
    $window = max(1, $window);
    $maxPerGroup = max(1, $maxPerGroup);
    $priorityShare = max(0, min(100, $priorityShare));
    $count = count($entries);
    if ($count < 2) {
      return $entries;
    }

    $usePriority = $priorityShare > 0 && self::hasPriority($entries);
    $used = array_fill(0, $count, false);
    $out = [];
    $counts = [];
    $windowGroups = [];
    $cursor = 0;
    $priorityPlaced = 0;

    for ($placed = 0; $placed < $count; $placed++) {
      $pick = null;
      if ($usePriority) {
        $wantPriority = ($priorityPlaced * 100) < ($priorityShare * ($placed + 1));
        $pick = self::firstThatFits(
          $entries,
          $used,
          $cursor,
          $counts,
          $maxPerGroup,
          $wantPriority ? 'priority' : 'rest',
        );
      }
      if ($pick === null) {
        $pick = self::firstThatFits($entries, $used, $cursor, $counts, $maxPerGroup);
      }
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
      if (array_key_exists('priority', $entries[$pick])) {
        $priorityPlaced++;
      }
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
   * Best (lowest) rank for each image. Keys are "taxonomy:termId".
   *
   * @param array<int, list<string>> $termsByImage
   * @param array<string, int> $keyRanks
   * @return array<int, int>
   */
  public static function priorityRanks(array $termsByImage, array $keyRanks): array
  {
    $ranks = [];
    foreach ($termsByImage as $imageId => $keys) {
      $best = null;
      foreach ($keys as $key) {
        if (!isset($keyRanks[$key])) {
          continue;
        }
        $rank = $keyRanks[$key];
        if ($best === null || $rank < $best) {
          $best = $rank;
        }
      }
      if ($best !== null) {
        $ranks[(int) $imageId] = $best;
      }
    }

    return $ranks;
  }

  /**
   * @param list<array{id: int, group: string, priority?: int}> $entries
   */
  private static function hasPriority(array $entries): bool
  {
    foreach ($entries as $entry) {
      if (array_key_exists('priority', $entry)) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param list<array{id: int, group: string, priority?: int}> $entries
   * @param list<bool> $used
   * @param array<string, int> $counts
   * @param 'any'|'priority'|'rest' $pool
   */
  private static function firstThatFits(
    array $entries,
    array $used,
    int $cursor,
    array $counts,
    int $maxPerGroup,
    string $pool = 'any',
  ): ?int {
    $count = count($entries);
    $best = null;
    $bestRank = PHP_INT_MAX;
    for ($index = $cursor; $index < $count; $index++) {
      if ($used[$index]) {
        continue;
      }
      $hasPriority = array_key_exists('priority', $entries[$index]);
      if ($pool === 'priority' && !$hasPriority) {
        continue;
      }
      if ($pool === 'rest' && $hasPriority) {
        continue;
      }
      $group = $entries[$index]['group'];
      if (($counts[$group] ?? 0) >= $maxPerGroup) {
        continue;
      }
      if ($pool !== 'priority') {
        return $index;
      }
      $rank = (int) $entries[$index]['priority'];
      if ($best === null || $rank < $bestRank) {
        $best = $index;
        $bestRank = $rank;
      }
    }

    return $best;
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
