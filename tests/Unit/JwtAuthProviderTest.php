<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\Auth\JwtAuthProvider;
use PHPUnit\Framework\TestCase;

final class JwtAuthStub
{
  public function __construct(
    private mixed $payload,
    private bool $error,
  ) {
  }

  public function validate_token(bool $returnResponse = true): mixed
  {
    return $this->payload;
  }

  public function is_error_response(mixed $payload): bool
  {
    return $this->error;
  }
}

final class JwtErrorResponseStub
{
  public function get_data(): array
  {
    return [
      'code' => 'jwt_auth_no_auth_header',
      'message' => 'Authorization header not found.',
    ];
  }

  public function get_status(): int
  {
    return 401;
  }
}

final class JwtAuthProviderTest extends TestCase
{
  public function testValidJwtPayloadAuthorizesTheRequest(): void
  {
    $provider = new JwtAuthProvider(
      authFactory: static fn(): object => new JwtAuthStub(
        (object) ['sub' => 42],
        false,
      ),
    );

    $this->assertTrue($provider->authorize());
  }

  public function testJwtErrorResponsesBecomePermissionErrors(): void
  {
    $provider = new JwtAuthProvider(
      authFactory: static fn(): object => new JwtAuthStub(
        new JwtErrorResponseStub(),
        true,
      ),
    );

    $result = $provider->authorize();

    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('jwt_auth_no_auth_header', $result->code);
    $this->assertSame(['status' => 401], $result->data);
  }
}
