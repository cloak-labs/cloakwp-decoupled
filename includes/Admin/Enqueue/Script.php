<?php

declare(strict_types=1);

namespace CloakWP\Admin\Enqueue;

use InvalidArgumentException;

/**
 * A better API for enqueueing custom JS files.
 */
class Script extends Asset
{
  protected string $enqueueFunction = 'wp_enqueue_script';
  
  /**
   * Since WordPress v6.3. Used to specify a script loading strategy. Supported strategies are as follows:
   *    "defer": Script is only executed once the DOM tree has fully loaded (but before the DOMContentLoaded and window load events). Deferred scripts are executed in the same order they were printed/added in the DOM, unlike asynchronous scripts.
   *    "async": Script is executed as soon as they are loaded by the browser. Asynchronous scripts do not have a guaranteed execution order, as script B (although added to the DOM after script A) may execute first given that it may complete loading prior to script A. Such scripts may execute either before the DOM has been fully constructed or after the DOMContentLoaded event.
   *
   * @param string $strategy - value must be either 'defer' or 'async'
   * @throws InvalidArgumentException
   */
  public function loadingStrategy(string $strategy): static
  {
    if ($strategy != 'defer' && $strategy != 'async') {
      throw new InvalidArgumentException("strategy value must be either 'defer' or 'async'");
    }
    $this->initArgsIfEmpty();
    $this->settings['args']['strategy'] = $strategy;
    return $this;
  }

  /**
   * Ensures the script is printed in the footer.
   */
  public function inFooter(): static
  {
    $this->initArgsIfEmpty();
    $this->settings['args']['in_footer'] = true;
    return $this;
  }

  private function initArgsIfEmpty(): void
  {
    if(!isset($this->settings['args'])) $this->settings['args'] = [];
  }
}
