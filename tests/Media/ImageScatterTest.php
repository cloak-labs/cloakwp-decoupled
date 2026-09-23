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

  public function testPrioritySharePullsEarlierTermsForward(): void
  {
    $entries = [
      ['id' => 1, 'group' => 'image:1'],
      ['id' => 2, 'group' => 'image:2', 'priority' => 0],
      ['id' => 3, 'group' => 'image:3', 'priority' => 1],
      ['id' => 4, 'group' => 'image:4'],
      ['id' => 5, 'group' => 'image:5', 'priority' => 0],
      ['id' => 6, 'group' => 'image:6', 'priority' => 2],
    ];

    $half = ImageScatter::reorder($entries, 12, 2, 50);
    $this->assertSame([2, 1, 5, 4, 3, 6], array_column($half, 'id'));

    $filled = ImageScatter::reorder($entries, 12, 2, 100);
    $this->assertSame([2, 5, 3, 6, 1, 4], array_column($filled, 'id'));

    $ignored = ImageScatter::reorder($entries, 12, 2, 0);
    $this->assertSame([1, 2, 3, 4, 5, 6], array_column($ignored, 'id'));
  }

  public function testPriorityStillCapsAProjectInsideTheViewport(): void
  {
    $entries = [];
    for ($id = 1; $id <= 4; $id++) {
      $entries[] = ['id' => $id, 'group' => 'hot', 'priority' => 0];
    }
    for ($id = 5; $id <= 16; $id++) {
      $entries[] = ['id' => $id, 'group' => 'image:' . $id];
    }

    $out = ImageScatter::reorder($entries, 12, 2, 100);
    $hot = 0;
    foreach (array_slice($out, 0, 12) as $entry) {
      if ($entry['group'] === 'hot') {
        $hot++;
      }
    }

    $this->assertSame(1, $out[0]['id']);
    $this->assertSame(2, $hot);
  }

  public function testPriorityRanksUsesTheEarliestMatchingTerm(): void
  {
    $ranks = ImageScatter::priorityRanks(
      [
        2 => ['photo_type:12', 'outdoor_living_type:34'],
        3 => ['outdoor_living_type:99'],
        1 => ['client_type:1'],
      ],
      [
        'photo_type:12' => 0,
        'outdoor_living_type:34' => 1,
        'outdoor_living_type:99' => 1,
      ],
    );

    $this->assertSame([2 => 0, 3 => 1], $ranks);
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
