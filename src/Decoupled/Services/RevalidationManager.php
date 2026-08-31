<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Services;

use CloakWP\Core\Utils;
use CloakWP\Decoupled\Contracts\FrontendResolver;
use CloakWP\Decoupled\Frontend;

final class RevalidationManager
{
  public const CRON_HOOK = 'cloakwp_decoupled_retry_revalidation';
  private const QUEUE_OPTION = 'cloakwp_decoupled_revalidation_queue';

  private \Closure $httpPost;
  private \Closure $clock;

  public function __construct(
    private readonly FrontendResolver $frontends,
    private readonly MaintenanceState $maintenance,
    ?callable $httpPost = null,
    ?callable $clock = null,
  ) {
    $this->httpPost = \Closure::fromCallable($httpPost ?? 'wp_remote_post');
    $this->clock = \Closure::fromCallable($clock ?? 'time');
  }

  public function boot(): void
  {
    foreach ($this->allFrontends() as $frontend) {
      if ($frontend->getSettings('revalidateEntriesOnSave') !== true) {
        continue;
      }

      add_action('save_post', function ($postId, $post, $update) use ($frontend): void {
        if (
          wp_is_post_revision($postId)
          || wp_is_post_autosave($postId)
          || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
          || (defined('DOING_AJAX') && DOING_AJAX)
          || (($post->post_status ?? null) === 'auto-draft')
        ) {
          return;
        }

        $this->revalidate($frontend, [(int) $postId]);
      }, 10, 3);
    }

    add_action(self::CRON_HOOK, [$this, 'processRetryQueue']);
  }

  /**
   * Send one batched request to each unique deployment.
   *
   * @param list<mixed> $paths
   */
  public function revalidate(Frontend $frontend, array $paths): void
  {
    if ($this->maintenance->isRevalidationPaused()) {
      return;
    }

    $normalized = $this->normalizePaths($paths);
    if ($normalized === []) {
      return;
    }

    foreach ($this->targets($frontend) as $target) {
      $this->send($frontend, $target, $normalized, true);
    }
  }

  public function processRetryQueue(): void
  {
    $queue = get_option(self::QUEUE_OPTION, []);
    if (!is_array($queue) || $queue === []) {
      return;
    }

    if ($this->maintenance->isRevalidationPaused()) {
      $this->scheduleRetry();
      return;
    }

    $now = ($this->clock)();
    foreach ($queue as $key => $entry) {
      if (!is_array($entry) || (int) ($entry['nextAttemptAt'] ?? 0) > $now) {
        continue;
      }

      $frontend = $this->frontends->getFrontend((string) ($entry['frontend'] ?? ''));
      $target = (string) ($entry['target'] ?? '');
      $paths = is_array($entry['paths'] ?? null) ? $entry['paths'] : [];
      if ($frontend === null || !in_array($target, $this->targets($frontend), true)) {
        unset($queue[$key]);
        continue;
      }

      if ($this->send($frontend, $target, $this->normalizePaths($paths), false)) {
        unset($queue[$key]);
        continue;
      }

      $attempts = (int) ($entry['attempts'] ?? 0) + 1;
      if ($attempts >= 8) {
        error_log("CloakWP abandoned revalidation retries for {$target} after {$attempts} attempts.");
        unset($queue[$key]);
        continue;
      }

      $queue[$key]['attempts'] = $attempts;
      $queue[$key]['nextAttemptAt'] = $now + min(3600, 60 * (2 ** min($attempts, 6)));
    }

    if ($queue === []) {
      delete_option(self::QUEUE_OPTION);
      return;
    }

    update_option(self::QUEUE_OPTION, $queue, false);
    $this->scheduleRetry();
  }

  /**
   * @param list<string> $paths
   */
  private function send(Frontend $frontend, string $target, array $paths, bool $queueOnFailure): bool
  {
    if ($paths === []) {
      return true;
    }

    $succeeded = $this->sendPayload($frontend, $target, $paths);
    if (!$succeeded && $queueOnFailure) {
      $this->enqueue($frontend, $target, $paths);
    }

    return $succeeded;
  }

  /**
   * @param list<string> $paths
   */
  private function sendPayload(Frontend $frontend, string $target, array $paths): bool
  {
    try {
      $secret = $this->secret($frontend);
    } catch (\RuntimeException $error) {
      error_log($error->getMessage());
      return false;
    }

    $timestamp = ($this->clock)();
    $body = (string) json_encode(
      ['paths' => $paths],
      JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $response = ($this->httpPost)(
      rtrim($frontend->getApiRouterUrl($target), '/') . '/revalidate',
      [
        'blocking' => true,
        'timeout' => (int) $frontend->getSettings('revalidationTimeout'),
        'headers' => [
          'Content-Type' => 'application/json',
          'X-CloakWP-Timestamp' => (string) $timestamp,
          'X-CloakWP-Signature' => 'sha256=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $body,
            $secret,
          ),
        ],
        'body' => $body,
      ],
    );

    if (is_wp_error($response)) {
      error_log('CloakWP revalidation request failed: ' . $response->get_error_message());
      return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $responseBody = wp_remote_retrieve_body($response);
    if ($status < 200 || $status >= 300 || $responseBody === '') {
      error_log("CloakWP revalidation returned HTTP {$status}.");
      return false;
    }

    $decoded = json_decode($responseBody, true);
    $valid = is_array($decoded) && ($decoded['ok'] ?? null) === true;
    if (!$valid) {
      error_log('CloakWP revalidation returned an invalid response body.');
    }

    return $valid;
  }

  /**
   * @param list<mixed> $paths
   * @return list<string>
   */
  private function normalizePaths(array $paths): array
  {
    $normalized = [];
    foreach ($paths as $path) {
      if (is_object($path)) {
        $path = (int) ($path->ID ?? 0);
      }
      if (is_int($path)) {
        if ($path <= 0) {
          continue;
        }
        $path = Utils::getPostPathname($path);
      }
      if (!is_string($path) || $path === '') {
        continue;
      }

      $parsedPath = parse_url($path, PHP_URL_PATH);
      $path = is_string($parsedPath) ? $parsedPath : $path;
      $normalized[] = $path === '/' ? '/' : '/' . trim($path, '/');
    }

    return array_values(array_unique($normalized));
  }

  /** @return list<string> */
  private function targets(Frontend $frontend): array
  {
    $targets = [$frontend->getApiRouteUrl()];
    $deployments = $frontend->getSettings('deployments');
    if (is_array($deployments)) {
      $targets = [...$targets, ...$deployments];
    }

    return array_values(array_unique(array_map(
      static fn(string $url): string => rtrim($url, '/'),
      array_filter($targets, 'is_string'),
    )));
  }

  /**
   * @param list<string> $paths
   */
  private function enqueue(Frontend $frontend, string $target, array $paths): void
  {
    $queue = get_option(self::QUEUE_OPTION, []);
    if (!is_array($queue)) {
      $queue = [];
    }

    $key = hash('sha256', $frontend->getKey() . '|' . $target);
    $existingPaths = is_array($queue[$key]['paths'] ?? null) ? $queue[$key]['paths'] : [];
    $queue[$key] = [
      'frontend' => $frontend->getKey(),
      'target' => $target,
      'paths' => array_values(array_unique([...$existingPaths, ...$paths])),
      'attempts' => (int) ($queue[$key]['attempts'] ?? 0),
      'nextAttemptAt' => ($this->clock)() + 60,
    ];

    update_option(self::QUEUE_OPTION, $queue, false);
    $this->scheduleRetry();
  }

  private function scheduleRetry(): void
  {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_single_event(($this->clock)() + 60, self::CRON_HOOK);
    }
  }

  private function secret(Frontend $frontend): string
  {
    $secret = $frontend->getSettings('authSecret');
    if (!is_string($secret) || $secret === '') {
      $secret = defined('CLOAKWP_AUTH_SECRET') && is_string(\CLOAKWP_AUTH_SECRET)
        ? \CLOAKWP_AUTH_SECRET
        : '';
    }
    if ($secret === '') {
      throw new \RuntimeException(
        "Frontend '{$frontend->getKey()}' requires an auth secret for signed revalidation requests.",
      );
    }

    return $secret;
  }

  /** @return list<Frontend> */
  private function allFrontends(): array
  {
    return $this->frontends->getFrontends();
  }
}
