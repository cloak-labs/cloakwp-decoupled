<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Core\Rest\RestApi;
use CloakWP\Core\Rest\Route;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Rest\Handlers\Authorize;
use CloakWP\Decoupled\Rest\Handlers\EstablishLogout;
use CloakWP\Decoupled\Rest\Handlers\EstablishSession;
use CloakWP\Decoupled\Rest\Handlers\GenerateAuthorizationCode;
use CloakWP\Decoupled\Rest\Handlers\GetFrontpage;
use CloakWP\Decoupled\Rest\Handlers\GetGlobal;
use CloakWP\Decoupled\Rest\Handlers\GetMenu;
use CloakWP\Decoupled\Rest\Handlers\ListGlobals;
use CloakWP\Decoupled\Rest\Handlers\ListMenus;
use CloakWP\Decoupled\Rest\Handlers\Logout;

final class RestEndpointsProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isRest()) {
      return;
    }

    $menuRepository = $cms->menuRepository();
    $globalsRepository = $cms->globalsRepository();
    $globalsExposure = $cms->globalsExposure();
    $session = $cms->session();

    RestApi::make('cloakwp')
      ->routes([
        Route::get('/menus', new ListMenus($menuRepository))
          ->public()
          ->args([
            'location' => [
              'type' => 'string',
              'required' => false,
              'sanitize_callback' => 'sanitize_key',
            ],
          ]),
        Route::get('/menus/(?P<menu_slug>[a-zA-Z0-9-]+)', new GetMenu($menuRepository))
          ->public()
          ->args([
            'menu_slug' => [
              'type' => 'string',
              'required' => true,
              'sanitize_callback' => 'sanitize_title',
            ],
          ]),
        Route::get('/frontpage', new GetFrontpage())->public(),
        Route::get('/globals', new ListGlobals($globalsRepository, $globalsExposure))->public(),
        Route::get('/globals/(?P<global_slug>[a-zA-Z0-9_-]+)', new GetGlobal($globalsRepository, $globalsExposure))
          ->public()
          ->args([
            'global_slug' => [
              'type' => 'string',
              'required' => true,
              'sanitize_callback' => 'sanitize_key',
            ],
          ]),
        Route::post('/auth/authorize', new Authorize($session))
          ->public()
          ->args([
            'grant_type' => [
              'type' => 'string',
              'required' => true,
            ],
            'username' => [
              'type' => 'string',
              'required' => false,
            ],
            'password' => [
              'type' => 'string',
              'required' => false,
            ],
            'refresh_token' => [
              'type' => 'string',
              'required' => false,
            ],
            'code' => [
              'type' => 'string',
              'required' => false,
            ],
          ]),
        Route::get('/auth/establish-session', new EstablishSession($session))
          ->public()
          ->args([
            'code' => [
              'type' => 'string',
              'required' => true,
            ],
            'redirect' => [
              'type' => 'string',
              'required' => true,
            ],
          ]),
        Route::get('/auth/establish-logout', new EstablishLogout($session))
          ->public()
          ->args([
            'code' => [
              'type' => 'string',
              'required' => true,
            ],
            'redirect' => [
              'type' => 'string',
              'required' => true,
            ],
          ]),
        Route::post('/auth/logout', new Logout($session))
          ->public()
          ->args([
            'refresh_token' => [
              'type' => 'string',
              'required' => true,
            ],
          ]),
        Route::get('/auth/generate', new GenerateAuthorizationCode($session))->public(),
      ])
      ->register();
  }
}
