<?php

declare(strict_types=1);

namespace CloakWP\Decoupled\Providers;

use CloakWP\Core\Enqueue\Assets;
use CloakWP\Core\Enqueue\Script;
use CloakWP\Core\Enqueue\Stylesheet;
use CloakWP\Decoupled\CMS;
use CloakWP\Decoupled\Support\Acf;
use CloakWP\Decoupled\Support\PluginAssets;

final class EditorAssetsProvider implements ServiceProvider
{
  public function register(CMS $cms): void
  {
  }

  public function boot(CMS $cms): void
  {
    if (!$cms->context()->isBackoffice()) {
      return;
    }

    $assets = new PluginAssets();
    $blockPreviewJs = $assets->path('js/block-preview.js');
    $editorCss = $assets->path('css/editor.css');
    $isDevelopment = defined('WP_ENV') && \WP_ENV === 'development';
    $enqueue = [
      Stylesheet::make('cloakwp_gutenberg_styles')
        ->hooks(['enqueue_block_editor_assets'])
        ->src($assets->url('css/editor.css'))
        ->version($isDevelopment && is_readable($editorCss) ? (string) filemtime($editorCss) : '2.0.0'),
    ];

    if (Acf::isActive()) {
      $enqueue[] = Script::make('cloakwp_block_preview')
        ->hooks(['enqueue_block_editor_assets'])
        ->priority(100)
        ->src($assets->url('js/block-preview.js'))
        ->deps(['acf-input', 'acf-blocks'])
        ->version(
          ($isDevelopment && is_readable($blockPreviewJs))
            ? (string) filemtime($blockPreviewJs)
            : '2.0.0'
        )
        ->inFooter();
    }

    Assets::enqueue($enqueue);
  }
}
