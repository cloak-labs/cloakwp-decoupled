<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\FrontendLinksProvider;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ButtonRelativeUrlsTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();

    $cms = CMS::getInstance(WpContext::new()->force(WpContext::REST))
      ->frontends([
        Frontend::make('website', 'https://staging.example.com')
          ->deployments(['https://preview.example.com']),
      ]);
    foreach ($cms->providers() as $provider) {
      if (!$provider instanceof FrontendLinksProvider) {
        $cms->removeProvider($provider::class);
      }
    }
    $cms->boot();
  }

  #[DataProvider('buttonUrls')]
  public function testParsedButtonUrls(string $url, string $expected): void
  {
    $block = [
      'name' => 'core/button',
      'type' => 'core',
      'attrs' => [
        'url' => $url,
        'text' => 'Learn more',
        'linkTarget' => '_blank',
        'rel' => 'noreferrer noopener',
      ],
    ];
    $expectedBlock = $block;
    $expectedBlock['attrs']['url'] = $expected;

    $this->assertSame($expectedBlock, apply_filters('cloakwp/block', $block));
  }

  public static function buttonUrls(): iterable
  {
    yield 'frontend' => ['https://staging.example.com/About/?a=1&b=2#details', '/About/?a=1&b=2#details'];
    yield 'deployment' => ['https://preview.example.com/work', '/work'];
    yield 'wordpress' => ['https://wp.example.test/contact/', '/contact/'];
    yield 'homepage' => ['https://staging.example.com', '/'];
    yield 'homepage query' => ['https://staging.example.com?search=foo#results', '/?search=foo#results'];
    yield 'homepage fragment' => ['https://staging.example.com#contact', '/#contact'];
    yield 'case insensitive origin' => ['https://STAGING.EXAMPLE.COM/About', '/About'];
    yield 'external' => ['https://other.example.com/work', 'https://other.example.com/work'];
    yield 'similar hostname' => ['https://staging.example.com.other.test/work', 'https://staging.example.com.other.test/work'];
    yield 'relative' => ['/contact/?from=button#form', '/contact/?from=button#form'];
    yield 'fragment' => ['#contact', '#contact'];
    yield 'email' => ['mailto:hello@example.com', 'mailto:hello@example.com'];
    yield 'phone' => ['tel:+16045551234', 'tel:+16045551234'];
    yield 'empty' => ['', ''];
  }

  public function testAdditionalInternalBasesCanBeConfigured(): void
  {
    add_filter('cloakwp/relative_frontend_urls', static function (array $bases): array {
      $bases[] = 'https://production.example.com';
      return $bases;
    });

    $block = ['name' => 'core/button', 'attrs' => ['url' => 'https://production.example.com/team']];
    $this->assertSame('/team', apply_filters('cloakwp/block', $block)['attrs']['url']);
  }

  public function testOtherBlocksAndMissingButtonUrlsAreUnchanged(): void
  {
    $blocks = [
      ['name' => 'core/image', 'attrs' => ['url' => 'https://staging.example.com/image.jpg']],
      ['name' => 'core/buttons', 'attrs' => []],
      ['name' => 'core/button', 'attrs' => []],
      ['name' => 'core/button', 'attrs' => ['url' => null]],
      [['name' => 'core/button', 'attrs' => ['url' => 'https://staging.example.com/team']]],
    ];

    foreach ($blocks as $block) {
      $this->assertSame($block, apply_filters('cloakwp/block', $block));
    }
  }
}
