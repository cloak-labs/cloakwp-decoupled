<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Auth\JwtAuthProvider;
use CloakWP\Decoupled\Auth\NoAuthProvider;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Contracts\MenuRepository;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\CorsProvider;
use CloakWP\Decoupled\Providers\MaintenanceProvider;
use CloakWP\Decoupled\Providers\RestEndpointsProvider;
use CloakWP\Decoupled\Repositories\AcfGlobalsRepository;
use CloakWP\Decoupled\Rest\Handlers\GetGlobal;
use CloakWP\Decoupled\Rest\Handlers\ListGlobals;
use CloakWP\Decoupled\Rest\Handlers\ListMenus;
use CloakWP\Decoupled\Services\GlobalsExposure;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\TestCase;

final class RestHardeningTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();
  }

  public function testCoreRestApiRegistersExistingContractsWithInvokableHandlers(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::REST))
      ->frontends([Frontend::make('web', 'https://web.test')]);
    foreach ($cms->providers() as $provider) {
      if (!$provider instanceof RestEndpointsProvider) {
        $cms->removeProvider($provider::class);
      }
    }

    $cms->boot();
    WpStubs::runAction('rest_api_init');

    $this->assertCount(12, WpStubs::$restRoutes);
    $this->assertSame(['cloakwp'], array_values(array_unique(array_column(WpStubs::$restRoutes, 'namespace'))));
    $this->assertSame([
      '/menus',
      '/menus/(?P<menu_slug>[a-zA-Z0-9-]+)',
      '/frontpage',
      '/globals',
      '/globals/(?P<global_slug>[a-zA-Z0-9_-]+)',
      '/auth/authorize',
      '/auth/establish-session',
      '/auth/establish-logout',
      '/auth/logout',
      '/auth/generate',
      '/image-library',
      '/image-library/filters',
    ], array_column(WpStubs::$restRoutes, 'path'));
    foreach (WpStubs::$restRoutes as $route) {
      $this->assertTrue(is_callable($route['definition']['callback']));
    }
    $this->assertIsCallable(WpStubs::$restRoutes[5]['definition']['permission_callback']);
  }

  public function testSingleGlobalEndpointReturnsExplicitlyExposedValue(): void
  {
    WpStubs::$acfOptions = ['phone' => '250-555-0100', 'private_key' => 'hidden'];
    $repository = new AcfGlobalsRepository();
    $exposure = new GlobalsExposure();
    $exposure->allow(['phone']);
    $request = new \WP_REST_Request();
    $request->set_param('global_slug', 'phone');

    $single = (new GetGlobal($repository, $exposure))($request);
    $all = (new ListGlobals($repository, $exposure))();

    $this->assertSame('250-555-0100', $single->data);
    $this->assertSame(['phone' => '250-555-0100'], $all->data);
  }

  public function testGlobalsDefaultToDenied(): void
  {
    WpStubs::$acfOptions = ['phone' => '250-555-0100'];
    $result = (new ListGlobals(new AcfGlobalsRepository(), new GlobalsExposure()))();

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame(403, $result->data['status']);
  }

  public function testSingleGlobalCanReturnLegitimateFalseValue(): void
  {
    WpStubs::$acfOptions = ['announcement_enabled' => false];
    $repository = new AcfGlobalsRepository();
    $exposure = new GlobalsExposure();
    $exposure->allow(['announcement_enabled']);
    $request = new \WP_REST_Request();
    $request->set_param('global_slug', 'announcement_enabled');

    $response = (new GetGlobal($repository, $exposure))($request);

    $this->assertFalse($response->data);
  }

  public function testMenuLocationPreservesSingleObjectContract(): void
  {
    $repository = new class implements MenuRepository {
      public function all(): array
      {
        return [['slug' => 'main']];
      }

      public function atLocation(string $location): ?array
      {
        return $location === 'header' ? ['slug' => 'main'] : null;
      }

      public function findBySlug(string $slug): ?array
      {
        return null;
      }
    };
    $request = new \WP_REST_Request();
    $request->set_param('location', 'header');

    $response = (new ListMenus($repository))($request);

    $this->assertSame(['slug' => 'main'], $response->data);
  }

  public function testAuthenticationIsOptionalAndJwtFailsClearlyWhenMissing(): void
  {
    $this->assertTrue((new NoAuthProvider())->authorize());

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('cloakwp/jwt-auth');
    (new JwtAuthProvider())->register();
  }

  public function testCorsAllowsOnlyExactConfiguredOrigins(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::CORE))
      ->frontends([
        Frontend::make('web', 'https://web.test/path')
          ->deployments(['https://preview.test:8443/deployment']),
      ]);

    $this->assertSame(
      ['https://web.test', 'https://preview.test:8443'],
      (new CorsProvider())->allowedOrigins($cms),
    );
  }

  public function testRestMaintenanceLockdownHasNoPublicQueryBypass(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::REST))
      ->frontends([Frontend::make('web', 'https://web.test')])
      ->enableRestMaintenanceLockdown();
    (new MaintenanceProvider())->boot($cms);
    $_GET['bypass'] = '1';

    $result = WpStubs::applyFilters(
      'rest_pre_dispatch',
      null,
      null,
      new \WP_REST_Request(),
    );

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame(503, $result->data['status']);
  }
}
