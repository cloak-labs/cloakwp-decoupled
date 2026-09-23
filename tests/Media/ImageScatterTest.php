<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Media;

use CloakWP\Decoupled\Media\ImageScatter;
use PHPUnit\Framework\TestCase;

final class ImageScatterTest extends TestCase
{
  public function testNewestImageStaysFirstAndClumpsLeaveTheViewport(): void
  {
    $entries = [];
    foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $group) {
      for ($n = 0; $n < 10; $n++) {
        $entries[] = ['id' => count($entries) + 1, 'group' => $group];
      }
    }

    $out = ImageScatter::reorder($entries);
    $ids = array_column($out, 'id');

    $this->assertSame(range(1, count($entries)), $this->sortedCopy($ids));
    $this->assertSame(1, $out[0]['id']);
    $this->assertSame('a', $out[0]['group']);
    $this->assertNotSame('a', $out[2]['group']);

    $window = ImageScatter::VIEWPORT_WINDOW;
    $max = ImageScatter::MAX_PER_PROJECT_IN_VIEWPORT;
    for ($start = 0; $start < 24; $start++) {
      $slice = array_slice($out, $start, $window);
      $counts = [];
      foreach ($slice as $entry) {
        $counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;
      }
      foreach ($counts as $group => $count) {
        $this->assertLessThanOrEqual(
          $max,
          $count,
          "Group {$group} appeared {$count} times in a viewport starting at {$start}",
        );
      }
    }
  }

  public function testSingleProjectKeepsDateOrder(): void
  {
    $entries = [];
    for ($id = 1; $id <= 6; $id++) {
      $entries[] = ['id' => $id, 'group' => 'only'];
    }

    $out = ImageScatter::reorder($entries);

    $this->assertSame([1, 2, 3, 4, 5, 6], array_column($out, 'id'));
  }

  public function testUnrelatedImagesStayInDateOrder(): void
  {
    $entries = [
      ['id' => 5, 'group' => 'image:5'],
      ['id' => 4, 'group' => 'image:4'],
      ['id' => 3, 'group' => 'image:3'],
    ];

    $this->assertSame($entries, ImageScatter::reorder($entries));
  }

  /**
   * @param list<int> $ids
   * @return list<int>
   */
  private function sortedCopy(array $ids): array
  {
    $copy = $ids;
    sort($copy);

    return $copy;
  }
}
