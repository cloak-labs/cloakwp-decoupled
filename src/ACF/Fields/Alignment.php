<?php

namespace CloakWP\ACF\Fields;

use Extended\ACF\Fields\ButtonGroup;
use InvalidArgumentException;

class Alignment extends ButtonGroup
{
  protected array $enabledChoices;

  // we override inherited `make` in order to set default alignment options when include() isn't called/specified
  public static function make(string $label, string|null $name = null): static
  {
    $self = new static($label, $name);
    $self->include(); // set defaults
    return $self;
  }


  public function include(array $enabledChoices = ['left', 'center', 'right', 'justify']): self
  {
    $validChoices = ['left', 'center', 'right', 'justify'];

    foreach ($enabledChoices as $choice) {
      if (!in_array($choice, $validChoices)) {
        throw new InvalidArgumentException("Invalid alignment choice: $choice");
      }
    }

    // Set the choices field based on enabled choices
    $choices = [];
    foreach ($enabledChoices as $choice) {
      switch ($choice) {
        case 'left':
          $choices['left'] = '<i class="dashicons dashicons-editor-alignleft"></i>';
          break;
        case 'center':
          $choices['center'] = '<i class="dashicons dashicons-editor-aligncenter"></i>';
          break;
        case 'right':
          $choices['right'] = '<i class="dashicons dashicons-editor-alignright"></i>';
          break;
        case 'justify':
          $choices['justify'] = '<i class="dashicons dashicons-editor-justify"></i>';
          break;
      }
    }

    // Add choices to settings
    $this->settings['choices'] = $choices;
    $this->enabledChoices = $enabledChoices;
    
    return $this;
  }
}