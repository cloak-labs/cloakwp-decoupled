<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Styles\ContentStyleCatalog;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class ContentStyleCatalogTest extends TestCase
{
  private mixed $previousDatabase;
  protected function setUp(): void
  {
    WpStubs::reset();
    $this->previousDatabase = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new class {
      public string $options = 'wp_options';
      public mixed $beforeDelete = null;
      public function delete(string $table, array $where, array $formats): int
      {
        if ($this->beforeDelete) ($this->beforeDelete)();
        if (get_option($where['option_name']) !== $where['option_value']) return 0;
        delete_option($where['option_name']);
        return 1;
      }
    };
  }

  protected function tearDown(): void
  {
    $GLOBALS['wpdb'] = $this->previousDatabase;
  }

  public function testAcknowledgingAnOlderSaveCannotDeleteANewerPendingNotification(): void
  {
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/rebuild')->token('secret');
    $catalog->changed(100);
    $GLOBALS['wpdb']->beforeDelete = static function (): void {
      update_option('cloakwp_style_catalog_pending', 'newer-save', false);
    };
    $catalog->notify();
    $this->assertSame('newer-save', get_option('cloakwp_style_catalog_pending'));
  }

  public function testConfigurationIsOptInAndRegistrationIsIdempotent(): void
  {
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/rebuild')->token('test-secret');
    $this->assertSame([], WpStubs::$actions);
    $catalog->register();
    $registered = WpStubs::$actions;
    $catalog->register();
    $this->assertSame($registered, WpStubs::$actions);
    $this->assertSame(['rest_api_init', 'wp_after_insert_post', 'deleted_post', 'acf/save_post', 'shutdown', 'cloakwp_style_catalog_retry'], array_column($registered, 'hook'));
  }

  public function testRegistrationRequiresBothWorkerAndToken(): void
  {
    $this->expectException(\LogicException::class);
    ContentStyleCatalog::make()->workerUrl('http://worker.test/rebuild')->register();
  }

  public function testWorkerMustUseHttp(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    ContentStyleCatalog::make()->workerUrl('file:///tmp/worker');
  }

  public function testAuthorizationRejectsEmptyOrIncorrectTokens(): void
  {
    $this->assertFalse(ContentStyleCatalog::make()->authorized(''));
    $catalog = ContentStyleCatalog::make()->token('test-secret');
    $this->assertFalse($catalog->authorized(''));
    $this->assertFalse($catalog->authorized('wrong-secret'));
    $this->assertTrue($catalog->authorized('test-secret'));
  }

  public function testWorkerUrlCannotContainEmbeddedCredentials(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    ContentStyleCatalog::make()->workerUrl('https://user:password@worker.test/rebuild');
  }

  public function testAutosavesRevisionsAndOptionsDoNotNotifyWorker(): void
  {
    WpStubs::$revisionIds = [10];
    WpStubs::$autosaveIds = [11];
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/rebuild')->token('test-secret');
    foreach ([10, 11, 'options'] as $id) $catalog->changed($id);
    $catalog->notify();
    $this->assertSame([], WpStubs::$remoteRequests);
  }

  public function testSavesCoalesceIntoOneSiteScopedNotification(): void
  {
    WpStubs::$siteId = 38;
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/rebuild')->token('test-secret');
    $catalog->changed(100);
    $catalog->changed(101);
    $catalog->notify();
    $catalog->notify();
    $this->assertCount(1, WpStubs::$remoteRequests);
    $request = WpStubs::$remoteRequests[0];
    $this->assertSame('http://worker.test/rebuild', $request['url']);
    $this->assertSame('test-secret', $request['args']['headers']['X-Class-Catalog-Token']);
    $this->assertSame(['version' => 1, 'siteId' => 38, 'event' => 'content-changed'], json_decode($request['args']['body'], true));
  }

  public function testPublishingIsSeparatelyOptInAndDefersAutomaticIsr(): void
  {
    ContentStyleCatalog::make()->site('example')->workerUrl('http://worker.test/rebuild')->token('test')->register();
    $this->assertSame([], WpStubs::$filters);
    ContentStyleCatalog::make()->site('example')->publishing()->workerUrl('http://worker.test/rebuild')->token('test')->register();
    $this->assertSame('cloakwp/decoupled/revalidate_on_save', WpStubs::$filters[0]['hook']);
  }

  public function testFailedNotificationStaysInTheOutboxAndRetries(): void
  {
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/events')->token('secret')->notificationTimeout(5);
    WpStubs::$remoteResponses = [['response' => ['code' => 503], 'body' => '{}']];
    $catalog->changed(100);
    $catalog->notify();
    $this->assertNotFalse(get_option('cloakwp_style_catalog_pending', false));
    $this->assertNotFalse(wp_next_scheduled('cloakwp_style_catalog_retry'));
    $this->assertSame(5, WpStubs::$remoteRequests[0]['args']['timeout']);
    $catalog->retry();
    $this->assertFalse(get_option('cloakwp_style_catalog_pending', false));
    $this->assertCount(2, WpStubs::$remoteRequests);
    $catalog->retry();
    $this->assertCount(2, WpStubs::$remoteRequests);
  }

  public function testQueuedAcknowledgmentMustConfirmDurableAcceptance(): void
  {
    $catalog = ContentStyleCatalog::make()->workerUrl('http://worker.test/events')->token('secret');
    WpStubs::$remoteResponses = [['response' => ['code' => 200], 'body' => '{}'], ['response' => ['code' => 202], 'body' => '{"ok":true}']];
    $catalog->changed(100); $catalog->notify();
    $this->assertNotFalse(get_option('cloakwp_style_catalog_pending', false));
    $catalog->retry();
    $this->assertFalse(get_option('cloakwp_style_catalog_pending', false));
  }

  public function testRegisteredConfigurationCannotChange(): void
  {
    $catalog = ContentStyleCatalog::make()->site('example')->workerUrl('http://worker.test/rebuild')->token('secret')->register();
    $this->expectException(\LogicException::class);
    $catalog->token('changed');
  }

  public function testManifestIsScopedToSiteAndReleaseAndRejectsStaleContent(): void
  {
    $catalog = ContentStyleCatalog::make()->site('example')->publishing();
    $manifest = ['version' => 1, 'site' => 'example', 'release' => 'build-1', 'hash' => str_repeat('a', 64),
      'cssUrl' => 'https://assets.test/a.css', 'bytes' => 10, 'gzipBytes' => 30, 'contentRevision' => 'initial'];
    $this->assertTrue($catalog->publish($manifest)->data['ok']);
    $this->assertSame($manifest, $catalog->manifest('build-1')->data);
    $this->assertSame('no-store', $catalog->manifest('build-1')->headers['Cache-Control']);
    $this->assertInstanceOf(\WP_Error::class, $catalog->manifest('build-2'));
    WpStubs::$siteId = 2;
    $this->assertInstanceOf(\WP_Error::class, $catalog->manifest('build-1'));
    WpStubs::$siteId = 1;
    $catalog->changed(100);
    $this->assertSame('stale_styles', $catalog->publish($manifest)->code);
    $this->assertSame($manifest, $catalog->manifest('build-1')->data);
    $this->assertSame('invalid_styles', $catalog->publish([...$manifest, 'site' => 'other'])->code);
    $this->assertSame('invalid_styles', $catalog->publish([...$manifest, 'cssUrl' => 'https://user:password@assets.test/a.css'])->code);
    $this->assertSame('invalid_release', $catalog->manifest('../escape')->code);
    $this->assertSame('invalid_styles', ContentStyleCatalog::make()->site('example')->publish($manifest)->code);
  }

  public function testBulkInvalidationIsScopedIdempotentAndPreservesLaterEditorChanges(): void
  {
    $catalog = ContentStyleCatalog::make()->site('example')->publishing();
    $input = ['site' => 'example', 'siteId' => 1, 'operation' => 'copy-1'];
    $first = $catalog->invalidate($input)->data;
    $this->assertNotSame('initial', $first['contentRevision']);
    $this->assertSame($first, $catalog->invalidate($input)->data);
    $catalog->changed(100);
    $newer = $catalog->invalidate($input)->data;
    $this->assertNotSame($first['contentRevision'], $newer['contentRevision']);
    $this->assertSame(get_option('cloakwp_style_content_revision'), $newer['contentRevision']);
    $this->assertSame('invalid_publication', $catalog->invalidate([...$input, 'siteId' => 2])->code);
    $this->assertSame('invalid_publication', $catalog->invalidate([...$input, 'site' => 'other'])->code);
    $this->assertSame('invalid_publication', ContentStyleCatalog::make()->site('example')->invalidate($input)->code);
    $this->assertSame('invalid_publication', $catalog->invalidate([...$input, 'operation' => '../escape'])->code);
  }
}
