<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Tests;

final class WpStubs
{
  /** @var list<array{hook: mixed, callback: mixed, priority: mixed, accepted_args: int}> */
  public static array $actions = [];

  /** @var list<array{hook: mixed, callback: mixed, priority: mixed, accepted_args: int}> */
  public static array $filters = [];

  /** @var array<string, int> */
  public static array $didActions = [];

  /** @var array<int, array<string, array{0: string, 1: int, 2: int}|false>> */
  public static array $imageSrc = [];

  /** @var array<int, array<string, mixed>> */
  public static array $postMeta = [];

  /** @var array<int, object> */
  public static array $posts = [];

  /** @var array<string, int> */
  public static array $urlToPostId = [];

  /** @var array<int, array<string, mixed>> */
  public static array $optionsBySite = [];

  public static int $siteId = 1;

  /** @var list<array{url: string, args: array<string, mixed>}> */
  public static array $remoteRequests = [];

  /** @var list<mixed> */
  public static array $remoteResponses = [];

  /** @var array<string, int> */
  public static array $scheduledEvents = [];

  /** @var array<string, mixed> */
  public static array $acfOptions = [];

  /** @var list<string> */
  public static array $redirects = [];

  /** @var list<int> */
  public static array $revisionIds = [];

  /** @var list<int> */
  public static array $autosaveIds = [];

  /** @var list<array{namespace: string, path: string, definition: array<string, mixed>}> */
  public static array $restRoutes = [];

  /** @var array<int, \WP_User> */
  public static array $users = [];

  /** @var array<string, string> */
  public static array $passwords = [];

  /** @var list<array{userId: int, remember: bool}> */
  public static array $authCookies = [];

  public static int $logoutCount = 0;

  /** @var array<string, array{value: mixed, exp: int}> */
  public static array $transients = [];

  /** @var array<int, array<string, mixed>> */
  public static array $userMeta = [];

  /** @var array<string, mixed> */
  public static array $objectCache = [];

  public static function reset(): void
  {
    self::$actions = [];
    self::$filters = [];
    self::$didActions = [];
    self::$imageSrc = [];
    self::$postMeta = [];
    self::$posts = [];
    self::$urlToPostId = [];
    self::$optionsBySite = [];
    self::$siteId = 1;
    self::$remoteRequests = [];
    self::$remoteResponses = [];
    self::$scheduledEvents = [];
    self::$acfOptions = [];
    self::$redirects = [];
    self::$revisionIds = [];
    self::$autosaveIds = [];
    self::$restRoutes = [];
    self::$users = [];
    self::$passwords = [];
    self::$authCookies = [];
    self::$logoutCount = 0;
    self::$transients = [];
    self::$userMeta = [];
    self::$objectCache = [];
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_COOKIE = [];
    unset($_SERVER['HTTP_ORIGIN']);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    unset($GLOBALS['post']);
  }

  public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
  {
    foreach (self::$filters as $filter) {
      if ($filter['hook'] !== $hook) {
        continue;
      }
      $value = ($filter['callback'])($value, ...$args);
    }

    return $value;
  }

  public static function runAction(string $hook, mixed ...$args): void
  {
    self::$didActions[$hook] = (self::$didActions[$hook] ?? 0) + 1;
    $actions = array_values(array_filter(
      self::$actions,
      static fn(array $action): bool => $action['hook'] === $hook,
    ));
    usort($actions, static fn(array $left, array $right): int => $left['priority'] <=> $right['priority']);
    foreach ($actions as $action) {
      ($action['callback'])(...array_slice($args, 0, $action['accepted_args']));
    }
  }
}
