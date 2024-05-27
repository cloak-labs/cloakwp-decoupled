<?php

namespace CloakWP\ACF\Fields;

use Extended\ACF\Fields\Select;
use InvalidArgumentException;

class MenuSelect extends Select
{
  private function get_valid_menus()
  {
    $menus = array();
    $raw_menus = wp_get_nav_menus();

    foreach ($raw_menus as $menu) {
      $menus[$menu->term_id] = $menu->name;
    }

    return $menus;
  }

  // we override inherited `make` in order to set default post type options when include() isn't called/specified
  public static function make(string $label, string|null $name = null): static
  {
    $self = new static($label, $name);
    $self->include(); // set defaults
    return $self;
  }

  public function include(array $enabledMenuSlugs = null): self
  {
    add_action("init", function () use ($enabledMenuSlugs) {
      $validChoices = $this->get_valid_menus();
      $choices = [];

      if ($enabledMenuSlugs) {
        if (is_array($enabledMenuSlugs)) {
          foreach ($enabledMenuSlugs as $choice) {
            if (!array_key_exists($choice, $validChoices)) {
              throw new InvalidArgumentException("Non-existant menu slug provided to include method: $choice");
            }

            // Set the choices field based on enabled choices
            $choices[$choice] = $validChoices[$choice];
          }
        } else {
          throw new InvalidArgumentException("Invalid inclusion value -- must be ARRAY of menu slug strings.");
        }
      } else {
        $choices = $validChoices;
      }

      // Add choices to settings
      $this->choices($choices);
    }, 4);

    return $this;
  }
}
