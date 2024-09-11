<?php

declare(strict_types=1);

namespace CloakWP\Core\Enqueue;

/**
 * A better API for enqueueing custom CSS files.
 */
class Stylesheet extends Asset
{
  protected string $enqueueFunction = 'wp_enqueue_style';

  /**
   * The media for which this stylesheet has been defined. Accepts media types like 'all' (default),
   * 'print' and 'screen', or media queries like '(orientation: portrait)' and '(max-width: 640px)'.
   */
  public function media(string $media): static
  {
    $this->settings['media'] = $media;
    return $this;
  }
}
