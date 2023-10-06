<?php

namespace CloakWP\ACF\Fields;

use Extended\ACF\Fields\Select;
use InvalidArgumentException;

class PostTypeSelect extends Select
{
  private function get_valid_post_types()
  {
    $post_types = array();
    $exclude = array('attachment', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page');
    $objects = get_post_types(array(), 'objects');

    foreach ($objects as $post_slug => $post_type_object) {
      // Bail early if is exclude.
      if (in_array($post_slug, $exclude)) {
        continue;
      }

      // Bail early if is builtin (WP) private post type
      // i.e. nav_menu_item, revision, customize_changeset, etc.
      if ($post_type_object->_builtin && !$post_type_object->public) {
        continue;
      }

      $label = $post_type_object->labels->singular_name;
      $post_types[$post_slug] = $label;
    }

    return $post_types;
  }

  // we override inherited `make` in order to set default post type options when include() isn't called/specified
  public static function make(string $label, string|null $name = null): static
  {
    $self = new static($label, $name);
    // $post_types = $self->get_valid_post_types();
    $self->include(); // set defaults
    return $self;
  }

  public function include(array $enabledPostTypes = null): self
  {
    $validChoices = $this->get_valid_post_types();
    
    $choices = [];
    if ($enabledPostTypes) {
      if (is_array($enabledPostTypes)) {
        foreach ($enabledPostTypes as $choice) {
          if (!array_key_exists($choice, $validChoices)) {
            throw new InvalidArgumentException("Invalid post type choice: $choice");
          }
    
          // Set the choices field based on enabled choices
          $choices[$choice] = $validChoices[$choice];
        }
      } else {
        throw new InvalidArgumentException("Invalid inclusion value -- must be ARRAY of post label strings.");
      }
    } else {
      $choices = $validChoices;
    }
    
    // Add choices to settings
    $this->choices($choices);

    return $this;
  }
}
