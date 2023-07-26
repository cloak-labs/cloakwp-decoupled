<?php

namespace CloakWP\ACF\Fields;

use CloakWP\Utils;
use Extended\ACF\Fields\RadioButton;

// Note: this field requires custom CSS which CloakWP\Admin::inject_theme_color_picker_css() handles by dynamically generating and inlining CSS into wp-admin <head>
class ThemeColorPicker extends RadioButton
{
  // we override inherited `make` in order to set default colors when include() isn't called/specified
  public static function make(string $label, string|null $name = null): static
  {
    $self = new static($label, $name);
    $self->include(); // set defaults
    return $self;
  }

  public function include(array $enabledColors = []): self
  {
    $final_colors = $this->filterColors($enabledColors);
    $this->setFinalColors($final_colors);
    return $this;
  }

  public function exclude(array $excludedColors = []): self
  {
    $final_colors = $this->filterColors($excludedColors, true);
    $this->setFinalColors($final_colors);
    return $this;
  }

  private function filterColors(array $filterColors = [], bool $exclude = false): array
  {
    $color_palette = Utils::get_theme_color_palette();
    $final_colors = [];

    // if there are colors in the $color_palette array
    if (!empty($color_palette)) {
      $noFilterColors = empty($filterColors);
      
      // loop over each color and create option
      foreach ($color_palette as $color) {
        $includeCondition = $exclude ? !in_array($color['slug'], $filterColors) : in_array($color['slug'], $filterColors);
        
        // filter colors based on whether they've been included or excluded
        if ($noFilterColors || $includeCondition) {
          $final_colors[$color['slug']] = $color['name'];
        }
      }
    }

    return $final_colors;
  }

  private function setFinalColors(array $final_colors): void
  {
    $this->settings['choices'] = $final_colors;
    $this->settings['wrapper']['class'] = 'cloakwp-theme-color-picker';
  }

}
