<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Styles;

use CloakWP\Decoupled\CMS;

/**
 * Explicitly registered content inventory + save notification for a CSS worker.
 * No hooks or endpoints are installed unless register() is called on this site.
 */
final class ContentStyleCatalog
{
  private string $workerUrl = '';
  private string $token = '';
  private ?array $postTypes = null;
  private bool $dirty = false;
  private bool $registered = false;
  private string $site = '';
  private bool $publishing = false;
  private int $notificationTimeout = 30;
  private const RETRY_HOOK = 'cloakwp_style_catalog_retry';
  private const OUTBOX_KEY = 'cloakwp_style_catalog_pending';

  public function site(string $site): self
  {
    $this->assertConfigurable();
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/D', $site)) throw new \InvalidArgumentException('Invalid catalog site identifier.');
    $this->site = $site;
    return $this;
  }

  /** Opt in to durable manifests and publication-before-ISR coordination. */
  public function publishing(bool $enabled = true): self { $this->assertConfigurable(); $this->publishing = $enabled; return $this; }

  public static function make(): self { return new self(); }

  public function workerUrl(string $url): self
  {
    $this->assertConfigurable();
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
      || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
      || parse_url($url, PHP_URL_FRAGMENT) !== null) {
      throw new \InvalidArgumentException('An absolute HTTP worker URL is required.');
    }
    $this->workerUrl = $url;
    return $this;
  }

  public function token(string $token): self { $this->assertConfigurable(); $this->token = $token; return $this; }
  /** A queued service acknowledges quickly; legacy synchronous compilers can retain 30 seconds. */
  public function notificationTimeout(int $seconds): self
  {
    $this->assertConfigurable();
    if ($seconds < 1 || $seconds > 30) throw new \InvalidArgumentException('Notification timeout must be 1–30 seconds.');
    $this->notificationTimeout = $seconds;
    return $this;
  }
  public function postTypes(array $types): self { $this->assertConfigurable(); $this->postTypes = $types; return $this; }

  public function authorized(string $token): bool
  {
    return $this->token !== '' && hash_equals($this->token, $token);
  }

  public function register(): self
  {
    if ($this->workerUrl === '' || $this->token === '') {
      throw new \LogicException('Configure a worker URL and token before registering the content style catalog.');
    }
    if ($this->publishing && $this->site === '') throw new \LogicException('Publishing requires a site identifier.');
    if ($this->registered) return $this;
    $this->registered = true;
    add_action('rest_api_init', function (): void {
      register_rest_route('cloakwp', '/class-catalog', [
        'methods' => 'GET',
        'callback' => fn($request) => rest_ensure_response($this->snapshot(
          filter_var($request->get_param('include_unpublished'), FILTER_VALIDATE_BOOL),
        )),
        'permission_callback' => fn($request) => $this->authorized($request->get_header('X-Class-Catalog-Token')),
      ]);
      if ($this->publishing) {
        register_rest_route('cloakwp', '/class-catalog/invalidate', [
          'methods' => 'POST',
          'callback' => fn($request) => $this->invalidate($request->get_json_params() ?? []),
          'permission_callback' => fn($request) => $this->authorized($request->get_header('X-Class-Catalog-Token')),
        ]);
        register_rest_route('cloakwp', '/class-catalog/manifest', [
          [
            'methods' => 'GET',
            'callback' => fn($request) => $this->manifest((string) $request->get_param('release')),
            'permission_callback' => fn($request) => $this->authorized($request->get_header('X-Class-Catalog-Token')),
          ],
          [
            'methods' => 'POST',
            'callback' => fn($request) => $this->publish($request->get_json_params() ?? []),
            'permission_callback' => fn($request) => $this->authorized($request->get_header('X-Class-Catalog-Token')),
          ],
        ]);
      }
    });
    if ($this->publishing) {
      // The worker invalidates the frontend only after the new CSS and manifest
      // are available. Native on-save ISR would race the CSS build.
      add_filter('cloakwp/decoupled/revalidate_on_save', '__return_false');
    }
    add_action('wp_after_insert_post', [$this, 'changed'], 100, 1);
    add_action('deleted_post', [$this, 'changed'], 100, 1);
    // ACF and REST meta updates have completed by shutdown. Coalesce repeated saves.
    add_action('acf/save_post', [$this, 'changed'], 100, 1);
    add_action('shutdown', [$this, 'notify'], 20);
    add_action(self::RETRY_HOOK, [$this, 'retry']);
    return $this;
  }

  public function changed(mixed $postId): void
  {
    if (!is_numeric($postId) || wp_is_post_revision((int) $postId) || wp_is_post_autosave((int) $postId)) return;
    $this->dirty = true;
    if ($this->publishing) update_option('cloakwp_style_content_revision', bin2hex(random_bytes(16)), false);
    // Keep a site-scoped outbox until the service durably accepts the event.
    update_option(self::OUTBOX_KEY, bin2hex(random_bytes(16)), false);
    if (!wp_next_scheduled(self::RETRY_HOOK)) wp_schedule_single_event(time() + 60, self::RETRY_HOOK);
  }

  /** Bulk database copies do not run post-save hooks. The caller queues publication. */
  public function invalidate(array $input): mixed
  {
    $operation = $input['operation'] ?? '';
    if (!$this->publishing || ($input['site'] ?? null) !== $this->site
      || ($input['siteId'] ?? null) !== get_current_blog_id()
      || !is_string($operation) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $operation)) {
      return new \WP_Error('invalid_publication', 'Invalid content publication scope.', ['status' => 400]);
    }
    // Repeated delivery must not supersede a build started by the same operation.
    // An editor save still advances the revision and takes precedence on retry.
    if (get_option('cloakwp_style_bulk_operation', '') !== $operation) {
      $revision = bin2hex(random_bytes(16));
      update_option('cloakwp_style_content_revision', $revision, false);
      if (get_option('cloakwp_style_content_revision') !== $revision) {
        return new \WP_Error('publication_not_saved', 'The content revision could not be saved.', ['status' => 500]);
      }
      update_option('cloakwp_style_bulk_operation', $operation, false);
    }
    $response = rest_ensure_response([
      'ok' => true, 'site' => $this->site, 'siteId' => get_current_blog_id(),
      'contentRevision' => (string) get_option('cloakwp_style_content_revision'),
    ]);
    $response->header('Cache-Control', 'no-store');
    return $response;
  }

  public function snapshot(bool $includeUnpublished = false): array
  {
    // Capture the revision before reading content. A concurrent save must not
    // label an older inventory with the newer revision and pass publication checks.
    $revision = (string) get_option('cloakwp_style_content_revision', 'initial');
    $types = $this->postTypes ?? array_values(array_unique([
      ...array_values(get_post_types(['public' => true])),
      ...($includeUnpublished ? ['wp_block', 'template-partial'] : []),
    ]));
    $types = array_values(array_diff($types, ['attachment']));
    $parser = CMS::getInstance()->getBlockParser();
    $documents = [];
    // The parser expands referenced reusable blocks. Unreferenced patterns have
    // no public route; only development/editor previews need those as roots.
    $statuses = $includeUnpublished ? ['publish', 'draft', 'pending', 'future', 'private'] : ['publish'];
    foreach (get_posts(['post_type' => $types, 'post_status' => $statuses, 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $post) {
      if (!has_blocks($post->post_content)) continue;
      $documents[] = ['id' => $post->ID, 'type' => $post->post_type, 'blocks' => $parser->parseBlocksFromPost($post)];
    }
    $uploads = wp_upload_dir();
    $publicRoot = rtrim(dirname(ABSPATH), '/');
    return [
      'version' => 1,
      'siteId' => get_current_blog_id(),
      'site' => $this->site,
      'contentRevision' => $revision,
      'publishing' => $this->publishing,
      'documents' => $documents,
      'uploads' => ['path' => ltrim(substr($uploads['basedir'], strlen($publicRoot)), '/'), 'url' => $uploads['baseurl']],
    ];
  }

  public function manifest(string $release): mixed
  {
    if (!$this->validIdentifier($release)) return new \WP_Error('invalid_release', 'Invalid CSS release.', ['status' => 400]);
    $manifest = get_option($this->manifestKey($release), null);
    if (!is_array($manifest)) return new \WP_Error('missing_styles', 'This frontend release has no published CSS.', ['status' => 404]);
    $response = rest_ensure_response($manifest);
    $response->header('Cache-Control', 'no-store');
    return $response;
  }

  public function publish(array $manifest): mixed
  {
    $release = $manifest['release'] ?? '';
    $url = $manifest['cssUrl'] ?? '';
    if (!$this->publishing || ($manifest['version'] ?? null) !== 1 || ($manifest['site'] ?? null) !== $this->site
      || !is_string($release) || !$this->validIdentifier($release)
      || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
      || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
      || parse_url($url, PHP_URL_FRAGMENT) !== null
      || !is_string($manifest['hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['hash'])
      || !is_int($manifest['bytes'] ?? null) || $manifest['bytes'] < 0
      || !is_int($manifest['gzipBytes'] ?? null) || $manifest['gzipBytes'] < 0) {
      return new \WP_Error('invalid_styles', 'Invalid CSS publication.', ['status' => 400]);
    }
    if (($manifest['contentRevision'] ?? '') !== (string) get_option('cloakwp_style_content_revision', 'initial')) {
      return new \WP_Error('stale_styles', 'Content changed during compilation. Fetch the inventory and retry.', ['status' => 409]);
    }
    // Feature data, scoped to this multisite blog and frontend release; no
    // credentials, storage configuration, or source code are persisted here.
    $key = $this->manifestKey($release);
    update_option($key, $manifest, false);
    if (get_option($key) !== $manifest) return new \WP_Error('style_storage_failed', 'The CSS manifest could not be persisted.', ['status' => 500]);
    return rest_ensure_response(['ok' => true]);
  }

  private function validIdentifier(string $value): bool { return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}$/D', $value); }
  private function manifestKey(string $release): string { return 'cloakwp_style_manifest_' . hash('sha256', $release); }
  private function assertConfigurable(): void
  {
    if ($this->registered) throw new \LogicException('A registered content style catalog cannot be reconfigured.');
  }

  public function notify(): void
  {
    if (!$this->dirty) return;
    $this->dirty = false;
    $pending = get_option(self::OUTBOX_KEY, false);
    $response = wp_remote_post($this->workerUrl, [
      'timeout' => $this->notificationTimeout,
      'headers' => ['Content-Type' => 'application/json', 'X-Class-Catalog-Token' => $this->token],
      // Fetch the inventory in a REST request, where ACF's block formatters are
      // registered. Admin and WP-CLI save contexts do not have the same parser.
      'body' => wp_json_encode(['version' => 1, 'siteId' => get_current_blog_id(), 'event' => 'content-changed']),
    ]);
    $accepted = !is_wp_error($response)
      && in_array(wp_remote_retrieve_response_code($response), [200, 202], true)
      && (json_decode(wp_remote_retrieve_body($response), true)['ok'] ?? false) === true;
    if (!$accepted) {
      error_log('Content style catalog worker failed; the previous stylesheet remains active.');
      if (!wp_next_scheduled(self::RETRY_HOOK)) wp_schedule_single_event(time() + 60, self::RETRY_HOOK);
    } elseif (is_string($pending)) {
      // Compare and delete in one database operation. get_option() can retain a
      // request-local cached value while another save replaces the outbox entry.
      global $wpdb;
      $wpdb->delete($wpdb->options, ['option_name' => self::OUTBOX_KEY, 'option_value' => $pending], ['%s', '%s']);
      wp_cache_delete(self::OUTBOX_KEY, 'options');
    }
  }

  public function retry(): void
  {
    if (get_option(self::OUTBOX_KEY, false) === false) return;
    $this->dirty = true;
    $this->notify();
  }
}
