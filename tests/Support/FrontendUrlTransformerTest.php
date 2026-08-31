<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Support;

use CloakWP\Decoupled\Contracts\FrontendResolver;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Support\FrontendUrlTransformer;
use CloakWP\Decoupled\Tests\WpStubs;
use PHPUnit\Framework\TestCase;

final class FrontendUrlTransformerTest extends TestCase
{
  protected function setUp(): void
  {
    WpStubs::reset();
  }

  private function resolver(string $url, array $deployments = []): FrontendResolver
  {
    return new class ($url, $deployments) implements FrontendResolver {
      public function __construct(
        private readonly string $url,
        private readonly array $deployments,
      ) {
      }

      public function getFrontends(): array
      {
        return [$this->getActiveFrontend()];
      }

      public function getActiveFrontend(): Frontend
      {
        return Frontend::make('website', $this->url)->deployments($this->deployments);
      }

      public function getFrontend(string $key): ?Frontend
      {
        return null;
      }
    };
  }

  public function testStripsActiveFrontendOrigin(): void
  {
    $transformer = new FrontendUrlTransformer($this->resolver('https://app.example.com'));

    $this->assertSame('/about', $transformer->makeRelative('https://app.example.com/about'));
    $this->assertSame('/', $transformer->makeRelative('https://app.example.com'));
  }

  public function testStripsDeploymentOrigins(): void
  {
    $transformer = new FrontendUrlTransformer($this->resolver('https://localhost:3000', [
      'https://preview.example.com',
    ]));

    $this->assertSame('/pricing', $transformer->makeRelative('https://preview.example.com/pricing'));
  }

  public function testLeavesExternalUrlsUnchanged(): void
  {
    $transformer = new FrontendUrlTransformer($this->resolver('https://app.example.com'));
    $external = 'https://other.example.com/page';

    $this->assertSame($external, $transformer->makeRelative($external));
  }

  public function testFilterCanAddExtraOrigins(): void
  {
    add_filter('cloakwp/relative_frontend_urls', static function (array $bases): array {
      $bases[] = 'https://prod.example.com';
      return $bases;
    });

    $transformer = new FrontendUrlTransformer($this->resolver('https://localhost:3000'));

    $this->assertSame('/team', $transformer->makeRelative('https://prod.example.com/team'));
  }
}
