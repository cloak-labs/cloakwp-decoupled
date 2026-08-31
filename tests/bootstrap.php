<?php

declare(strict_types=1);

if (!function_exists('get_current_blog_id')) {
  function get_current_blog_id(): int
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$siteId;
  }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
  require $autoload;
} else {
  spl_autoload_register(static function (string $class): void {
    $prefix = 'CloakWP\\';
    if (!str_starts_with($class, $prefix)) {
      return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
      require_once $path;
    }
  });
}

$coreSrc = dirname(__DIR__, 2) . '/cloakwp-core/src';
if (is_dir($coreSrc)) {
  spl_autoload_register(static function (string $class) use ($coreSrc): void {
    $prefix = 'CloakWP\\Core\\';
    if (!str_starts_with($class, $prefix)) {
      return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $coreSrc . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
      require_once $path;
    }
  });
}

require_once __DIR__ . '/WpStubs.php';

if (!defined('DAY_IN_SECONDS')) {
  define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('add_action')) {
  function add_action($hook, $callback, $priority = 10, $accepted_args = 1): void
  {
    \CloakWP\Decoupled\Tests\WpStubs::$actions[] = [
      'hook' => $hook,
      'callback' => $callback,
      'priority' => $priority,
      'accepted_args' => $accepted_args,
    ];
  }
}

if (!function_exists('add_filter')) {
  function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void
  {
    \CloakWP\Decoupled\Tests\WpStubs::$filters[] = [
      'hook' => $hook,
      'callback' => $callback,
      'priority' => $priority,
      'accepted_args' => $accepted_args,
    ];
  }
}

if (!function_exists('apply_filters')) {
  function apply_filters($hook, $value, ...$args)
  {
    return \CloakWP\Decoupled\Tests\WpStubs::applyFilters((string) $hook, $value, ...$args);
  }
}

if (!function_exists('did_action')) {
  function did_action($hook): int
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$didActions[(string) $hook] ?? 0;
  }
}

if (!function_exists('remove_action')) {
  function remove_action($hook, $callback, $priority = 10): bool
  {
    $before = count(\CloakWP\Decoupled\Tests\WpStubs::$actions);
    \CloakWP\Decoupled\Tests\WpStubs::$actions = array_values(array_filter(
      \CloakWP\Decoupled\Tests\WpStubs::$actions,
      static fn(array $action): bool => !(
        $action['hook'] === $hook
        && $action['callback'] === $callback
        && $action['priority'] === $priority
      ),
    ));
    return count(\CloakWP\Decoupled\Tests\WpStubs::$actions) !== $before;
  }
}

if (!function_exists('remove_filter')) {
  function remove_filter($hook, $callback, $priority = 10): bool
  {
    $before = count(\CloakWP\Decoupled\Tests\WpStubs::$filters);
    \CloakWP\Decoupled\Tests\WpStubs::$filters = array_values(array_filter(
      \CloakWP\Decoupled\Tests\WpStubs::$filters,
      static fn(array $filter): bool => !(
        $filter['hook'] === $hook
        && $filter['callback'] === $callback
        && $filter['priority'] === $priority
      ),
    ));
    return count(\CloakWP\Decoupled\Tests\WpStubs::$filters) !== $before;
  }
}

if (!function_exists('wp_parse_url')) {
  function wp_parse_url($url, $component = -1)
  {
    return parse_url($url, $component);
  }
}

if (!function_exists('wp_get_attachment_image_src')) {
  function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail')
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$imageSrc[(int) $attachment_id][$size] ?? false;
  }
}

if (!function_exists('get_post_meta')) {
  function get_post_meta($post_id, $key = '', $single = false)
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$postMeta[(int) $post_id][$key] ?? ($single ? '' : []);
  }
}

if (!function_exists('get_post')) {
  function get_post($post = null)
  {
    $id = is_object($post) ? ($post->ID ?? 0) : (int) $post;
    return \CloakWP\Decoupled\Tests\WpStubs::$posts[$id] ?? null;
  }
}

if (!function_exists('attachment_url_to_postid')) {
  function attachment_url_to_postid($url): int
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$urlToPostId[$url] ?? 0;
  }
}

if (!function_exists('get_site_url')) {
  function get_site_url(): string
  {
    return 'https://wp.example.test';
  }
}

if (!function_exists('home_url')) {
  function home_url($path = ''): string
  {
    return 'https://wp.example.test' . $path;
  }
}

if (!function_exists('is_admin')) {
  function is_admin(): bool
  {
    return false;
  }
}

if (!class_exists('WP_Error')) {
  class WP_Error
  {
    public function __construct(
      public string $code = '',
      public string $message = '',
      public mixed $data = null,
    ) {
    }

    public function get_error_message(): string
    {
      return $this->message;
    }

    public function get_error_code(): string
    {
      return $this->code;
    }
  }
}

if (!class_exists('WP_REST_Response')) {
  class WP_REST_Response
  {
    public function __construct(
      public mixed $data = null,
      public int $status = 200,
      public array $headers = [],
    ) {
    }

    public function get_status(): int
    {
      return $this->status;
    }

    public function get_headers(): array
    {
      return $this->headers;
    }
  }
}

if (!class_exists('WP_REST_Request')) {
  class WP_REST_Request
  {
    private array $params = [];
    private array $headers = [];

    public function __construct(
      public string $method = 'GET',
      public string $route = '',
    ) {
    }

    public function set_param(string $key, mixed $value): void
    {
      $this->params[$key] = $value;
    }

    public function get_param(string $key): mixed
    {
      return $this->params[$key] ?? null;
    }

    public function get_query_params(): array
    {
      return $this->params;
    }

    public function set_header(string $key, string $value): void
    {
      $this->headers[strtolower($key)] = $value;
    }

    public function get_header(string $key): string
    {
      return $this->headers[strtolower($key)] ?? '';
    }

    public function get_json_params(): array
    {
      return $this->params;
    }

    public function get_body_params(): array
    {
      return $this->params;
    }
  }
}

if (!function_exists('is_wp_error')) {
  function is_wp_error($value): bool
  {
    return $value instanceof WP_Error;
  }
}

if (!function_exists('rest_ensure_response')) {
  function rest_ensure_response($value): WP_REST_Response
  {
    return $value instanceof WP_REST_Response ? $value : new WP_REST_Response($value);
  }
}

if (!function_exists('register_rest_route')) {
  function register_rest_route($namespace, $route, $args = [], $override = false): bool
  {
    \CloakWP\Decoupled\Tests\WpStubs::$restRoutes[] = [
      'namespace' => (string) $namespace,
      'path' => (string) $route,
      'definition' => $args,
    ];
    return true;
  }
}

if (!function_exists('wp_json_encode')) {
  function wp_json_encode($value, $flags = 0, $depth = 512): string|false
  {
    return json_encode($value, $flags, $depth);
  }
}

if (!function_exists('get_option')) {
  function get_option($key, $default = false): mixed
  {
    $stubs = \CloakWP\Decoupled\Tests\WpStubs::class;
    return $stubs::$optionsBySite[$stubs::$siteId][$key] ?? $default;
  }
}

if (!function_exists('update_option')) {
  function update_option($key, $value, $autoload = null): bool
  {
    $stubs = \CloakWP\Decoupled\Tests\WpStubs::class;
    $stubs::$optionsBySite[$stubs::$siteId][$key] = $value;
    return true;
  }
}

if (!function_exists('delete_option')) {
  function delete_option($key): bool
  {
    $stubs = \CloakWP\Decoupled\Tests\WpStubs::class;
    unset($stubs::$optionsBySite[$stubs::$siteId][$key]);
    return true;
  }
}

if (!function_exists('wp_remote_post')) {
  function wp_remote_post($url, $args = []): mixed
  {
    \CloakWP\Decoupled\Tests\WpStubs::$remoteRequests[] = [
      'url' => $url,
      'args' => $args,
    ];
    return array_shift(\CloakWP\Decoupled\Tests\WpStubs::$remoteResponses)
      ?? ['response' => ['code' => 200], 'body' => '{"ok":true}'];
  }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
  function wp_remote_retrieve_response_code($response): int
  {
    return (int) ($response['response']['code'] ?? 0);
  }
}

if (!function_exists('wp_remote_retrieve_body')) {
  function wp_remote_retrieve_body($response): string
  {
    return (string) ($response['body'] ?? '');
  }
}

if (!function_exists('wp_next_scheduled')) {
  function wp_next_scheduled($hook): int|false
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$scheduledEvents[$hook] ?? false;
  }
}

if (!function_exists('wp_schedule_single_event')) {
  function wp_schedule_single_event($timestamp, $hook, $args = []): bool
  {
    \CloakWP\Decoupled\Tests\WpStubs::$scheduledEvents[$hook] = (int) $timestamp;
    return true;
  }
}

if (!function_exists('wp_is_post_revision')) {
  function wp_is_post_revision($postId): int|false
  {
    return in_array((int) $postId, \CloakWP\Decoupled\Tests\WpStubs::$revisionIds, true)
      ? (int) $postId
      : false;
  }
}

if (!function_exists('wp_is_post_autosave')) {
  function wp_is_post_autosave($postId): int|false
  {
    return in_array((int) $postId, \CloakWP\Decoupled\Tests\WpStubs::$autosaveIds, true)
      ? (int) $postId
      : false;
  }
}

if (!function_exists('get_fields')) {
  function get_fields($postId = false): array|false
  {
    return $postId === 'options'
      ? \CloakWP\Decoupled\Tests\WpStubs::$acfOptions
      : false;
  }
}

if (!function_exists('get_field')) {
  function get_field($selector, $postId = false): mixed
  {
    return $postId === 'options'
      ? (\CloakWP\Decoupled\Tests\WpStubs::$acfOptions[$selector] ?? false)
      : false;
  }
}

if (!function_exists('get_field_object')) {
  function get_field_object($selector, $postId = false): array|false
  {
    if ($postId !== 'options' || !array_key_exists($selector, \CloakWP\Decoupled\Tests\WpStubs::$acfOptions)) {
      return false;
    }

    return [
      'name' => (string) $selector,
      'value' => \CloakWP\Decoupled\Tests\WpStubs::$acfOptions[$selector],
    ];
  }
}

if (!function_exists('sanitize_key')) {
  function sanitize_key($value): string
  {
    return strtolower((string) preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value));
  }
}

if (!function_exists('sanitize_title')) {
  function sanitize_title($value): string
  {
    return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9-]+/', '-', (string) $value), '-'));
  }
}

if (!function_exists('wp_safe_redirect')) {
  function wp_safe_redirect($url, $status = 302, $xRedirectBy = 'WordPress'): bool
  {
    \CloakWP\Decoupled\Tests\WpStubs::$redirects[] = (string) $url;
    return true;
  }
}

if (!function_exists('get_post_type')) {
  function get_post_type($post = null): string
  {
    return 'page';
  }
}

if (!function_exists('is_user_logged_in')) {
  function is_user_logged_in(): bool
  {
    return false;
  }
}

if (!function_exists('wp_verify_nonce')) {
  function wp_verify_nonce($nonce, $action = -1): int|false
  {
    return $nonce === 'valid' ? 1 : false;
  }
}

if (!function_exists('wp_parse_args')) {
  function wp_parse_args($args, $defaults = []): array
  {
    return array_merge($defaults, is_array($args) ? $args : []);
  }
}

if (!class_exists('WP_User')) {
  class WP_User
  {
    public function __construct(
      public int $ID = 0,
      public string $user_login = '',
      public string $display_name = '',
      public string $user_pass = '',
    ) {
      if ($this->display_name === '') {
        $this->display_name = $this->user_login;
      }
    }
  }
}

if (!function_exists('do_action')) {
  function do_action($hook, ...$args): void
  {
    \CloakWP\Decoupled\Tests\WpStubs::runAction((string) $hook, ...$args);
  }
}

if (!function_exists('wp_authenticate')) {
  function wp_authenticate($username, $password)
  {
    $user = apply_filters('authenticate', null, $username, $password);
    if ($user instanceof WP_User) {
      return $user;
    }
    if (is_wp_error($user)) {
      return $user;
    }

    $expected = \CloakWP\Decoupled\Tests\WpStubs::$passwords[(string) $username] ?? null;
    if (!is_string($expected) || !hash_equals($expected, (string) $password)) {
      return new WP_Error('incorrect_password', 'Invalid credentials.', ['status' => 401]);
    }

    foreach (\CloakWP\Decoupled\Tests\WpStubs::$users as $candidate) {
      if ($candidate->user_login === $username) {
        return $candidate;
      }
    }

    return new WP_Error('incorrect_password', 'Invalid credentials.', ['status' => 401]);
  }
}

if (!function_exists('wp_set_auth_cookie')) {
  function wp_set_auth_cookie($user_id, $remember = false, $secure = '', $token = ''): void
  {
    \CloakWP\Decoupled\Tests\WpStubs::$authCookies[] = [
      'userId' => (int) $user_id,
      'remember' => (bool) $remember,
    ];
  }
}

if (!function_exists('wp_logout')) {
  function wp_logout(): void
  {
    \CloakWP\Decoupled\Tests\WpStubs::$logoutCount++;
  }
}

if (!function_exists('get_userdata')) {
  function get_userdata($user_id)
  {
    return \CloakWP\Decoupled\Tests\WpStubs::$users[(int) $user_id] ?? false;
  }
}

if (!function_exists('set_transient')) {
  function set_transient($transient, $value, $expiration = 0): bool
  {
    \CloakWP\Decoupled\Tests\WpStubs::$transients[(string) $transient] = [
      'value' => $value,
      'exp' => time() + (int) $expiration,
    ];
    return true;
  }
}

if (!function_exists('get_transient')) {
  function get_transient($transient): mixed
  {
    $row = \CloakWP\Decoupled\Tests\WpStubs::$transients[(string) $transient] ?? null;
    if (!is_array($row)) {
      return false;
    }
    if (($row['exp'] ?? 0) < time()) {
      unset(\CloakWP\Decoupled\Tests\WpStubs::$transients[(string) $transient]);
      return false;
    }

    return $row['value'];
  }
}

if (!function_exists('delete_transient')) {
  function delete_transient($transient): bool
  {
    unset(\CloakWP\Decoupled\Tests\WpStubs::$transients[(string) $transient]);
    return true;
  }
}

if (!function_exists('get_user_meta')) {
  function get_user_meta($user_id, $key = '', $single = false): mixed
  {
    $value = \CloakWP\Decoupled\Tests\WpStubs::$userMeta[(int) $user_id][$key] ?? ($single ? '' : []);
    return $value;
  }
}

if (!function_exists('update_user_meta')) {
  function update_user_meta($user_id, $key, $value): bool
  {
    \CloakWP\Decoupled\Tests\WpStubs::$userMeta[(int) $user_id][$key] = $value;
    return true;
  }
}

if (!function_exists('delete_user_meta')) {
  function delete_user_meta($user_id, $key): bool
  {
    unset(\CloakWP\Decoupled\Tests\WpStubs::$userMeta[(int) $user_id][$key]);
    return true;
  }
}
