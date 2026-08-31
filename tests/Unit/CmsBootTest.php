<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Support\HtmlEntityDecoder;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class CmsBootTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();
  }

  public function testSingletonSchedulesDelayedBoot(): void
  {
    $cms = CMS::getInstance();

    $this->assertFalse($cms->isBooted());
    $hooks = array_column(WpStubs::$actions, 'hook');
    $this->assertContains('init', $hooks);
  }

  public function testFrontendsConfiguredBeforeActiveLookup(): void
  {
    $cms = CMS::getInstance();
    $cms->frontends([
      Frontend::make('website', 'https://frontend.test'),
    ]);

    $this->assertSame('https://frontend.test', $cms->getActiveFrontend()->getUrl());
  }

  public function testInstancesAreScopedByMultisiteSiteId(): void
  {
    $siteOne = CMS::getInstance();
    $siteOne->frontends([
      Frontend::make('website', 'https://one.test'),
    ]);

    WpStubs::$siteId = 2;
    $siteTwo = CMS::getInstance();
    $siteTwo->frontends([
      Frontend::make('website', 'https://two.test'),
    ]);

    $this->assertNotSame($siteOne, $siteTwo);
    $this->assertSame('https://one.test', CMS::forSite(1)->getActiveFrontend()->getUrl());
    $this->assertSame('https://two.test', CMS::forSite(2)->getActiveFrontend()->getUrl());
  }

  public function testLongRunningJobsCanConfigureAChangedSiteBeforeBoot(): void
  {
    WpStubs::$didActions['init'] = 1;

    $cms = CMS::forSite(2, scheduleBoot: false);
    $this->assertFalse($cms->isBooted());

    $cms->frontends([
      Frontend::make('website', 'https://two.test'),
    ]);
    $cms->boot();

    $this->assertTrue($cms->isBooted());
  }
}

final class HtmlEntityDecoderTest extends TestCase
{
  public function testDecodesNestedProperties(): void
  {
    $decoder = new HtmlEntityDecoder();
    $data = ['title' => ['rendered' => 'Tom &amp; Jerry']];
    $decoder->decodeResponseData($data, ['title.rendered']);

    $this->assertSame('Tom & Jerry', $data['title']['rendered']);
  }
}
