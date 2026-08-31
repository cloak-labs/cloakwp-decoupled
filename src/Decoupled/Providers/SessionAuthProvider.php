<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Decoupled\CMS;

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
    if (!$cms->context()->isRest() && !$cms->context()->isCore()) {
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
  }
}
