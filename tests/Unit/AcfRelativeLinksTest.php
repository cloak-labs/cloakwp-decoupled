<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\FrontendLinksProvider;
use CloakWP\Decoupled\Providers\RestApiProvider;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcfRelativeLinksTest extends TestCase
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
      if (!$provider instanceof FrontendLinksProvider && !$provider instanceof RestApiProvider) {
        $cms->removeProvider($provider::class);
      }
    }
    $cms->boot();
  }

  #[DataProvider('linkUrls')]
  public function testRestAndRawBlockLinksUseTheSameInternalBases(string $url, string $expected): void
  {
    $link = ['url' => $url, 'title' => 'Contact us', 'target' => '_blank'];
    $expectedLink = array_replace($link, ['url' => $expected]);
    $this->assertSame($expectedLink, apply_filters('acf/format_value/type=link', $link));
    $this->assertSame($expected, apply_filters('acf/format_value/type=link', $url));

    $block = ['name' => 'acf/cta', 'data' => ['cta' => $link, 'url_only' => $url]];
    $fields = [['name' => 'cta', 'type' => 'link'], ['name' => 'url_only', 'type' => 'link']];
    $result = apply_filters('cloakwp/block/data', $block, $fields);
    $this->assertSame($expectedLink, $result['data']['cta']);
    $this->assertSame($expected, $result['data']['url_only']);
  }

  public static function linkUrls(): iterable
  {
    yield 'active frontend' => ['https://staging.example.com/Contact/?a=1&b=2#form', '/Contact/?a=1&b=2#form'];
    yield 'deployment' => ['https://preview.example.com/contact', '/contact'];
    yield 'wordpress' => ['https://wp.example.test/contact', '/contact'];
    yield 'homepage query' => ['https://staging.example.com?search=foo#results', '/?search=foo#results'];
    yield 'external' => ['https://external.example/contact', 'https://external.example/contact'];
    yield 'similar hostname' => ['https://staging.example.com.external.test/contact', 'https://staging.example.com.external.test/contact'];
    yield 'relative' => ['/contact#form', '/contact#form'];
    yield 'email' => ['mailto:hello@example.com', 'mailto:hello@example.com'];
    yield 'phone' => ['tel:+16045551234', 'tel:+16045551234'];
    yield 'empty' => ['', ''];
  }

  public function testNestedBlockLinksAreNormalizedByFieldType(): void
  {
    $link = ['url' => 'https://staging.example.com/contact', 'title' => 'Contact', 'target' => '_blank'];
    $relativeLink = array_replace($link, ['url' => '/contact']);
    $fields = [
      ['name' => 'group', 'type' => 'group', 'sub_fields' => [
        ['name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
          ['name' => 'cta', 'type' => 'link'],
          ['name' => 'image', 'type' => 'image'],
        ]],
      ]],
      ['name' => 'sections', 'type' => 'flexible_content', 'layouts' => [
        'layout_cta' => ['name' => 'cta', 'sub_fields' => [['name' => 'link', 'type' => 'link']]],
        'layout_media' => ['name' => 'media', 'sub_fields' => [['name' => 'link', 'type' => 'file']]],
      ]],
      ['name' => 'plain_url', 'type' => 'url'],
    ];
    $block = ['name' => 'acf/sections', 'data' => [
      'group' => ['rows' => [2 => ['cta' => $link, 'image' => $link]]],
      'sections' => [
        ['acf_fc_layout' => 'cta', 'link' => $link],
        ['acf_fc_layout' => 'media', 'link' => $link],
        ['acf_fc_layout' => 'unknown', 'link' => $link],
      ],
      'plain_url' => $link['url'],
      'unregistered' => $link,
    ]];
    $expected = $block;
    $expected['data']['group']['rows'][2]['cta'] = $relativeLink;
    $expected['data']['sections'][0]['link'] = $relativeLink;

    $this->assertSame($expected, apply_filters('cloakwp/block/data', $block, $fields));
  }

  public function testEmptyValuesAndBlocksWithoutDataArePreserved(): void
  {
    foreach ([false, null, [], ['url' => null], ['title' => 'No URL']] as $value) {
      $this->assertSame($value, apply_filters('acf/format_value/type=link', $value));
    }
    $block = ['name' => 'acf/empty', 'attrs' => []];
    $this->assertSame($block, apply_filters('cloakwp/block/data', $block, []));
  }

  public function testSharedThemeLinkGroupNormalizesItsInternalPageLink(): void
  {
    // Theme\Fields\Link extends Group: its internal field is PageLink, not Link.
    $fields = [['name' => 'services_cta_link', 'type' => 'group', 'sub_fields' => [
      ['name' => 'type', 'type' => 'button_group'],
      ['name' => 'internal', 'type' => 'page_link'],
      ['name' => 'custom', 'type' => 'text'],
      ['name' => 'target', 'type' => 'true_false'],
    ]]];
    $block = ['name' => 'acf/home-services-portfolio', 'data' => ['services_cta_link' => [
      'type' => 'internal',
      'internal' => 'https://staging.example.com/services/',
      'custom' => 'https://external.example/services',
      'target' => false,
    ]]];
    $expected = $block;
    $expected['data']['services_cta_link']['internal'] = '/services/';

    $this->assertSame($expected, apply_filters('cloakwp/block/data', $block, $fields));
    $this->assertSame('/services/', apply_filters('acf/format_value/type=page_link', $block['data']['services_cta_link']['internal']));
  }

  public function testMultiplePageLinksPreserveExternalUrlsAndArrayKeys(): void
  {
    $links = [
      2 => 'https://staging.example.com/services/?from=cta#details',
      5 => 'https://wp.example.test/contact/',
      8 => 'https://external.example/page',
    ];
    $expected = [2 => '/services/?from=cta#details', 5 => '/contact/', 8 => $links[8]];
    $this->assertSame($expected, apply_filters('acf/format_value/type=page_link', $links));
    $block = ['data' => ['pages' => $links]];
    $result = apply_filters('cloakwp/block/data', $block, [['name' => 'pages', 'type' => 'page_link']]);
    $this->assertSame($expected, $result['data']['pages']);
  }
}
