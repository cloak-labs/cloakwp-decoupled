<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\ServiceProvider;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();
  }

  public function testConfigurationStoresWithoutRegisteringBehavior(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::CORE));
    $scheduledActions = count(WpStubs::$actions);
    $asset = new \stdClass();
    $block = new \stdClass();

    $cms
      ->frontends([Frontend::make('web', 'https://web.test')])
      ->assets([$asset])
      ->blocks([$block])
      ->allowedCoreBlocks(['core/paragraph']);

    $this->assertFalse($cms->isRegistered());
    $this->assertFalse($cms->isBooted());
    $this->assertSame($scheduledActions, count(WpStubs::$actions));
    $this->assertSame([$asset], $cms->configuredAssets());
    $this->assertSame([$block], $cms->configuredBlocks());
  }

  public function testAllProvidersRegisterBeforeAnyProviderBoots(): void
  {
    $log = [];
    $cms = $this->bareCms()
      ->withProviders([
        new ProviderAlpha($log),
        new ProviderBeta($log),
      ]);

    $cms->boot();

    $this->assertSame([
      'register:alpha',
      'register:beta',
      'boot:alpha',
      'boot:beta',
    ], $log);
  }

  public function testProviderReplacementRemovalAndReordering(): void
  {
    $log = [];
    $cms = $this->bareCms()
      ->withProviders([
        new ProviderAlpha($log),
        new ProviderBeta($log),
        new ProviderGamma($log),
      ])
      ->moveProviderBefore(ProviderGamma::class, ProviderAlpha::class)
      ->replaceProvider(ProviderBeta::class, new ProviderDelta($log))
      ->removeProvider(ProviderAlpha::class);

    $this->assertSame(
      [ProviderGamma::class, ProviderDelta::class],
      array_map('get_class', $cms->providers()),
    );
  }

  public function testConfigurationAndFrontendFreezeAfterBoot(): void
  {
    $cms = $this->bareCms();
    $frontend = $cms->getActiveFrontend();
    $cms->boot();

    try {
      $cms->exposeAllGlobals();
      $this->fail('CMS configuration should be frozen.');
    } catch (\LogicException $error) {
      $this->assertStringContainsString('frozen', $error->getMessage());
    }

    $this->expectException(\LogicException::class);
    $frontend->deployments(['https://preview.test']);
  }

  public function testMissingFrontendFailsClearlyAtRegistration(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::CORE));

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('requires at least one frontend');
    $cms->register();
  }

  public function testGlobalsDefaultToAnEmptyRepository(): void
  {
    $this->assertInstanceOf(
      \CloakWP\Decoupled\Repositories\EmptyGlobalsRepository::class,
      $this->bareCms()->globalsRepository(),
    );
  }

  public function testEveryProviderReceivesInjectedContextInstance(): void
  {
    $log = [];
    $context = WpContext::new()->force(WpContext::CORE);
    $cms = $this->bareCms($context)
      ->withProviders([new ContextRecordingProvider($log)]);

    $cms->boot();

    $this->assertSame(
      [spl_object_id($context), spl_object_id($context)],
      $log,
    );
  }

  private function bareCms(?WpContext $context = null): CMS
  {
    $cms = CMS::getInstance($context ?? WpContext::new()->force(WpContext::CORE));
    $cms->frontends([Frontend::make('web', 'https://web.test')]);
    foreach ($cms->providers() as $provider) {
      $cms->removeProvider($provider::class);
    }

    return $cms;
  }
}

abstract class RecordingProvider implements ServiceProvider
{
  protected array $log;

  public function __construct(
    array &$log,
    private readonly string $name,
  ) {
    $this->log = &$log;
  }

  public function register(CMS $cms): void
  {
    $this->log[] = 'register:' . $this->name;
  }

  public function boot(CMS $cms): void
  {
    $this->log[] = 'boot:' . $this->name;
  }
}

final class ProviderAlpha extends RecordingProvider
{
  public function __construct(array &$log)
  {
    parent::__construct($log, 'alpha');
  }
}

final class ProviderBeta extends RecordingProvider
{
  public function __construct(array &$log)
  {
    parent::__construct($log, 'beta');
  }
}

final class ProviderGamma extends RecordingProvider
{
  public function __construct(array &$log)
  {
    parent::__construct($log, 'gamma');
  }
}

final class ProviderDelta extends RecordingProvider
{
  public function __construct(array &$log)
  {
    parent::__construct($log, 'delta');
  }
}

final class ContextRecordingProvider implements ServiceProvider
{
  private array $log;

  public function __construct(array &$log)
  {
    $this->log = &$log;
  }

  public function register(CMS $cms): void
  {
    $this->log[] = spl_object_id($cms->context());
  }

  public function boot(CMS $cms): void
  {
    $this->log[] = spl_object_id($cms->context());
  }
}
