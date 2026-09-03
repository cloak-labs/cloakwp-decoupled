<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Media;

use CloakWP\Decoupled\Media\ProjectImageIndex;
use PHPUnit\Framework\TestCase;

final class ProjectImageIndexTest extends TestCase
{
  public function testIdsFromMetaValueReadsSerializedGalleryAndObjects(): void
  {
    $serialized = serialize(['653', '655', '654']);

    $this->assertSame([653, 655, 654], ProjectImageIndex::idsFromMetaValue($serialized));
    $this->assertSame([10], ProjectImageIndex::idsFromMetaValue(10));
    $this->assertSame([11, 12], ProjectImageIndex::idsFromMetaValue(['11', 12]));
    $this->assertSame([13], ProjectImageIndex::idsFromMetaValue(['ID' => 13]));
    $this->assertSame([14], ProjectImageIndex::idsFromMetaValue((object) ['ID' => 14]));
    $this->assertSame([], ProjectImageIndex::idsFromMetaValue(''));
    $this->assertSame([20, 21], ProjectImageIndex::idsFromMetaValue('20,21'));
  }

  public function testIdsFromBlocksCollectsCoreImageAndAcfGalleriesNotLayoutInts(): void
  {
    $blocks = [
      [
        'blockName' => 'core/image',
        'attrs' => ['id' => 99],
        'innerBlocks' => [],
      ],
      [
        'blockName' => 'acf/images',
        'attrs' => [
          'data' => [
            'manual_images' => ['88', '89'],
            '_manual_images' => 'field_xyz',
            'per_page' => 20,
            'image' => 77,
          ],
        ],
        'innerBlocks' => [
          [
            'blockName' => 'core/image',
            'attrs' => ['id' => 66],
            'innerBlocks' => [],
          ],
        ],
      ],
    ];

    $this->assertSame([99, 88, 89, 77, 66], ProjectImageIndex::idsFromBlocks($blocks));
  }

  public function testImageToProjectMapPrefersLowestProjectId(): void
  {
    $map = ProjectImageIndex::imageToProjectMap([
      50 => [10, 11],
      40 => [11, 12],
    ]);

    $this->assertSame(50, $map[10]);
    $this->assertSame(40, $map[11]);
    $this->assertSame(40, $map[12]);
  }

  public function testResolveProjectIdPrefersPostParentOverGalleryIndex(): void
  {
    $published = [40 => true, 50 => true];
    $index = [10 => 40];

    $this->assertSame(50, ProjectImageIndex::resolveProjectId(10, 50, $index, $published));
    $this->assertSame(40, ProjectImageIndex::resolveProjectId(10, 0, $index, $published));
    $this->assertNull(ProjectImageIndex::resolveProjectId(10, 99, [], $published));
    $this->assertNull(ProjectImageIndex::resolveProjectId(10, 0, $index, []));
  }
}
