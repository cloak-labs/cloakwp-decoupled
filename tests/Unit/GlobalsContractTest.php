<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Repositories\AcfGlobalsRepository;
use CloakWP\Decoupled\Repositories\EmptyGlobalsRepository;
use CloakWP\Decoupled\Rest\Handlers\GetGlobal;
use CloakWP\Decoupled\Rest\Handlers\ListGlobals;
use CloakWP\Decoupled\Services\GlobalsExposure;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class GlobalsContractTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
  }

  public function testListGlobalsAndOptionsAliasReturnIdenticalPayloads(): void
  {
    WpStubs::$acfOptions = [
      'alpha' => ['label' => 'one'],
      'beta' => ['items' => []],
      'secret' => 'hidden',
    ];
    $repository = new AcfGlobalsRepository();
    $exposure = new GlobalsExposure();
    $exposure->allow(['alpha', 'beta']);

    $globals = (new ListGlobals($repository, $exposure))();
    $options = (new ListGlobals($repository, $exposure))();

    $this->assertSame(200, $globals->get_status());
    $this->assertSame($globals->data, $options->data);
    $this->assertSame(['alpha', 'beta'], array_keys($globals->data));
    $this->assertArrayNotHasKey('secret', $globals->data);
  }

  public function testSingleGlobalAcceptsLegacyOptionSlug(): void
  {
    WpStubs::$acfOptions = ['alpha' => ['label' => 'one']];
    $repository = new AcfGlobalsRepository();
    $exposure = new GlobalsExposure();
    $exposure->allow(['alpha']);

    $modern = new \WP_REST_Request();
    $modern->set_param('global_slug', 'alpha');
    $legacy = new \WP_REST_Request();
    $legacy->set_param('option_slug', 'alpha');

    $handler = new GetGlobal($repository, $exposure);
    $this->assertSame($handler($modern)->data, $handler($legacy)->data);
  }

  public function testUnexposedGlobalsAreForbiddenNotEmpty(): void
  {
    WpStubs::$acfOptions = ['alpha' => ['label' => 'x']];
    $result = (new ListGlobals(new AcfGlobalsRepository(), new GlobalsExposure()))();

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('globals_not_exposed', $result->get_error_code());
    $this->assertSame(403, $result->data['status']);
  }

  public function testExposedEmptyRepositoryIsNotFoundNotEmptyObject(): void
  {
    $exposure = new GlobalsExposure();
    $exposure->allowAll();
    $result = (new ListGlobals(new EmptyGlobalsRepository(), $exposure))();

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('globals_not_found', $result->get_error_code());
    $this->assertSame(404, $result->data['status']);
  }

  public function testAllowAllPreservesNestedAcfShape(): void
  {
    WpStubs::$acfOptions = [
      'alpha' => [
        'title' => ['short' => 'Example', 'long' => ''],
        'flags' => ['enabled' => false],
      ],
      'beta' => ['missing' => null, 'nested' => ['mode' => 'off']],
    ];
    $exposure = new GlobalsExposure();
    $exposure->allowAll();

    $response = (new ListGlobals(new AcfGlobalsRepository(), $exposure))();

    $this->assertSame(WpStubs::$acfOptions, $response->data);
    $this->assertFalse($response->data['alpha']['flags']['enabled']);
    $this->assertNull($response->data['beta']['missing']);
  }

  public function testMissingSingleGlobalIsNotFound(): void
  {
    WpStubs::$acfOptions = ['alpha' => ['label' => 'x']];
    $exposure = new GlobalsExposure();
    $exposure->allow(['missing']);
    $request = new \WP_REST_Request();
    $request->set_param('global_slug', 'missing');

    $result = (new GetGlobal(new AcfGlobalsRepository(), $exposure))($request);

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('global_not_found', $result->get_error_code());
    $this->assertSame(404, $result->data['status']);
  }
}
