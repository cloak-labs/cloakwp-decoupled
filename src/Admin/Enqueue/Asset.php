<?php

declare(strict_types=1);

namespace CloakWP\Admin\Enqueue;

use InvalidArgumentException;

/**
 * A base class for the Script and Stylesheet child classes, enabling a better API 
 * for enqueueing custom CSS and JS files.
 */
abstract class Asset
{
  protected array $settings;
  protected string $enqueueHook = 'admin_enqueue_scripts';
  protected string $enqueueFunction = ''; // eg. 'wp_enqueue_script' or 'wp_enqueue_style'

  public function __construct(string $handle)
  {
    $this->settings = [
      'handle' => $handle,
      'src' => '',
      'deps' => array(),
      'ver' => false,
    ];
  }

  /**
   * Create a new script/stylesheet asset. You must call the `enqueue` method to actually enqueue it.
   * @param string $handle - Name of the script. Should be unique.
   */
  public static function make(string $handle): static
  {
    return new static($handle);
  }

  public function hook(string $hookName): static
  {
    $this->enqueueHook = $hookName;
    return $this;
  }

  /**
   * Full URL of the script/stylesheet, or path relative to the WordPress root directory.
   */
  public function src(string $src): static
  {
    $this->settings['src'] = $src;
    return $this;
  }

  /**
   * An array of registered script/stylesheet handles this script depends on.
   * 
   * Default: array()
   */
  public function deps(array $deps): static
  {
    $this->settings['deps'] = $deps;
    return $this;
  }

  /**
   * String specifying script version number, if it has one, which is added to the URL as a 
   * query string for cache busting purposes. If version is set to false, a version number 
   * is automatically added equal to current installed WordPress version. If set to null,
   * no version is added. 
   * 
   * Default: false
   */
  public function version(bool|string|null $ver): static
  {
    $this->settings['ver'] = $ver;
    return $this;
  }

  public function enqueue()
  {
    add_action($this->enqueueHook, function () {
      $args = array_values($this->settings);
      if (is_callable($this->enqueueFunction)) {
        call_user_func($this->enqueueFunction, ...$args);
      } else {
        throw new InvalidArgumentException("The 'enqueueFunction' property for this Asset child class is not a valid function. Assign a value such as 'wp_enqueue_script'.");
      }
    });
  }
}
