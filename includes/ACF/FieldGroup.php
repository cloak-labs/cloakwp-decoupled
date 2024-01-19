<?php

declare(strict_types=1);

namespace CloakWP\ACF;

/**
 * FieldGroup is an OOP wrapper around ExtendedACF's `register_extended_field_group` function.
 * 
 * Note: make sure to call the `register()` method after all your config methods to actually register the Field Group.
 */
class FieldGroup
{
  protected array $settings;

  public function __construct(string $label)
  {
    $this->settings = [
      'title' => $label,
      'style' => 'default', // ExtendedACF decided to make the default Field Group style 'seamless', so here we revert that back to the 'default' style
      'show_in_rest' => true,
      // 'local' => 'php',
    ];
  }

  public static function make(string $label): static
  {
    return new static($label);
  }

  public function fields(array $fields): static
  {
    $this->settings['fields'] = $fields;
    return $this;
  }

  public function location(array $locations): static
  {
    $this->settings['location'] = $locations;
    return $this;
  }

  /**
   * Set the style of the field group on the edit screen.
   *
   * @param string $style The style to set. Options: 'default' (default), or 'seamless'.
   */
  public function style(string $style): static
  {
    $this->settings['style'] = $style;
    return $this;
  }

  /**
   * Set the order of the field group on the edit screen, shown from lowest to highest (only useful when multiple Field Groups are assigned to the same location).
   *
   * @param int $order Defaults to 0
   */
  public function menuOrder(int $order): static
  {
    $this->settings['menu_order'] = $order;
    return $this;
  }

  /**
   * Set the position of the field group on the edit screen.
   *
   * @param string $position Options: 'normal' (default), 'acf_after_title', 'normal', or 'side'.
   */
  public function position(string $position): static
  {
    $this->settings['position'] = $position;
    return $this;
  }

  /**
   * Determines where field labels are placed in relation to fields.
   *
   * @param string $placement Options: 'top' (default), or 'left'.
   */
  public function labelPlacement(string $placement): static
  {
    $this->settings['label_placement'] = $placement;
    return $this;
  }

  /**
   * Determines where field instructions are placed in relation to fields.
   *
   * @param string $placement Options: 'label' (default), or 'field'.
   */
  public function instructionPlacement(string $placement): static
  {
    $this->settings['instruction_placement'] = $placement;
    return $this;
  }

  /**
   * An array of elements to hide on the screen .
   *
   * @param array $hide_on_screen TODO: list options here.
   */
  public function hideOnScreen(array $elements): static
  {
    $this->settings['hide_on_screen'] = $elements;
    return $this;
  }

  /**
   * Whether or not to include this FieldGroup's fields in REST API responses.
   * Note: CloakWP chose to default this to `true` despite ACF defaulting to `false` internally, because we're biased towards headless builds.
   */
  public function showInRest(bool $showInRest): static
  {
    $this->settings['show_in_rest'] = $showInRest;
    return $this;
  }

  /**
   * Set a custom description for this FieldGroup
   */
  public function description(string $description): static
  {
    $this->settings['description'] = $description;
    return $this;
  }

  /**
   * Whether or not this FieldGroup is active (default) or disabled
   */
  public function active(bool $isActive): static
  {
    $this->settings['active'] = $isActive;
    return $this;
  }

  public function register()
  {
    add_action('acf/init', function () {
      register_extended_field_group($this->settings);
    });
  }
}
