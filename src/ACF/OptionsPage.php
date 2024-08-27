<?php

declare(strict_types=1);

namespace CloakWP\ACF;

use Extended\ACF\Location;

/**
 * OptionsPage is an OOP wrapper around ACF's `acf_add_options_page` function.
 * 
 * Note: make sure to call the `register()` method after all your config methods to actually activate the Option Page.
 */
class OptionsPage
{
  protected array $settings;
  protected array $fieldGroups;

  public function __construct(string $pageTitle)
  {
    $this->settings = [
      'page_title' => $pageTitle,
      'position' => 20,
      'icon_url' => 'dashicons-admin-settings'
    ];
  }

  public static function make(string $pageTitle = 'Site Settings'): static
  {
    return new static(__($pageTitle, 'acf'));
  }

  /**
   * Provide an array of CloakWP `FieldGroup` class instances to attach groups of ACF Fields to this Options Page. 
   */
  public function fieldGroups(array $fieldGroups): static
  {
    $this->fieldGroups = $fieldGroups;
    return $this;
  }


  /**
   * Controls the WordPress capability a site user must possess to view the Options Page.
   *
   * @param string $userCapability Defaults to `edit_posts` -- see options here: https://wordpress.org/documentation/article/roles-and-capabilities/#capabilities
   */
  public function visibleForCapability(string $userCapability): static
  {
    $this->settings['capability'] = $userCapability;
    return $this;
  }

  /**
   * Setting this to `true` will redirect this page to its first child page, if child pages exist. Defaults to `false`
   */
  public function redirectToChildPage(bool $shouldRedirect = true): static
  {
    $this->settings['redirect'] = $shouldRedirect;
    return $this;
  }

  /**
   * Optionally set a custom menu title that appears in the wp-admin menu. Defaults to `page_title`.
   *
   * @param string $title eg. "Site Settings"
   */
  public function menuTitle(string $title): static
  {
    $this->settings['menu_title'] = __($title, 'acf');
    return $this;
  }

  /**
   * Optionally set a custom URL slug used to uniquely identify this options page. Defaults to a url friendly version of `menu_title` or `page_title`.
   *
   * @param string $slug eg. "site-settings"
   */
  public function menuSlug(string $slug): static
  {
    $this->settings['menu_slug'] = sanitize_title($slug);
    return $this;
  }

  /**
   * Controls the icon used for the Option Page’s menu item in the WordPress admin. 
   * @see https://developer.wordpress.org/resource/dashicons/
   * @param int $dashiconOrUrl accepts either an image URL or a Dashicon class name (eg. "dashicons-admin-settings")
   */
  public function menuIcon(int $dashiconOrUrl): static
  {
    $this->settings['icon_url'] = $dashiconOrUrl;
    return $this;
  }

  /**
   * The position in the menu order where this menu should appear. WARNING: if two menu items use the same position, 
   * one of the items may be overwritten. Risk of conflict can be reduced by using decimal instead of integer values, 
   * e.g. "63.3" instead of 63 (must use quotes). Defaults to bottom of utility menu items.
   * 
   * @see: https://developer.wordpress.org/reference/functions/add_menu_page/#menu-structure 
   */
  public function menuPosition(int|string $position): static
  {
    $this->settings['position'] = $position;
    return $this;
  }


  /**
   * Set this Options Page as a nested child of another WP admin page.
   *
   * @param string $parentSlug The `slug` of the parent WP admin page.
   */
  public function parent(string $parentSlug): static
  {
    $this->settings['parent_slug'] = $parentSlug;
    return $this;
  }

  /**
   * Set a custom description for this Options Page
   * 
   * @see Read more about the available post_id values: https://www.advancedcustomfields.com/resources/get_field/
   * @param int|string $postId Can be set to a numeric post ID (123), or a string ("user_2"). Defaults to "options"
   */
  public function customStorage(int|string $postId): static
  {
    $this->settings['post_id'] = $postId;
    return $this;
  }

  /**
   * Data saved in the wp_options table is given an "autoload" identifier. When set to true, WP will automatically 
   * load these values within a single SQL query which can improve page load performance. Defaults to false. 
   */
  public function autoload(bool $shouldAutoload = true): static
  {
    $this->settings['autoload'] = $shouldAutoload;
    return $this;
  }

  /**
   * The text displayed on the Option Page's submit button.
   * 
   * @param string $buttonText eg. "Update"
   */
  public function updateButton(string $buttonText): static
  {
    $this->settings['update_button'] = __($buttonText, 'acf');
    return $this;
  }

  /**
   * The message shown above the form after updating the Option Page.  
   * 
   * @param string $message eg. "Options successfully updated."
   */
  public function updatedMessage(string $message): static
  {
    $this->settings['updated_message'] = __($message, 'acf');
    return $this;
  }

  /**
   * Set a custom description for this Options Page
   */
  public function description(string $description): static
  {
    $this->settings['description'] = __($description, 'acf');
    return $this;
  }

  public function register()
  {
    if (!isset($this->settings['menu_slug'])) {
      $this->settings['menu_slug'] = sanitize_title($this->settings['menu_title'] ?? $this->settings['page_title']);
    }

    add_action('acf/init', function () {
      if (isset($this->settings['parent_slug'])) {
        if (function_exists('acf_add_options_sub_page'))
          acf_add_options_sub_page($this->settings);
      } else {
        if (function_exists('acf_add_options_page'))
          acf_add_options_page($this->settings);
      }
    });

    if ($this->fieldGroups) {
      foreach ($this->fieldGroups as $fieldGroup) {
        $fieldGroup
          ->location([
            Location::where('options_page', '==', $this->settings['menu_slug'])
          ])
          ->register();
      }
    }
  }
}
