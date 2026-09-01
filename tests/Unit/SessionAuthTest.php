<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests\Unit;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Frontend;
use CloakWP\Decoupled\Providers\RestEndpointsProvider;
use CloakWP\Decoupled\Providers\SessionAuthProvider;
use CloakWP\Decoupled\Services\SessionManager;
use CloakWP\Decoupled\Services\SessionTokenCodec;
use CloakWP\Decoupled\Tests\Support\InMemorySessionStore;
use CloakWP\Decoupled\Tests\WpStubs;
use Inpsyde\WpContext;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class SessionAuthTest extends TestCase
{
  private int $now = 1_700_000_000;

  protected function setUp(): void
  {
    WpStubs::reset();
    CMS::resetInstance();
    $this->now = 1_700_000_000;
    WpStubs::$users[1] = new WP_User(1, 'editor', 'Editor');
    WpStubs::$passwords['editor'] = 'secret';
  }

  public function testPasswordGrantSucceedsAndInvokesWpAuthenticate(): void
  {
    $authenticated = [];
    add_filter('authenticate', function ($user, $username, $password) use (&$authenticated) {
      $authenticated[] = [$username, $password];
      return $user;
    }, 10, 3);

    $response = $this->manager()->authorize($this->authorizeRequest());

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame([['editor', 'secret']], $authenticated);
    $this->assertSame(1, $response->data['user']['id']);
    $this->assertNotSame('', $response->data['accessToken']);
    $this->assertNotSame('', $response->data['refreshToken']);
    $this->assertNotSame('', $response->data['wpLoginCode']);
    $this->assertSame(1, WpStubs::$didActions['wp_login'] ?? 0);
  }

  public function testPasswordGrantRejectsBadPassword(): void
  {
    $request = $this->authorizeRequest();
    $request->set_param('password', 'nope');
    $result = $this->manager()->authorize($request);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(401, $result->data['status']);
  }

  public function testPasswordGrantRejectsMissingSecret(): void
  {
    $request = $this->authorizeRequest();
    $request->set_header('X-CloakWP-Secret', '');
    $result = $this->manager()->authorize($request);

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('invalid_secret', $result->code);
    $this->assertSame(401, $result->data['status']);
  }

  public function testRefreshRotatesAndRejectsReplay(): void
  {
    $manager = $this->manager();
    $issued = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    $refresh = new WP_REST_Request('POST');
    $refresh->set_header('X-CloakWP-Secret', 'session-secret');
    $refresh->set_param('grant_type', 'refresh_token');
    $refresh->set_param('refresh_token', $issued->data['refreshToken']);
    $rotated = $manager->authorize($refresh);

    $this->assertInstanceOf(WP_REST_Response::class, $rotated);
    $this->assertNotSame($issued->data['refreshToken'], $rotated->data['refreshToken']);

    $replay = $manager->authorize($refresh);
    $this->assertInstanceOf(WP_Error::class, $replay);
    $this->assertSame(401, $replay->data['status']);
  }

  public function testLogoutRevokesRefreshToken(): void
  {
    $manager = $this->manager();
    $issued = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    $logout = new WP_REST_Request('POST');
    $logout->set_header('X-CloakWP-Secret', 'session-secret');
    $logout->set_param('refresh_token', $issued->data['refreshToken']);
    $revoked = $manager->logout($logout);
    $this->assertInstanceOf(WP_REST_Response::class, $revoked);
    $this->assertNotSame('', $revoked->data['wpLogoutCode']);

    $refresh = new WP_REST_Request('POST');
    $refresh->set_header('X-CloakWP-Secret', 'session-secret');
    $refresh->set_param('grant_type', 'refresh_token');
    $refresh->set_param('refresh_token', $issued->data['refreshToken']);
    $replay = $manager->authorize($refresh);
    $this->assertInstanceOf(WP_Error::class, $replay);
    $this->assertSame(401, $replay->data['status']);
  }

  public function testAuthorizationCodeRejectsWhenNoIssuerExists(): void
  {
    $request = new WP_REST_Request('POST');
    $request->set_header('X-CloakWP-Secret', 'session-secret');
    $request->set_param('grant_type', 'authorization_code');
    $request->set_param('code', 'anything');

    $result = $this->manager()->authorize($request);
    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('authorization_code_unavailable', $result->code);
    $this->assertSame(501, $result->data['status']);
  }

  public function testAuthorizationCodeRejectsInvalidCodeWhenIssuerExists(): void
  {
    $manager = $this->manager(static fn(string $code): mixed => $code === 'good' ? 1 : null);
    $request = new WP_REST_Request('POST');
    $request->set_header('X-CloakWP-Secret', 'session-secret');
    $request->set_param('grant_type', 'authorization_code');
    $request->set_param('code', 'bad');

    $result = $manager->authorize($request);
    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame('invalid_grant', $result->code);
    $this->assertSame(401, $result->data['status']);
  }

  public function testEstablishSessionSetsAuthCookieAndRedirects(): void
  {
    $manager = $this->manager();
    $issued = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    $request = new WP_REST_Request('GET');
    $request->set_param('code', $issued->data['wpLoginCode']);
    $request->set_param('redirect', 'https://web.test/after');
    $response = $manager->establishSession($request);

    $this->assertInstanceOf(WP_REST_Response::class, $response);
    $this->assertSame(302, $response->status);
    $this->assertSame('https://web.test/after', $response->headers['Location']);
    $this->assertSame([['userId' => 1, 'remember' => true]], WpStubs::$authCookies);
  }

  public function testEstablishSessionRejectsExpiredReplayAndWrongOrigin(): void
  {
    $manager = $this->manager();
    $issued = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    $wrongOrigin = new WP_REST_Request('GET');
    $wrongOrigin->set_param('code', $issued->data['wpLoginCode']);
    $wrongOrigin->set_param('redirect', 'https://evil.test/phish');
    $denied = $manager->establishSession($wrongOrigin);
    $this->assertInstanceOf(WP_Error::class, $denied);
    $this->assertSame(401, $denied->data['status']);

    $this->now += SessionManager::HANDSHAKE_TTL + 1;
    $expired = new WP_REST_Request('GET');
    $expired->set_param('code', $issued->data['wpLoginCode']);
    $expired->set_param('redirect', 'https://web.test/after');
    $timeout = $manager->establishSession($expired);
    $this->assertInstanceOf(WP_Error::class, $timeout);
    $this->assertSame(401, $timeout->data['status']);

    $this->now = 1_700_000_000;
    $fresh = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $fresh);
    $ok = new WP_REST_Request('GET');
    $ok->set_param('code', $fresh->data['wpLoginCode']);
    $ok->set_param('redirect', 'https://web.test/after');
    $this->assertInstanceOf(WP_REST_Response::class, $manager->establishSession($ok));

    $replay = $manager->establishSession($ok);
    $this->assertInstanceOf(WP_Error::class, $replay);
    $this->assertSame(401, $replay->data['status']);
  }

  public function testWpAdminRedirectsAllowSubdirectoryMultisite(): void
  {
    $manager = $this->manager();
    $this->assertTrue($manager->isAllowedRedirect('https://wp.example.test/wp-admin/'));
    $this->assertTrue($manager->isAllowedRedirect('https://wp.example.test/hyland02/wp-admin/post.php?post=1&action=edit'));
    $this->assertFalse($manager->isAllowedRedirect('https://wp.example.test/hyland02/not-admin'));
    $this->assertTrue($manager->isAllowedRedirect('https://web.test/after'));
  }

  public function testHandshakeAllowsHttpsLocalhostFrontends(): void
  {
    $manager = $this->manager();
    $this->assertTrue($manager->isAllowedRedirect('https://hyland02.localhost/'));
    $this->assertTrue($manager->isAllowedRedirect('https://hyland02.localhost/portfolio'));
    $this->assertTrue($manager->isAllowedRedirect('https://localhost/'));
    $this->assertFalse($manager->isAllowedRedirect('http://hyland02.localhost/'));
    $this->assertFalse($manager->isAllowedRedirect('https://hyland02.localhost.evil.test/'));
    $this->assertFalse($manager->isAllowedRedirect('https://evil.test/phish'));
  }

  public function testWpLogoutRevokesRefreshTokens(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::LOGIN))
      ->frontends([
        Frontend::make('web', 'https://web.test')->authSecret('session-secret'),
      ]);
    $manager = $this->manager();
    $cms->useSession($manager);
    (new SessionAuthProvider())->boot($cms);

    $issued = $manager->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    WpStubs::runAction('wp_logout', 1);

    $refresh = new WP_REST_Request('POST');
    $refresh->set_header('X-CloakWP-Secret', 'session-secret');
    $refresh->set_param('grant_type', 'refresh_token');
    $refresh->set_param('refresh_token', $issued->data['refreshToken']);
    $replay = $manager->authorize($refresh);
    $this->assertInstanceOf(WP_Error::class, $replay);
    $this->assertSame(401, $replay->data['status']);
  }

  public function testWpLogoutRedirectsToFrontendLogoutPage(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::LOGIN))
      ->frontends([Frontend::make('web', 'https://web.test')]);
    $cms->useSession($this->manager());
    (new SessionAuthProvider())->boot($cms);

    $redirect = WpStubs::applyFilters(
      'logout_redirect',
      'https://wp.example.test/wp-login.php?loggedout=true',
    );
    $this->assertSame('https://web.test/api/cloakwp/auth/logout', $redirect);
  }

  public function testFrontendHostsAreAllowedForWpSafeRedirect(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::LOGIN))
      ->frontends([Frontend::make('web', 'https://hyland02.localhost/')]);
    $cms->useSession($this->manager());
    (new SessionAuthProvider())->boot($cms);

    $hosts = WpStubs::applyFilters('allowed_redirect_hosts', ['wp.localhost']);
    $this->assertContains('wp.localhost', $hosts);
    $this->assertContains('hyland02.localhost', $hosts);
  }

  public function testDetermineCurrentUserAcceptsAccessTokenThenJwtAuth(): void
  {
    $cms = CMS::getInstance(WpContext::new()->force(WpContext::REST))
      ->frontends([
        Frontend::make('web', 'https://web.test')->authSecret('session-secret'),
      ]);
    $cms->useSession($this->manager());
    (new SessionAuthProvider())->boot($cms);

    add_filter('determine_current_user', static function ($userId) {
      return $userId ?: 99;
    }, 10);

    $issued = $cms->session()->authorize($this->authorizeRequest());
    $this->assertInstanceOf(WP_REST_Response::class, $issued);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued->data['accessToken'];
    $this->assertSame(1, WpStubs::applyFilters('determine_current_user', false));

    $this->now += SessionManager::ACCESS_TTL + 1;
    $this->assertSame(99, WpStubs::applyFilters('determine_current_user', false));

    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->assertSame(99, WpStubs::applyFilters('determine_current_user', false));
  }

  public function testGenerateAuthorizationCodeIsReserved(): void
  {
    $result = $this->manager()->generateAuthorizationCode();
    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertSame(501, $result->data['status']);
  }

  public function testAuthRoutesReplaceIsLoggedIn(): void
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

    $paths = array_column(WpStubs::$restRoutes, 'path');
    $this->assertNotContains('/is-logged-in', $paths);
    $this->assertContains('/auth/authorize', $paths);
    $this->assertContains('/auth/establish-session', $paths);
    $this->assertContains('/auth/generate', $paths);
  }

  private function manager(?callable $authorizationCodeRedeemer = null): SessionManager
  {
    $clock = fn(): int => $this->now;
    $secret = static fn(): string => 'session-secret';

    return new SessionManager(
      new InMemorySessionStore($clock),
      new SessionTokenCodec($secret, $clock),
      $secret,
      static fn(): array => ['https://web.test'],
      static fn(): string => 'https://wp.example.test',
      $clock,
      null,
      null,
      null,
      $authorizationCodeRedeemer,
    );
  }

  private function authorizeRequest(): WP_REST_Request
  {
    $request = new WP_REST_Request('POST');
    $request->set_header('X-CloakWP-Secret', 'session-secret');
    $request->set_param('grant_type', 'password');
    $request->set_param('username', 'editor');
    $request->set_param('password', 'secret');

    return $request;
  }
}
