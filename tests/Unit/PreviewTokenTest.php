<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Services\PreviewToken;
use CloakWP\Decoupled\Services\PreviewUrlHandler;
use PHPUnit\Framework\TestCase;

final class PreviewTokenTest extends TestCase
{
  public function testIssuesSignedClaimsWithTwelveHourDefault(): void
  {
    $tokens = new PreviewToken(static fn(): string => 'test-secret', static fn(): int => 1000);
    $token = $tokens->issue('block_123', '/about');

    $this->assertSame([
      'previewKey' => 'block_123',
      'pathname' => '/about',
      'exp' => 44200,
    ], $tokens->verify($token));
    $this->assertStringNotContainsString('test-secret', $token);
  }

  public function testRejectsTamperedAndExpiredTokens(): void
  {
    $now = 1000;
    $tokens = new PreviewToken(
      static fn(): string => 'test-secret',
      static function () use (&$now): int {
        return $now;
      },
    );
    $token = $tokens->issue('block_123', '/', 60);

    $this->assertNull($tokens->verify($token . 'tampered'));
    $now = 1061;
    $this->assertNull($tokens->verify($token));
  }

  public function testBlockPreviewUrlContainsTokenWithoutRawSecret(): void
  {
    $tokens = new PreviewToken(static fn(): string => 'raw-secret', static fn(): int => 1000);
    $urls = new PreviewUrlHandler($tokens);
    $frontend = Frontend::make('web', 'https://web.test')
      ->blockPreviewPath('/preview-block');

    $url = $urls->forBlock($frontend, 'block_123', '/about');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $this->assertSame('https://web.test/preview-block', strtok($url, '?'));
    $this->assertSame(['token'], array_keys($query));
    $this->assertArrayHasKey('token', $query);
    $this->assertStringNotContainsString('raw-secret', $url);
    $payload = $tokens->verify($query['token']);
    $this->assertSame('block_123', $payload['previewKey']);
    $this->assertSame('/about', $payload['pathname']);
  }

  public function testBindsWpOriginIntoIssuedToken(): void
  {
    $tokens = new PreviewToken(static fn(): string => 'test-secret', static fn(): int => 1000);
    $token = $tokens->issue('block_123', '/about', 43200, 'https://wp.localhost');

    $this->assertSame('https://wp.localhost', $tokens->verify($token)['wpOrigin']);
  }
}
