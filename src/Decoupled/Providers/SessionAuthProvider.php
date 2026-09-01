<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Services\SessionManager;

/**
 * Registers CloakWP session Bearer authentication ahead of jwt-auth so
 * frontend access tokens win when both schemes are present.
 */
final class SessionAuthProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$this->shouldRegister($cms)) {
      return;
    }

    add_filter('determine_current_user', static function ($userId) use ($cms) {
      if ($userId) {
        return $userId;
      }

      $header = (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
      );
      if (!preg_match('/^Bearer\s+(\S+)/i', $header, $matches)) {
        return $userId;
      }

      $claims = $cms->session()->verifyAccessToken($matches[1]);
      if ($claims === null) {
        return $userId;
      }

      return $claims['sub'];
    }, 5);

    add_action('after_password_reset', static function ($user) use ($cms): void {
      $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
      if ($id > 0) {
        $cms->session()->revokeAllRefreshTokens($id);
      }
    }, 10, 1);

    add_action('profile_update', static function ($userId, $oldUser = null) use ($cms): void {
      $id = (int) $userId;
      $newUser = get_userdata($id);
      if ($id < 1 || !is_object($oldUser) || !is_object($newUser)) {
        return;
      }
      if (($oldUser->user_pass ?? '') === ($newUser->user_pass ?? '')) {
        return;
      }
      $cms->session()->revokeAllRefreshTokens($id);
    }, 10, 2);

    add_action('wp_logout', static function ($userId = 0) use ($cms): void {
      $id = (int) $userId;
      if ($id > 0) {
        $cms->session()->revokeAllRefreshTokens($id);
      }
    }, 10, 1);

    add_filter('logout_redirect', static function ($redirectTo) use ($cms) {
      $frontends = $cms->getFrontends();
      if ($frontends === []) {
        return $redirectTo;
      }

      return rtrim($frontends[0]->getUrl(), '/') . SessionManager::FRONTEND_LOGOUT_PATH;
    });

    // wp-login.php uses wp_safe_redirect() after logout_redirect. Without
    // this, a decoupled frontend host is stripped and session cookies stay.
    add_filter('allowed_redirect_hosts', static function ($hosts) use ($cms) {
      $hosts = is_array($hosts) ? $hosts : [];
      foreach ($cms->getFrontends() as $frontend) {
        $host = parse_url($frontend->getUrl(), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
          $hosts[] = strtolower($host);
        }
      }

      return array_values(array_unique($hosts));
    });
  }

  private function shouldRegister(CMS $cms): bool
  {
    $context = $cms->context();

    return $context->isRest()
      || $context->isCore()
      || $context->isLogin()
      || $context->isBackoffice();
  }
}
