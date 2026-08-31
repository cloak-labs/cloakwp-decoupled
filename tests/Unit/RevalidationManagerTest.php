<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Services\MaintenanceState;
use CloakWP\Decoupled\Services\RevalidationManager;
use CloakWP\Decoupled\Tests\Support\FrontendResolverStub;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class RevalidationManagerTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
  }

  public function testBatchesUniquePathsOncePerUniqueDeployment(): void
  {
    $frontend = Frontend::make('web', 'https://web.test')
      ->authSecret('signing-secret')
      ->deployments(['https://preview.test/', 'https://preview.test']);
    $manager = $this->manager($frontend);

    $manager->revalidate($frontend, [
      '/about',
      'about/',
      'https://ignored-host.test/contact?draft=1',
    ]);

    $this->assertCount(2, WpStubs::$remoteRequests);
    foreach (WpStubs::$remoteRequests as $request) {
      $body = $request['args']['body'];
      $decoded = json_decode($body, true);
      $this->assertSame(['/about', '/contact'], $decoded['paths']);
      $this->assertTrue($request['args']['blocking']);
      $this->assertSame(5, $request['args']['timeout']);
      $this->assertSame('1000', $request['args']['headers']['X-CloakWP-Timestamp']);
      $this->assertSame(
        'sha256=' . hash_hmac('sha256', '1000.' . $body, 'signing-secret'),
        $request['args']['headers']['X-CloakWP-Signature'],
      );
      $this->assertStringNotContainsString('signing-secret', $request['url']);
      $this->assertStringNotContainsString('signing-secret', $body);
    }
  }

  public function testFailurePersistsPerSiteQueueAndSchedulesCron(): void
  {
    $frontend = Frontend::make('web', 'https://web.test')->authSecret('secret');
    $manager = $this->manager($frontend);
    WpStubs::$remoteResponses[] = new \WP_Error('network', 'offline');

    $manager->revalidate($frontend, ['/about']);

    $queue = WpStubs::$optionsBySite[1]['cloakwp_decoupled_revalidation_queue'];
    $this->assertCount(1, $queue);
    $this->assertSame(['/about'], array_values($queue)[0]['paths']);
    $this->assertArrayHasKey(RevalidationManager::CRON_HOOK, WpStubs::$scheduledEvents);
    $this->assertArrayNotHasKey(2, WpStubs::$optionsBySite);
  }

  public function testRetryQueueClearsAfterVerifiedSuccess(): void
  {
    $now = 1000;
    $frontend = Frontend::make('web', 'https://web.test')->authSecret('secret');
    $manager = $this->manager($frontend, new MaintenanceState(), $now);
    WpStubs::$remoteResponses[] = ['response' => ['code' => 503], 'body' => '{"ok":false}'];
    $manager->revalidate($frontend, ['/about']);

    $now = 1061;
    WpStubs::$remoteResponses[] = ['response' => ['code' => 200], 'body' => '{"ok":true}'];
    $manager->processRetryQueue();

    $this->assertArrayNotHasKey(
      'cloakwp_decoupled_revalidation_queue',
      WpStubs::$optionsBySite[1],
    );
    $this->assertCount(2, WpStubs::$remoteRequests);
  }

  public function testQueuesAreIsolatedByMultisiteSiteOptions(): void
  {
    $frontend = Frontend::make('web', 'https://web.test')->authSecret('secret');
    $manager = $this->manager($frontend);

    WpStubs::$remoteResponses[] = new \WP_Error('network', 'site one offline');
    $manager->revalidate($frontend, ['/one']);

    WpStubs::$siteId = 2;
    WpStubs::$remoteResponses[] = new \WP_Error('network', 'site two offline');
    $manager->revalidate($frontend, ['/two']);

    $siteOne = array_values(WpStubs::$optionsBySite[1]['cloakwp_decoupled_revalidation_queue'])[0];
    $siteTwo = array_values(WpStubs::$optionsBySite[2]['cloakwp_decoupled_revalidation_queue'])[0];
    $this->assertSame(['/one'], $siteOne['paths']);
    $this->assertSame(['/two'], $siteTwo['paths']);
  }

  public function testPausedOutboundRevalidationDoesNotLockRestOrSend(): void
  {
    $maintenance = new MaintenanceState();
    $maintenance->pauseRevalidation(true);
    $frontend = Frontend::make('web', 'https://web.test')->authSecret('secret');
    $manager = $this->manager($frontend, $maintenance);

    $manager->revalidate($frontend, ['/about']);

    $this->assertSame([], WpStubs::$remoteRequests);
    $this->assertFalse($maintenance->isRestApiLocked());
  }

  public function testPausedWorkerDoesNotRescheduleAnEmptyQueue(): void
  {
    $maintenance = new MaintenanceState();
    $maintenance->pauseRevalidation(true);
    $frontend = Frontend::make('web', 'https://web.test')->authSecret('secret');

    $this->manager($frontend, $maintenance)->processRetryQueue();

    $this->assertSame([], WpStubs::$scheduledEvents);
  }

  public function testMultiplePathsAlwaysUseOneBatchedRequest(): void
  {
    $frontend = Frontend::make('web', 'https://web.test')
      ->authSecret('secret');
    $manager = $this->manager($frontend);

    $manager->revalidate($frontend, ['/one', '/two']);

    $this->assertCount(1, WpStubs::$remoteRequests);
    $this->assertSame(
      ['/one', '/two'],
      json_decode(WpStubs::$remoteRequests[0]['args']['body'], true)['paths'],
    );
  }

  public function testDefaultSaveHookGuardsRevisionsAndAutosaves(): void
  {
    $frontend = Frontend::make('web', 'https://web.test')
      ->authSecret('secret')
      ->revalidateEntriesOnSave();
    $manager = $this->manager($frontend);
    $manager->boot();
    WpStubs::$revisionIds = [10];
    WpStubs::$autosaveIds = [11];

    WpStubs::runAction('save_post', 10, (object) ['post_status' => 'publish'], true);
    WpStubs::runAction('save_post', 11, (object) ['post_status' => 'publish'], true);

    $this->assertSame([], WpStubs::$remoteRequests);
  }

  private function manager(
    Frontend $frontend,
    ?MaintenanceState $maintenance = null,
    int &$now = 1000,
  ): RevalidationManager {
    return new RevalidationManager(
      new FrontendResolverStub([$frontend]),
      $maintenance ?? new MaintenanceState(),
      null,
      static function () use (&$now): int {
        return $now;
      },
    );
  }
}
