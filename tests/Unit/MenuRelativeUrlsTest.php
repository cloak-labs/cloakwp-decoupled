<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\FrontendLinksProvider;
use CloakWP\Decoupled\Repositories\NativeMenuRepository;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\TestCase;

final class MenuRelativeUrlsTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();
  }

  public function testRepositoryEmitsRelativePathsForInternalMenuLinks(): void
  {
    $cms = CMS::getInstance()
      ->frontends([
        Frontend::make('website', 'https://staging.example.com')
          ->deployments(['https://preview.example.com']),
      ]);

    WpStubs::$navMenus[12] = (object) [
      'term_id' => 12,
      'name' => 'Header Menu',
      'slug' => 'header-menu',
      'term_group' => 0,
      'term_taxonomy_id' => 12,
      'count' => 4,
    ];
    WpStubs::$navMenuLocations = ['header_menu' => 12];
    WpStubs::$navMenuItems[12] = [
      $this->menuItem(1, 'https://staging.example.com/about/', 'About'),
      $this->menuItem(2, 'https://preview.example.com/work', 'Work'),
      $this->menuItem(3, 'https://wp.example.test/contact/', 'Contact', 'custom'),
      $this->menuItem(4, 'https://other.example.com/careers', 'Careers', 'custom'),
    ];

    $menu = (new NativeMenuRepository($cms->frontendUrls()))->findBySlug('header-menu');

    $this->assertNotNull($menu);
    $urls = array_column($menu['menu_items'], 'url');
    $this->assertSame([
      '/about/',
      '/work',
      '/contact/',
      'https://other.example.com/careers',
    ], $urls);
  }

  public function testRestBootRelativizesMenuItemFilterForPreviewHosts(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::REST))
      ->frontends([Frontend::make('website', 'https://staging.example.com')]);
    foreach ($cms->providers() as $provider) {
      if (!$provider instanceof FrontendLinksProvider) {
        $cms->removeProvider($provider::class);
      }
    }
    $cms->boot();

    $meta = WpStubs::applyFilters(
      'cloakwp/decoupled/menu_item/formatted_meta',
      [
        'url' => 'https://staging.example.com/team',
        'link_type' => 'custom',
      ],
      (object) [],
    );

    $this->assertSame('/team', $meta['url']);
  }

  private function menuItem(
    int $id,
    string $url,
    string $title,
    string $type = 'post_type',
  ): object {
    return (object) [
      'ID' => $id,
      'object_id' => (string) $id,
      'object' => 'page',
      'menu_item_parent' => '0',
      'url' => $url,
      'title' => $title,
      'target' => '',
      'attr_title' => '',
      'description' => '',
      'classes' => [],
      'menu_order' => $id,
      'type' => $type,
    ];
  }
}
