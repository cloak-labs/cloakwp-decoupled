<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Media;

use CloakWP\Decoupled\Media\RelativeUploadUrl;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class RelativeUploadUrlTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
  }

  public function testLeavesAbsoluteUrlsAloneByDefault(): void
  {
    $url = 'https://wp.test/app/uploads/sites/35/2026/09/e2_v1_preview.mp4';
    $this->assertSame($url, RelativeUploadUrl::maybe($url));
  }

  public function testRewritesUploadUrlsWhenRelativeImagesQueryPresent(): void
  {
    $_GET['relative_images'] = '1';
    $this->assertSame(
      '/app/uploads/sites/35/2026/09/e2_v1_preview.mp4',
      RelativeUploadUrl::maybe('https://wp.test/app/uploads/sites/35/2026/09/e2_v1_preview.mp4')
    );
  }

  public function testLeavesCdnUrlsAbsolute(): void
  {
    $_GET['relative_images'] = '1';
    $url = 'https://cdn.example.com/videos/hero.mp4';
    $this->assertSame($url, RelativeUploadUrl::maybe($url));
  }

  public function testRewritesFileArrayUrl(): void
  {
    $_GET['relative_images'] = '1';
    $value = [
      'ID' => 62,
      'id' => 62,
      'url' => 'https://wp.localhost/app/uploads/sites/35/2026/09/e2_v1_preview.mp4',
      'mime_type' => 'video/mp4',
      'title' => 'e2 hero preview video',
    ];

    $this->assertSame(
      '/app/uploads/sites/35/2026/09/e2_v1_preview.mp4',
      RelativeUploadUrl::maybeFile($value)['url']
    );
    $this->assertSame(62, RelativeUploadUrl::maybeFile($value)['ID']);
  }

  public function testRewritesBareFileUrlString(): void
  {
    $_GET['relative_images'] = '1';
    $this->assertSame(
      '/app/uploads/sites/35/file.pdf',
      RelativeUploadUrl::maybeFile('https://wp.localhost/app/uploads/sites/35/file.pdf')
    );
  }

  public function testFilePassthroughWhenDisabled(): void
  {
    $value = [
      'url' => 'https://wp.localhost/app/uploads/sites/35/file.mp4',
    ];
    $this->assertSame($value, RelativeUploadUrl::maybeFile($value));
  }

  public function testWalkRewritesNestedFileUrls(): void
  {
    $_GET['relative_images'] = '1';
    $data = [
      'h1' => 'The world works better when engineering is done properly.',
      'video' => [
        'src' => 'file',
        'files' => [
          'h264' => [
            'ID' => 62,
            'url' => 'https://wp.localhost/app/uploads/sites/35/2026/09/e2_v1_preview.mp4',
            'mime_type' => 'video/mp4',
          ],
        ],
      ],
    ];

    $walked = RelativeUploadUrl::walk($data);

    $this->assertSame(
      '/app/uploads/sites/35/2026/09/e2_v1_preview.mp4',
      $walked['video']['files']['h264']['url']
    );
    $this->assertSame($data['h1'], $walked['h1']);
    $this->assertSame('file', $walked['video']['src']);
  }

  public function testCoerceFileToId(): void
  {
    $this->assertSame(62, RelativeUploadUrl::coerceFileToId([
      'ID' => 62,
      'url' => 'https://wp.localhost/app/uploads/sites/35/file.mp4',
    ]));
    $this->assertSame(62, RelativeUploadUrl::coerceFileToId(62));
  }
}
