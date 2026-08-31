<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Media;

use CloakWP\Decoupled\Media\ImageFormatter;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class ImageFormatterTest extends TestCase
{
  private ImageFormatter $formatter;

  protected function setUp(): void
  {
    WpStubs::reset();
    $this->formatter = new ImageFormatter();
  }

  public function testArrayPassthrough(): void
  {
    $input = ['ID' => 42, 'url' => 'https://example.com/x.jpg'];
    $this->assertSame($input, $this->formatter->format($input));
  }

  public function testNullAndFalsyPassthrough(): void
  {
    $this->assertNull($this->formatter->format(null));
    $this->assertSame(0, $this->formatter->format(0));
    $this->assertSame('', $this->formatter->format(''));
    $this->assertFalse($this->formatter->format(false));
  }

  public function testSizeDedupWhenWidthsEqual(): void
  {
    WpStubs::$imageSrc[10] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'full' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
    ];

    $result = $this->formatter->format(10);

    $this->assertArrayHasKey('medium', $result);
    $this->assertArrayNotHasKey('large', $result);
    $this->assertArrayNotHasKey('full', $result);
  }

  public function testKeepsLargerSizesWhenWidthsDiffer(): void
  {
    WpStubs::$imageSrc[11] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/sites/1/photo.jpg', 2000, 1333],
    ];

    $result = $this->formatter->format(11);

    $this->assertArrayHasKey('medium', $result);
    $this->assertArrayHasKey('large', $result);
    $this->assertArrayHasKey('full', $result);
  }

  public function testRelativeUrlsWhenRelativeImagesQueryPresent(): void
  {
    $_GET['relative_images'] = '1';
    WpStubs::$imageSrc[12] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/sites/1/photo.jpg', 2000, 1333],
    ];

    $result = $this->formatter->format(12);

    $this->assertSame('/app/uploads/sites/1/photo-300.jpg', $result['medium']['src']);
    $this->assertSame('/app/uploads/sites/1/photo.jpg', $result['full']['src']);
  }

  public function testRelativeUrlsWhenFilterForcesIt(): void
  {
    add_filter('cloakwp/image_format/relative_urls', static fn() => true);
    WpStubs::$imageSrc[13] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/sites/1/photo.jpg', 2000, 1333],
    ];

    $result = $this->formatter->format(13);

    $this->assertSame('/app/uploads/sites/1/photo-300.jpg', $result['medium']['src']);
  }

  public function testAbsoluteUrlsByDefault(): void
  {
    WpStubs::$imageSrc[14] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/sites/1/photo.jpg', 2000, 1333],
    ];

    $result = $this->formatter->format(14);

    $this->assertSame('https://wp.test/app/uploads/sites/1/photo-300.jpg', $result['medium']['src']);
  }

  public function testUnknownRemoteUrlsAreNotFetchedForDimensions(): void
  {
    $url = 'https://untrusted.example/image.jpg';

    $this->assertSame([
      'full' => ['src' => $url],
    ], $this->formatter->format($url));
  }

  public function testAltCaptionTitleWhenPresent(): void
  {
    WpStubs::$imageSrc[15] = [
      'medium' => ['https://wp.test/app/uploads/sites/1/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/sites/1/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/sites/1/photo.jpg', 2000, 1333],
    ];
    WpStubs::$postMeta[15] = ['_wp_attachment_image_alt' => 'Alt text here'];
    WpStubs::$posts[15] = (object) [
      'ID' => 15,
      'post_excerpt' => 'A caption',
      'post_title' => 'Image title',
    ];

    $result = $this->formatter->format(15);

    $this->assertSame('Alt text here', $result['alt']);
    $this->assertSame('A caption', $result['caption']);
    $this->assertSame('Image title', $result['title']);
  }

  public function testFormattingDoesNotMutateGlobalPost(): void
  {
    $globalPost = (object) ['ID' => 999, 'post_title' => 'Current editor post'];
    $GLOBALS['post'] = $globalPost;
    WpStubs::$imageSrc[16] = [
      'medium' => ['https://wp.test/app/uploads/photo-300.jpg', 300, 200],
      'large' => ['https://wp.test/app/uploads/photo-1024.jpg', 1024, 683],
      'full' => ['https://wp.test/app/uploads/photo.jpg', 2000, 1333],
    ];
    WpStubs::$posts[16] = (object) [
      'ID' => 16,
      'post_excerpt' => 'Caption',
      'post_title' => 'Attachment',
    ];

    $this->formatter->format(16);

    $this->assertSame($globalPost, $GLOBALS['post']);
  }
}
