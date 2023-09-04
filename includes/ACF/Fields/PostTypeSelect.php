<?php

namespace CloakWP\ACF\Fields;

use Extended\ACF\Fields\Select;
use InvalidArgumentException;

class PostTypeSelect extends Select
{
  protected array $enabledPostTypes;

  private function get_valid_post_types()
  {
    $post_types = array();
    $exclude = array('attachment', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page');
    $objects = get_post_types(array(), 'objects');

    foreach ($objects as $i => $object) {
      // Bail early if is exclude.
      if (in_array($i, $exclude)) {
        continue;
      }

      // Bail early if is builtin (WP) private post type
      // i.e. nav_menu_item, revision, customize_changeset, etc.
      if ($object->_builtin && !$object->public) {
        continue;
      }

      $post_types[$i] = $object->labels->singular_name;
    }

    return $post_types;
  }

  // we override inherited `make` in order to set default post type options when include() isn't called/specified
  public static function make(string $label, string|null $name = null): static
  {
    $self = new static($label, $name);
    $post_types = $self->get_valid_post_types();
    $self->include($post_types); // set defaults
    return $self;
  }

  public function include(array $enabledPostTypes): self
  {
    $validChoices = $this->get_valid_post_types();
    
    foreach ($enabledPostTypes as $choice) {
      if (!in_array($choice, $validChoices)) {
        throw new InvalidArgumentException("Invalid post type choice: $choice");
      }
    }
    
    // Set the choices field based on enabled choices
    $choices = [];
    foreach ($enabledPostTypes as $choice) {
      $choices[$choice] = $choice;
    }

    // Add choices to settings
    $this->enabledPostTypes = $enabledPostTypes;
    $this->choices($choices);

    return $this;
  }
}
