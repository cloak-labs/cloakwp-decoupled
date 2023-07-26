<?php

namespace CloakWP;

use CloakWP\CloakWP;
use CloakWP\Utils;

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://https://github.com/cloak-labs
 * @since      0.6.0
 *
 * @package    CloakWP
 * @subpackage CloakWP/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, enqueues the admin-specific stylesheet and JavaScript,
 * strips down wp-admin for headless, removes certain options for editor roles, etc.
 *
 * @package    CloakWP
 * @subpackage CloakWP/admin
 * @author     Cloak Labs 
 */
class Admin
{

  /**
   * The ID of this plugin.
   *
   * @since    0.6.0
   * @access   private
   * @var      string    $plugin_name    The ID of this plugin.
   */
  private $plugin_name;

  /**
   * The version of this plugin.
   *
   * @since    0.6.0
   * @access   private
   * @var      string    $version    The current version of this plugin.
   */
  private $version;

  /**
   * Initialize the class and set its properties.
   *
   * @since    0.6.0
   * @param      string    $plugin_name       The name of this plugin.
   * @param      string    $version    The version of this plugin.
   */
  public function __construct($plugin_name, $version)
  {
    $this->plugin_name = $plugin_name;
    $this->version = $version;
    $this->add_frontend_links();
    $this->add_frontend_view_links();
    $this->settings_init();
    $this->modify_acf_rest_api_format();
    $this->modify_jwt_issuer();
  }


  /**
   * Override the href for the site name & view site links (to use our Next front-end URL)
   * in the WP admin toolbar, and open them in new tabs
   *
   * @param object $wp_admin_bar
   * 
   * @return void
   * 
   * @since    0.6.0
   */
  public static function customize_admin_bar($wp_admin_bar)
  {
    // Get references to the 'view-site' and 'site-name' nodes to modify.
    $view_site_node = $wp_admin_bar->get_node('view-site');
    $site_name_node = $wp_admin_bar->get_node('site-name');

    // Change targets
    $view_site_node->meta['target'] = '_blank';
    $site_name_node->meta['target'] = '_blank';

    // Change hrefs to our frontend URL
    $url = CloakWP::get_frontend_url();
    $view_site_node->href = $url;
    $site_name_node->href = $url;

    // Update Nodes
    $wp_admin_bar->add_node($view_site_node);
    $wp_admin_bar->add_node($site_name_node);
  }


  /**
   * Modify 'View Post' links on posts, pages to point to frontend URL
   * 
   * @return string
   *
   * @since    0.6.0
   */
  public static function custom_view_page_url($permalink, $post)
  {
    $custom_permalink = CloakWP::get_frontend_url();
    if ($permalink) {
      $custom_permalink = str_replace(home_url(), $custom_permalink,  $permalink);
    }
    return $custom_permalink;
  }

  /**
   * 
   * Options page function
   * 
   * @return void
   *
   * @since    0.6.0
   */
  public function cloakwp_options_page()
  { ?>
    <div>
      <h2>CloakWP Configuration</h2>
      <?php
      settings_fields('cloakwp_settings');
      do_settings_sections('cloakwp_settings');
      ?>
    </div>
  <?php
  }

  /**
   * Function to output HTML to display env var
   * 
   * @return void
   *
   * @since    0.6.0
   */
  private static function echo_variable($var)
  {
    if (isset($var)) {
      if (gettype($var) === 'boolean') {
        if ($var === TRUE) {
          echo "<span>TRUE</span>";
        }
        if ($var === FALSE) {
          echo "<span>FALSE</span>";
        }
      }
      if (gettype($var) === 'string') {
        if (strlen($var) > 0) {
          echo "<span>'" . $var . "'</span>";
        }
        if (strlen($var) === 0) {
          echo "<span>''</span>";
        }
      }
      echo "<span> (" . gettype($var) . ")</span>";
    } else {
      echo "<span>Unset</span>";
    }
  }


  /**
   * Output a row on the settings page
   * 
   * @return void
   *
   * @since    0.6.0
   */
  private static function settings_row($name)
  {
    if (defined($name)) {
      $var = constant($name);
    } else {
      $var = NULL;
    }
  ?>
    <tr>
      <th scope="row">
        <?php echo $name ?>
      </th>
      <td><?php self::echo_variable($var); ?></td>
    </tr>
  <?php
  }

  /**
   * Ouput the rows of settings vars to the admin page
   * 
   * @return void
   *
   * @since    0.6.0
   */
  public function cloakwp_config_section_callback()
  { ?>
    <table class="form-table" role="presentation">
      <tbody>
        <?php
        self::settings_row('CLOAKWP_FRONTEND_URL');
        self::settings_row('CLOAKWP_PREVIEW_SECRET');
        self::settings_row('CLOAKWP_ENABLE_DEV_MODE');
        self::settings_row('CLOAKWP_API_BASE_PATH'); ?>
      </tbody>
    </table>
<?php
  }

  /**
   * Initialize the settings & settings page section
   * 
   * @return void
   *
   * @since    0.6.0
   */
  public function cloakwp_settings_init()
  {
    register_setting('cloakwp_settings', 'cloakwp_settings');

    add_settings_section(
      'config_section',
      __('PHP Constants', 'cloakwp'),
      array($this, 'cloakwp_config_section_callback'),
      'cloakwp_settings'
    );
  }


  /**
   * Add CloakWP menu item to wordpress admin
   * 
   * @return void
   *
   * @since    0.6.0
   */
  public function add_plugin_menu()
  {
    add_menu_page(
      'CloakWP',
      'CloakWP',
      'manage_options',
      'cloakwp',
      array($this, 'cloakwp_options_page'),
      'data:image/svg+xml;base64,' . base64_encode('<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m22.43.01-.73.07C14.88.69 8.5 4.37 4.45 10.02A23.75 23.75 0 0 0 .22 20.51a18.3 18.3 0 0 0-.22 3.5c0 1.78.02 2.17.22 3.49A24.1 24.1 0 0 0 21.7 47.94c.73.08 3.87.08 4.6 0a24.22 24.22 0 0 0 8.65-2.53c.4-.2.49-.27.43-.31-.03-.03-1.8-2.4-3.9-5.24l-3.84-5.19-4.81-7.11a688.2 688.2 0 0 0-4.84-7.12c-.02 0-.04 3.16-.05 7.02-.02 6.76-.02 7.04-.1 7.2a.85.85 0 0 1-.42.42c-.15.08-.28.1-.99.1h-.81l-.22-.15a.88.88 0 0 1-.31-.34l-.1-.2.01-9.42.02-9.4.14-.19c.08-.1.24-.22.35-.29.19-.09.27-.1 1.08-.1.95 0 1.11.04 1.36.31.07.08 2.68 4 5.8 8.72l9.46 14.34 3.8 5.76.2-.13c1.7-1.1 3.5-2.68 4.92-4.32a23.89 23.89 0 0 0 5.65-12.27c.2-1.32.22-1.7.22-3.5 0-1.78-.02-2.17-.22-3.49A24.1 24.1 0 0 0 26.37.07c-.45-.04-3.55-.1-3.94-.06zm9.82 14.52a.95.95 0 0 1 .48.55c.03.12.04 2.73.03 8.61v8.44l-1.5-2.28-1.49-2.28v-6.14c0-3.96.02-6.19.05-6.3a.96.96 0 0 1 .46-.59c.2-.1.26-.1 1-.1.7 0 .82 0 .97.09z" fill="#000"/></svg>')
    );
  }

  /**
   * Override the href for the site name & view site links
   * 
   * @return void
   *
   * @since    0.6.0
   */
  private function settings_init()
  {
    add_action('admin_init', array($this, 'cloakwp_settings_init'));
  }


  /**
   * Override the href for the site name & view site links
   * 
   * @return void
   *
   * @since    0.6.0
   */
  private function add_frontend_links()
  {
    add_action('admin_bar_menu', array($this, 'customize_admin_bar'), 80);
  }

  /**
   * Modify 'View Post' links on posts, pages to point to frontend URL
   * 
   * @return void
   *
   * @since    0.6.0
   */
  private function add_frontend_view_links()
  {
    add_filter('page_link', array($this, 'custom_view_page_url'), 10, 2);
    add_filter('post_link', array($this, 'custom_view_page_url'), 10, 2);
    add_filter('post_type_link', array($this, 'custom_view_page_url'), 10, 2);
  }

  /**
   * Register the stylesheets for the admin area.
   *
   * @since    0.6.0
   */
  public function enqueue_styles()
  {
    wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/cloakwp-admin.css', array(), $this->version, 'all');
  }

  /**
   * Register the JavaScript for the admin area.
   *
   * @since    0.6.0
   */
  public function enqueue_scripts()
  {
    wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/cloakwp-admin.js', array('jquery'), $this->version, false);
  }

  /* 
    Add CSS styling and JS for ACF Block iframe previews, among other future Gutenberg customizations:
  */
  public function enqueue_gutenberg_assets()
  {
    // Load our custom CSS stylesheets for the Gutenberg editor:
    $cssFile = plugin_dir_url(__FILE__) . 'css/gutenberg-styles.css';
    wp_enqueue_style(
      'cloakwp-gutenberg-editor-styles',
      $cssFile,
      array(),
      filemtime($cssFile),
      'all'
    );

    // Load our custom JS scripts for the Gutenberg editor:
    $jsFile = plugin_dir_url(__FILE__) . 'js/acf-block-iframe-preview.js';
    wp_enqueue_script(
      'acf-block-iframe-preview-js',
      $jsFile,
      array(),
      filemtime($jsFile),
      true
    );
  }

  // Add stuff to wp-admin's <head>
  public function add_to_admin_head()
  {
    $this->inject_theme_color_picker_css();
    $this->inject_browser_sync_script();
    $this->restrict_appearance_menu_for_editors();
  }

  // Add browserSync script to wp-admin <head> to enable live reloading upon saving theme files
  public function inject_browser_sync_script()
  {
    echo '<script id="__bs_script__">//<![CDATA[
      (function() {
        try {
          console.log("adding BrowserSync script");
          var script = document.createElement("script");
          if ("async") {
            script.async = true;
          }
          script.src = "http://localhost:3000/browser-sync/browser-sync-client.js?v=2.29.3";
          if (document.body) {
            document.body.appendChild(script);
          } else if (document.head) {
            document.head.appendChild(script);
          }
        } catch (e) {
          console.error("Browsersync: could not append script tag", e);
        }
      })()
    //]]></script>';
  }

  /**
   * Add dynamically-generated CSS to wp-admin's <head>, to style our ThemeColorPicker custom ACF Field using our theme.json's colors
   *
   * @since    0.6.0
   */
  public function inject_theme_color_picker_css()
  {
    $themeColorPickerCSS = '';
    $color_palette = Utils::get_theme_color_palette();
    if (!empty($color_palette)) {
      foreach ($color_palette as $color) {
        $themeColorPickerCSS .= ".cloakwp-theme-color-picker .acf-radio-list li label input[type='radio'][value='{$color['slug']}'] { background-color: var(--wp--preset--color--{$color['slug']}); }";
      }
    }
    echo "<style id='themeColorPickerACF'>{$themeColorPickerCSS}</style>";
  }

  /*
    Add ability for "editor" user role to edit WP Menus, but hide all other submenus under Appearance (for editors only) -- eg. we don't want clients to be able to switch/deactivate theme 
  */
  public function restrict_appearance_menu_for_editors()
  {
    $role_object = get_role('editor');
    if (!$role_object->has_cap('edit_theme_options')) {
      $role_object->add_cap('edit_theme_options');
    }

    if (current_user_can('editor')) { // remove certain Appearance > Sub-pages
      remove_submenu_page('themes.php', 'themes.php'); // hide the theme selection submenu
      remove_submenu_page('themes.php', 'widgets.php'); // hide the widgets submenu

      // special handling for removing "Customize" submenu (above method doesn't work due to its URL structure) --> snippet taken from https://stackoverflow.com/a/50912719/8297151
      global $submenu;
      if (isset($submenu['themes.php'])) {
        foreach ($submenu['themes.php'] as $index => $menu_item) {
          foreach ($menu_item as $value) {
            if (strpos($value, 'customize') !== false) {
              unset($submenu['themes.php'][$index]);
            }
          }
        }
      }
    }
  }

  /*
    This is required in order for WP Admin > Appearance > Menus page 
    to be visible for new Block themes such as this one. 
  */
  public function register_menus()
  {
    register_nav_menus(
      array(
        'nav' => __(''), // this creates a menu location that doesn't serve a purpose other than to get the "Menus" page to become visible in wp-admin
      )
    );
  }

  /*
  Expand ACF field data returned in REST API; eg. image fields return full image data rather than just an ID. More info: https://www.advancedcustomfields.com/resources/wp-rest-api-integration/
  */
  public function modify_acf_rest_api_format()
  {
    add_filter('acf/settings/rest_api_format', function () {
      return 'standard';
    });
  }

  /*
  Change the JWT token issuer:
  */
  public function modify_jwt_issuer()
  {
    // Note: 06/26/2023 I can't remember why this filter was added or if it's really needed
    add_filter('jwt_auth_iss', function () {
      // Default value is get_bloginfo( 'url' );
      return site_url();
    });
  }

  // Give editors access to the Menu tab
  public function allow_menu_editing_for_editors()
  {
    $role = get_role('editor');
    $role->add_cap('edit_theme_options');
  }

  /*
    Remove "Comments" from wp-admin sidebar for all roles.
    Remove "Tools", "Dashboard", and "Yoast SEO" for non-admins
  */
  public function remove_admin_pages()
  {
    remove_menu_page('edit-comments.php');

    if (!current_user_can('administrator')) { // remove certain pages for non-administrators
      remove_menu_page('tools.php'); // remove "Tools"
      remove_menu_page('index.php'); // remove "Dashboard"

      // remove Yoast SEO
      remove_menu_page('wpseo_dashboard');
      remove_menu_page('wpseo_workouts');
    }
  }

  /*
    Function to remove various options in wp-admin top toolbar (not sidebar)
    Currently used to remove the "Comments" and "View Posts" menu items
  */
  public function remove_admin_toolbar_options()
  {
    global $wp_admin_bar;
    $wp_admin_bar->remove_menu('comments');
    $wp_admin_bar->remove_menu('archive');
  }
}
