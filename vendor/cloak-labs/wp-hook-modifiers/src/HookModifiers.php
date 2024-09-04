<?php

declare(strict_types=1);

namespace CloakWP;

class HookModifiers
{
  private string $type = 'filter';
  private string $hook;
  private array $modifiers;
  private int $modifiersArgPosition = 0;

  /**
   * @param array $modifiers An array of modifier keys to look for in the modifiers object arg
   */
  private function __construct(array $modifiers)
  {
    $this->modifiers = $modifiers;
  }

  /**
   * Create a new HookModifiers instance
   *
   * @param array $modifiers An array of modifier keys to look for in the modifiers object arg
   * @return self
   */
  public static function make(array $modifiers): self
  {
    return new self($modifiers);
  }

  /**
   * Specify a filter hook to apply the modifiers to
   *
   * @param string $hook The filter name
   * @return self
   */
  public function forFilter(string $hook): self
  {
    $this->type = 'filter';
    $this->hook = $hook;
    return $this;
  }

  /**
   * Specify an action hook to apply the modifiers to
   *
   * @param string $hook The action name
   * @return self
   */
  public function forAction(string $hook): self
  {
    $this->type = 'action';
    $this->hook = $hook;
    return $this;
  }

  /**
   * Set the position of the argument containing the data for modifiers
   *
   * @param int $position The position of the argument (0-based index)
   * @return self
   */
  public function modifiersArgPosition(int $position): self
  {
    $this->modifiersArgPosition = $position;
    return $this;
  }

  public function register(): void
  {
    if (empty($this->hook)) {
      throw new \InvalidArgumentException('Hook name is not set. You must call forFilter() or forAction() before calling register().');
    }

    $callback = $this->getCallback();

    if ($this->type === 'filter') {
      add_filter($this->hook, $callback, 10, 10);
    } else {
      add_action($this->hook, $callback, 10, 10);
    }
  }

  private function getCallback(): callable
  {
    return function () {
      $hook_name = current_filter();
      $args = func_get_args();
      $modifiersObject = $args[$this->modifiersArgPosition];

      foreach ($this->modifiers as $modifier) {
        if (isset($modifiersObject["_$modifier"])) {
          $value = $modifiersObject["_$modifier"];
        } elseif (isset($modifiersObject[$modifier])) {
          $value = $modifiersObject[$modifier];
        } else {
          continue;
        }

        $modified_hook = "$hook_name/$modifier=$value";
        $hasManyArgs = count($args) > 1;

        // Apply hooks with modifiers:
        if ($this->type === 'filter') {
          if ($hasManyArgs) {
            $args[0] = apply_filters_ref_array($modified_hook, $args);
          } else {
            $args[0] = apply_filters($modified_hook, $args);
          }
        } else {
          if ($hasManyArgs) {
            do_action_ref_array($modified_hook, $args);
          } else {
            do_action($modified_hook, $args);
          }
        }
      }

      return $args[0];
    };
  }
}