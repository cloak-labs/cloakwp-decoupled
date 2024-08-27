<?php

declare(strict_types=1);

namespace CloakWP\Content;

use CloakWP\ACF\FieldGroup;
use Extended\ACF\Location;

class MenuLocation
{
  public string $slug;
  public string $description;
  protected array|null $fields = null;
  protected array|null $menuItemFields = null;

  public function __construct(string $slug, string $description)
  {
    $this->slug = sanitize_key($slug); // sanitize_key ensures consistency/correctness if user provides improper slug, such as non-lowercase
    $this->description = $description;
  }

  public static function make(string $slug, string $description): static
  {
    return new static($slug, $description);
  }

  /**
   * Provide an array of ExtendedACF fields to attach to the Menu that's assigned to this MenuLocation -- also see `menuItemFields` method. 
   */
  public function fields(array $fields): static
  {
    $this->fields = $fields;
    return $this;
  }

  /**
   * Provide an array of ExtendedACF fields to attach to the Menu Items of the Menu that's assigned to this MenuLocation -- also see `fields` method. 
   */
  public function menuItemFields(array $fields): static
  {
    $this->menuItemFields = $fields;
    return $this;
  }

  /**
   * Finally, register the Post Type and, if necessary, its ACF Field Groups.
   * Make sure to call this method last -- you can't continue chaining methods after it.
   */
  public function register()
  {
    add_action('after_setup_theme', function () {
      register_nav_menu($this->slug, $this->description);

      if ($this->fields) {
        $locations = get_nav_menu_locations();
        $menu_id = $locations[$this->slug];

        if ($menu_id) {
          FieldGroup::make('Menu Fields')
            ->fields($this->fields)
            ->location([
              Location::where('nav_menu', '==', "{$menu_id}")
            ])
            ->register();
        }
      }
    }, 3);

    // if ($this->afterChangeCallback) {
    //   $callback = $this->afterChangeCallback;
    //   add_action("save_post_$this->slug", function ($post_id, $post, $update) use ($callback) {
    //     if (wp_is_post_autosave($post_id)) {
    //       return;
    //     }

    //     if (!$update) { // if new object
    //       return;
    //     }

    //     $callback($post_id, $post, $update);
    //   }, 10, 3);
    // }
  }

}
