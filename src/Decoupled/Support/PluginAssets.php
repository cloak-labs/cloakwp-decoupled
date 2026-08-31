<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Support;

final class PluginAssets
{
  private string $root;

  public function __construct(?string $root = null)
  {
    $this->root = $root ?? dirname(__DIR__, 3);
  }

  public function path(string $relative): string
  {
    return $this->root . '/' . ltrim($relative, '/');
  }

  public function url(string $relative): string
  {
    $relative = ltrim($relative, '/');
    foreach ($this->servedLocations() as [$directory, $url]) {
      if (is_readable($directory . '/' . $relative)) {
        return rtrim($url, '/') . '/' . $relative;
      }
    }

    if (function_exists('plugin_dir_url')) {
      return plugin_dir_url($this->root . '/cloakwp-decoupled.php') . $relative;
    }

    throw new \RuntimeException('Unable to resolve CloakWP Decoupled public asset URL.');
  }

  /**
   * @return list<array{string, string}>
   */
  private function servedLocations(): array
  {
    $locations = [];
    foreach (['decoupled', 'cloakwp-decoupled'] as $directory) {
      if (defined('WPMU_PLUGIN_DIR') && defined('WPMU_PLUGIN_URL')) {
        $locations[] = [
          rtrim(\WPMU_PLUGIN_DIR, '/') . '/' . $directory,
          rtrim(\WPMU_PLUGIN_URL, '/') . '/' . $directory,
        ];
      }
      if (defined('WP_PLUGIN_DIR') && defined('WP_PLUGIN_URL')) {
        $locations[] = [
          rtrim(\WP_PLUGIN_DIR, '/') . '/' . $directory,
          rtrim(\WP_PLUGIN_URL, '/') . '/' . $directory,
        ];
      }
    }

    return $locations;
  }
}
