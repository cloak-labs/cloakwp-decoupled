<?php

namespace CloakWP;

use CloakWP\Admin\Admin;
use CloakWP\Admin\Enqueue\Stylesheet;
use CloakWP\VirtualFields\VirtualField;
use CloakWP\Utils;

use CloakWP\API\BlockTransformer;
use CloakWP\API\FrontpageEndpoint;
use CloakWP\API\MenusEndpoint;
use CloakWP\API\OptionsEndpoint;

use InvalidArgumentException;
use WP_Block_Type_Registry;
use WP_Error;
use WP_REST_Response;

class CloakWP extends Admin
{
  /**
   * Stores the CloakWP Singleton instance.
   */
  private static $instance;

  /**
   * Stores an instance of the BlockTransformer class.
   */
  private BlockTransformer $blockTransformer;

  /**
   * Stores one or more decoupled Frontend instances, defined by plugin user.
   */
  protected array $frontends = [];

  /**
   * Stores one or more ACF Block instances, defined by plugin user.
   */
  protected array $blocks = [];

  /**
   * Stores one or more PostType instances, defined by plugin user.
   */
  protected array $postTypes = [];

  /**
   * The unique identifier of this plugin.
   */
  protected string $plugin_name;

  /**
   * The current version of the plugin.
   */
  protected string $version;

  /**
   * Define the core functionality of the plugin.
   */
  private function __construct()
  {
    if (defined('CLOAKWP_VERSION')) {
      $this->version = CLOAKWP_VERSION;
    } else {
      $this->version = '0.6.0';
    }

    $this->plugin_name = 'cloakwp';
    $this->setLocale();
    $this->bootstrap();
  }

  /**
   * This function gets called when initiating the CloakWP instance via getInstance().
   * We extracted this logic into this separate method so that users can extend CloakWP 
   * and customize the bootstrapping process without having to also redeclare __construct(),
   * which most likely folks will not want to customize. 
   */
  private function bootstrap()
  {
    $this->blockTransformer = new BlockTransformer();

    // Register CloakWP custom REST API endpoints:
    MenusEndpoint::register();
    FrontpageEndpoint::register();
    OptionsEndpoint::register();

    // Enqueue CloakWP custom CSS/JS assets:
    $this->enqueueAssets([
      Stylesheet::make("{$this->plugin_name}_admin_styles")
        ->src(dirname(plugin_dir_url(__FILE__)) . '/assets/css/admin.css')
        ->version($this->version),
      Stylesheet::make("{$this->plugin_name}_gutenberg_styles")
        ->hook('enqueue_block_editor_assets')
        ->src(dirname(plugin_dir_url(__FILE__)) . '/assets/css/editor.css')
        ->version($this->version)
    ]);

    // for now, enable all wp-admin customizations by default:
    $this
      ->replaceFrontendLinks()
      ->registerHeadlessVirtualFields()
      ->registerAuthEndpoint()
      ->cleanRestResponses()
      ->makeAcfRestFormatStandard()
      ->modifyJwtIssuer()
      ->extendJwtExpiryDate()
      ->disableBlockPluginRecommendations()
      ->injectBrowserSyncScript()
      ->injectThemeColorPickerCss()
      ->restrictAppearanceMenuForEditors()
      ->enableMenuEditingForEditors()
      ->addXdebugInfoPage()
      ->addConfigDisplayPage()
      ->removeAdminToolbarOptions()
      ->enableLegacyMenuEditor()
      ->removeIrrelevantAdminPages()
      ->deprioritizeYoastSeoMetabox()
      ->applyRecommendedThemeSupports()
      ->enableCors()
      ->cleanAdminNotices();
  }


  /**
   * Returns the CloakWP Singleton instance.
   */
  public static function getInstance(): static
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Registers the post virtual fields that are necessary for CloakWP.js to work.
   */
  public function registerHeadlessVirtualFields(): static
  {
    add_action("init", function () {
      $customPostTypes = Utils::get_custom_post_types();
      $publicPostTypes = Utils::get_public_post_types();
      $allPostTypes = array_merge($customPostTypes, $publicPostTypes);
      $gutenbergPostTypes = Utils::get_post_types_with_editor();
  
      // add the `pathname` virtual field to all PUBLIC posts (i.e. all posts that map to a front-end page):
      register_virtual_fields($publicPostTypes, [
        VirtualField::make('pathname')
          ->value(fn ($post) => Utils::get_post_pathname(is_array($post) ? $post['id'] : $post->ID))
      ]);

      // add `featured_image` and `taxonomies` virtual fields to all CPTs + public built-in post types:
      register_virtual_fields($allPostTypes, [
        VirtualField::make('featured_image')
          ->value(function ($post) {
            $post_id = is_array($post) ? $post['id'] : $post->ID;
            $image_id = get_post_thumbnail_id($post_id);
            
            $result = [];
            $sizes = apply_filters('cloakwp/virtual_fields/featured_image/sizes', ['medium', 'large', 'full'], $post, $image_id);
            
            foreach ($sizes as $size) {
              $img = wp_get_attachment_image_src($image_id, $size);
              if (is_array($img)) {
                $url = $img[0]; // Image URL
                $width = $img[1]; // Width of the image
                $height = $img[2]; // Height of the image

                // Include URL, width, and height in the result
                $result[$size] = [
                  'src' => $url,
                  'width' => $width,
                  'height' => $height
                ];
              } else {
                // Handle cases where the image size does not exist
                $result[$size] = false;
              }
            }

            $alt_desc = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            $result['alt'] = $alt_desc;
  
            return $result;
          }),
        VirtualField::make('author')
          ->value(function ($post) {
            $authorId = is_array($post) ? $post['author'] : $post->post_author;
            return Utils::get_pretty_author($authorId);
          }),
        VirtualField::make('taxonomies')
          ->value(function (\WP_Post $post) {  
            // Get all taxonomies attached to the post type
            $taxonomies = get_object_taxonomies($post->post_type);
            $taxonomies_data = array();
  
            // Iterate through each taxonomy
            foreach ($taxonomies as $taxonomy) {
              // Get the terms for the current taxonomy
              $terms = wp_get_post_terms($post->ID, $taxonomy);
              $terms_data = array();
  
              // Iterate through each term
              foreach ($terms as $term) {
                // Build the term data array
                $term_data = array(
                  'name' => $term->name,
                  'slug' => $term->slug,
                  'id' => $term->term_id,
                );
  
                // Add the term data to the terms array
                $terms_data[] = $term_data;
              }
  
              // Add the taxonomy slug to its own object
              $taxonomies_data[$taxonomy]['slug'] = $taxonomy;
  
              // Add the terms data to the taxonomies data array
              $taxonomies_data[$taxonomy]['terms'] = $terms_data;
            }
  
            return $taxonomies_data;
          })
      ]);
    
      // add the Gutenberg-related virtual fields to all post types that utilize Gutenberg
      if ($gutenbergPostTypes) {
        register_virtual_fields($gutenbergPostTypes, [
          VirtualField::make('blocks_data')
            ->value(function ($post) {
              if (!$post) return [];
              return $this->blockTransformer->getBlocksFromPost($post);
            })
            ->excludeFrom(['core'])
        ]);
      }
    }, 99);

    return $this;
  }

  /**
   * When hooked into the 'rest_prepare_{$post_type}' filter, this function cleans up the 
   * REST API response for that post type, removing fields that are usually unused by decoupled
   * frontends; need to be careful about removing things that are used by WordPress internally.
   */
  public function cleanRestResponses(): static
  {
    $cleanFn = function (WP_REST_Response|WP_Error $response, $post, $context) {
      // First check if the REST response is an error:
      if (is_wp_error($response)) {
        return $response;
      }

      $original_data = $response->data;
      $modified_data = $response->data;
      // $modified_data['author'] = Utils::get_pretty_author($original_data['post_author']);

      /* 
          Remove unnecessary fields from the response.
          Note: the 'content' field is not usually useful when doing headless the "right" way, but it's
                required by Gutenberg in order to properly show/preview blocks in the editor, so leave it.
        */
      unset(
        $modified_data['date_gmt'],
        $modified_data['modified_gmt'],
        $modified_data['featured_media'],
        $modified_data['comment_status'],
        $modified_data['ping_status'],
        $modified_data['guid'],
        // Remove categories & tags in favour of "taxonomies" virtual field added in registerHeadlessVirtualFields() method:
        $modified_data['categories'],
        $modified_data['tags'],
        // $modified_data['post_author'], // replaced by new 'author' field above
      );

      // Remove footnotes if it's empty:
      if (isset($modified_data['meta']) && $modified_data['meta']['footnotes'] == "") unset($modified_data['meta']);

      // Remove some unnecessary nesting:
      if (isset($modified_data['title']['rendered'])) $modified_data['title'] = $modified_data['title']['rendered'];
      if (isset($modified_data['excerpt']['rendered'])) $modified_data['excerpt'] = $modified_data['excerpt']['rendered'];

      // Apply a filter to the final modified data so users can customize further (eg. they can remove more fields, and/or add back in some that we removed above)
      $final_data = apply_filters('cloakwp/rest/posts/response_format', $modified_data, $original_data);

      $response->data = $final_data;
      return $response;
    };

    $publicPostTypes = Utils::get_public_post_types();
    $publicPostTypes[] = 'revision'; // make sure "revisions" responses also get cleaned in same way

    foreach ($publicPostTypes as $postType) {
      add_filter("rest_prepare_{$postType}", $cleanFn, 50, 3);
    }

    return $this;
  }


  /**
   * Modifies all wp-admin frontend links (eg. 'View Post') to point to decoupled frontend URL
   */
  public function replaceFrontendLinks(): static
  {
    add_filter('page_link', array($this, 'convertToDecoupledUrl'), 10, 2);
    add_filter('post_link', array($this, 'convertToDecoupledUrl'), 10, 2);
    add_filter('post_type_link', array($this, 'convertToDecoupledUrl'), 10, 2);

    // Override the href for the site name & view site links in the wp-admin top toolbar, and open links in new tab:
    add_action('admin_bar_menu', function (\WP_Admin_Bar $wp_admin_bar) {
      // Get references to the 'view-site' and 'site-name' nodes to modify.
      $view_site_node = $wp_admin_bar->get_node('view-site');
      $site_name_node = $wp_admin_bar->get_node('site-name');

      // Change targets
      $view_site_node->meta['target'] = '_blank';
      $site_name_node->meta['target'] = '_blank';

      // Change hrefs to our frontend URL
      $url = $this->getActiveFrontend()->getUrl();
      $view_site_node->href = $url;
      $site_name_node->href = $url;

      // Update Nodes
      $wp_admin_bar->add_node($view_site_node);
      $wp_admin_bar->add_node($site_name_node);
    }, 80);
    return $this;
  }

  /**
   * Given a default WP post permalink, this returns the decoupled frontend URL for that particular post (used with filters)
   */
  public function convertToDecoupledUrl($permalink, $post): string
  {
    $decoupledPostUrl = $this->getActiveFrontend()->getUrl();
    if ($permalink) {
      // str_replace below ensures that the permalink path gets appended to the active frontend url:
      $decoupledPostUrl = str_replace(home_url(), $decoupledPostUrl,  $permalink);
    }
    $decoupledPostUrl = apply_filters('cloakwp/decoupled_post_link', $decoupledPostUrl, $permalink, $post);
    return $decoupledPostUrl;
  }


  /**
   * Add CloakWP Config Display page to wp-admin
   */
  public function addConfigDisplayPage(): static
  {
    add_action('admin_menu', function () {
      add_menu_page(
        'CloakWP Configuration Details',
        'CloakWP',
        'manage_options',
        'cloakwp',
        function () {
?>
        <div>
          <h2>CloakWP Configuration</h2>
          <?php
          $this->renderActiveFrontendSettings()
          ?>
        </div>
    <?php
        },
        'data:image/svg+xml;base64,' . base64_encode('<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m22.43.01-.73.07C14.88.69 8.5 4.37 4.45 10.02A23.75 23.75 0 0 0 .22 20.51a18.3 18.3 0 0 0-.22 3.5c0 1.78.02 2.17.22 3.49A24.1 24.1 0 0 0 21.7 47.94c.73.08 3.87.08 4.6 0a24.22 24.22 0 0 0 8.65-2.53c.4-.2.49-.27.43-.31-.03-.03-1.8-2.4-3.9-5.24l-3.84-5.19-4.81-7.11a688.2 688.2 0 0 0-4.84-7.12c-.02 0-.04 3.16-.05 7.02-.02 6.76-.02 7.04-.1 7.2a.85.85 0 0 1-.42.42c-.15.08-.28.1-.99.1h-.81l-.22-.15a.88.88 0 0 1-.31-.34l-.1-.2.01-9.42.02-9.4.14-.19c.08-.1.24-.22.35-.29.19-.09.27-.1 1.08-.1.95 0 1.11.04 1.36.31.07.08 2.68 4 5.8 8.72l9.46 14.34 3.8 5.76.2-.13c1.7-1.1 3.5-2.68 4.92-4.32a23.89 23.89 0 0 0 5.65-12.27c.2-1.32.22-1.7.22-3.5 0-1.78-.02-2.17-.22-3.49A24.1 24.1 0 0 0 26.37.07c-.45-.04-3.55-.1-3.94-.06zm9.82 14.52a.95.95 0 0 1 .48.55c.03.12.04 2.73.03 8.61v8.44l-1.5-2.28-1.49-2.28v-6.14c0-3.96.02-6.19.05-6.3a.96.96 0 0 1 .46-.59c.2-.1.26-.1 1-.1.7 0 .82 0 .97.09z" fill="#000"/></svg>')
      );
    });
    return $this;
  }

  /**
   * Render the Active Frontend's settings in rows.
   */
  private function renderActiveFrontendSettings()
  {
    $frontend = $this->getActiveFrontend();
    $settings = $frontend->getSettings();
    ?>
    <h3>Active Frontend Settings:</h3>
    <table class="form-table" role="presentation">
      <tbody>
        <?php
        $this->renderConfigRow('URL', $frontend->getUrl());
        $this->renderConfigRow('Auth Secret', $settings['authSecret']);
        $this->renderConfigRow('API Base Path', $settings['apiBasePath']);
        $this->renderConfigRow('API Router Base Path', $settings['apiRouterBasePath']);
        $this->renderConfigRow('Block Preview Path', $settings['blockPreviewPath']);
        $this->renderConfigRow('Deployments', $settings['deployments']);
        ?>
      </tbody>
    </table>
  <?php
  }

  /**
   * Render a config/setting row
   */
  private function renderConfigRow($name, $var)
  {
  ?>
    <tr>
      <th scope="row">
        <?php echo $name ?>
      </th>
      <td><?php $this->renderConfigVariable($var); ?></td>
    </tr>
<?php
  }

  /**
   * Render a config/setting variable. Includes the variable's type in parentheses.
   */
  private function renderConfigVariable($var)
  {
    if (isset($var)) {
      if (gettype($var) === 'boolean') {
        if ($var === TRUE) {
          echo "<span>TRUE</span>";
        }
        if ($var === FALSE) {
          echo "<span>FALSE</span>";
        }
      } else if (gettype($var) === 'string') {
        if (strlen($var) > 0) {
          echo "<span>" . $var . "</span>";
        }
        if (strlen($var) === 0) {
          echo "<span>''</span>";
        }
      } else if (gettype($var) === 'array' || gettype($var) === 'object') {
        echo '<pre>';
        print_r($var);
        echo '</pre>';
      }
      echo "<span style='color: grey;'> (" . gettype($var) . ")</span>";
    } else {
      echo "<span>Unset</span>";
    }
  }

  /**
   * Add a "Xdebug Info" page under the "Tools" menu that prints useful Xdebug dev info. 
   * Only gets added for Admin users. 
   */
  public function addXdebugInfoPage(): static
  {
    add_action('admin_menu', function () {
      add_submenu_page(
        'tools.php',           // Parent page
        'Xdebug Info',         // Menu title
        'Xdebug Info',         // Page title
        'manage_options',      // user "role"
        'php-info-page',       // page slug
        array($this, 'renderXdebugInfoPage') // callback function
      );
    });
    return $this;
  }

  /**
   * Callback for addXdebugInfoPage()->add_submenu_page()
   */
  private function renderXdebugInfoPage()
  {
    $message = '<h2>No Xdebug enabled</h2>';
    if (function_exists('xdebug_info')) {
      /** @disregard */
      xdebug_info();
    } else {
      echo $message;
    }
  }

  // Add browserSync script to wp-admin <head> to enable live reloading upon saving theme files
  public function injectBrowserSyncScript(): static
  {
    add_action('admin_head', function () {
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
    });
    return $this;
  }

  /**
   * Add dynamically-generated CSS to wp-admin's <head>, to style our ThemeColorPicker custom ACF Field using our theme.json's colors
   *
   * @since    0.6.0
   */
  public function injectThemeColorPickerCss(): static
  {
    add_action('admin_head', function () {
      $themeColorPickerCSS = '';
      $color_palette = Utils::get_theme_color_palette();
      if (!empty($color_palette)) {
        foreach ($color_palette as $color) {
          $themeColorPickerCSS .= ".cloakwp-theme-color-picker .acf-radio-list li label input[type='radio'][value='{$color['slug']}'] { background-color: var(--wp--preset--color--{$color['slug']}); }";
        }
      }
      echo "<style id='themeColorPickerACF'>{$themeColorPickerCSS}</style>";
    });
    return $this;
  }

  /*
    Add ability for "editor" user role to edit WP Menus, but hide all other submenus under Appearance (for editors only) -- eg. we don't want clients to be able to switch/deactivate theme 
  */
  public function restrictAppearanceMenuForEditors(): static
  {
    add_action('admin_head', function () {
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
    });
    return $this;
  }

  /**
   * This is required in order for WP Admin > Appearance > Menus page to be visible for new Block themes. 
   */
  public function enableLegacyMenuEditor(): static
  {
    add_action('init', function () {
      register_nav_menus(
        array(
          'nav' => __(''), // this creates a menu location that doesn't serve a purpose other than to get the "Menus" page to become visible in wp-admin
        )
      );
    });
    return $this;
  }

  /**
   * Expand ACF field data returned in REST API; eg. image fields return full image data rather than just an ID. More info: https://www.advancedcustomfields.com/resources/wp-rest-api-integration/
   */
  public function makeAcfRestFormatStandard(): static
  {
    add_filter('acf/settings/rest_api_format', function () {
      return 'standard';
    });
    return $this;
  }

  /*
  Change the JWT token issuer:
  */
  public function modifyJwtIssuer(): static
  {
    // Note: 06/26/2023 I can't remember why this filter was added or if it's really needed
    add_filter('jwt_auth_iss', function () {
      // Default value is get_bloginfo( 'url' );
      return site_url();
    });
    return $this;
  }

  /**
   * This method enables you to override the default JWT expiry date of 7 days to something
   * far in the future, essentially making it never expire (assumes you're using the "JWT 
   * Authentication for WP-API" plugin). Defaults to setting it 500,000 days into the 
   * future -- customize this by passing in an integer.
   */
  public function extendJwtExpiryDate($days_into_future = 500000): static
  {
    add_filter('jwt_auth_expire', function () use ($days_into_future) {
      $seconds_in_a_day = 86400;
      $exp = time() + ($seconds_in_a_day * $days_into_future);
      return $exp;
    }, 10, 1);
    return $this;
  }

  // Give editors access to the Menu tab
  public function enableMenuEditingForEditors(): static
  {
    add_action('admin_init', function () {
      $role = get_role('editor');
      $role->add_cap('edit_theme_options');
    });
    return $this;
  }

  /*
    Remove "Comments" from wp-admin sidebar for all roles.
    Remove "Tools", "Dashboard", and "Yoast SEO" for non-admins
  */
  public function removeIrrelevantAdminPages(): static
  {
    add_action('admin_menu', function () {
      remove_menu_page('edit-comments.php');

      if (!current_user_can('administrator')) { // remove certain pages for non-administrators
        remove_menu_page('tools.php'); // remove "Tools"
        remove_menu_page('index.php'); // remove "Dashboard"

        // remove Yoast SEO
        remove_menu_page('wpseo_dashboard');
        remove_menu_page('wpseo_workouts');
      }
    });
    return $this;
  }

  /*
    Function to remove various options in wp-admin top toolbar (not sidebar)
    Currently used to remove the "Comments" and "View Posts" menu items
  */
  public function removeAdminToolbarOptions(): static
  {
    add_action('wp_before_admin_bar_render', function () {
      global $wp_admin_bar;
      $wp_admin_bar->remove_menu('comments');
      $wp_admin_bar->remove_menu('archive');
    });
    return $this;
  }

  /**
   * By default, when you search for a block in the Gutenberg Block Inserter, recommendations for 
   * 3rd party block plugins come up, asking if you want to install them; it's annoying, creates
   * the possibility for plugin hell caused by non-developers, and most 3rd party plugins will
   * be incompatible with headless/CloakWP anyway... so this method removes this feature. 
   */
  public function disableBlockPluginRecommendations(): static
  {
    remove_action('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
    return $this;
  }
  /**
   * By default, Yoast SEO's metabox gets displayed above ACF Field Groups when editing a post (not ideal).
   * This method pushes it below any ACF Field Groups.
   */
  public function deprioritizeYoastSeoMetabox(): static
  {
    add_action('wpseo_metabox_prio', function () {
      return 'low';
    });
    return $this;
  }

  /**
   */
  public function enableCors(): static
  {
    // enable cross-origin requests from the decoupled frontend
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
      $allowed_origin = $this->getActiveFrontend()->getUrl() ?? '*';

      // remove port from http://localhost URLs, because Access-Control-Allow-Origin doesn't work with them
      if (str_contains($allowed_origin, 'localhost')) {
        $parsedUrl = parse_url($allowed_origin);

        if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
          $allowed_origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        }
      }

      $access_control_allow_origin = sprintf('Access-Control-Allow-Origin: %s', $allowed_origin);
      header($access_control_allow_origin);
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: cache-control, X-WP-Nonce, Content-Type, Authorization, Access-Control-Allow-Headers, Accept');
      header('Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages', false);
    });

    // whitelist the Link header for HEAD requests to allow the WPAPI package's auto-discovery feature to work:
    add_action('send_headers', function () {
      if (!did_action('rest_api_init') && $_SERVER['REQUEST_METHOD'] == 'HEAD') {
        header('Access-Control-Expose-Headers: Link');
        header('Access-Control-Allow-Methods: HEAD');
      }
    });

    return $this;
  }


  /**
   * Hides certain wp-admin notices created by plugins that aren't relevant for headless use-case
   */
  private function cleanAdminNotices(): static
  {
    // hide Bedrock warning about search engine indexing:
    add_filter('roots/bedrock/disallow_indexing_admin_notice', '__return_false');

    // disable WP core, plugin, and theme update notices (because we manage these via Composer not wp-admin):
    $updateFilters = ['pre_site_transient_update_core', 'pre_site_transient_update_plugins', 'pre_site_transient_update_themes'];
    foreach ($updateFilters as $filter) {
      add_filter($filter, function () {
        global $wp_version;
        return (object) array('last_checked' => time(), 'version_checked' => $wp_version);
      });
    }

    return $this;
  }


  /**
   * Remove and add certain theme support
   */
  private function applyRecommendedThemeSupports(): static
  {
    // We use the after_setup_theme hook with a priority of 11 to load after the parent theme, which will fire on the default priority of 10
    add_action('after_setup_theme', function () {
      remove_theme_support('core-block-patterns'); // disable default Gutenberg Patterns
      add_theme_support('post-thumbnails'); // enable featured images
      add_post_type_support('page', 'excerpt'); // enable page excerpts
    }, 11);

    return $this;
  }


  /**
   * Provide an array of PostType class instances, defining your Custom Post Types and their configurations.
   */
  public function postTypes(string|array $postTypesOrPath): static
  {
    $postTypes = [];
    if (is_string($postTypesOrPath)) {
      // user provided a directory of post types rather than an array
      if (file_exists($postTypesOrPath) && is_dir($postTypesOrPath)) {
        // Scan the directory and get file names
        $files = scandir($postTypesOrPath);

        // Loop through the file array
        foreach ($files as $file) {
            // Skip '.' and '..'
            if ($file === '.' || $file === '..') continue;

            $postObject = require $postTypesOrPath . '/' . $file;
            if ($postObject && is_object($postObject)) $postTypes[] = $postObject;
        }
      } else {
        throw new InvalidArgumentException("You provided a string argument, which postTypes() expects to be a directory path, but the directory does not exist.");
      }
    } else {
      $postTypes = $postTypesOrPath;
    }

    $validPostTypes = [];

    // validate & register each post type
    foreach ($postTypes as $postType) {
      if (!is_object($postType) || !method_exists($postType, 'register')) continue; // invalid post type

      $validPostTypes[] = $postType;
      $postType->register();
    }

    // save all valid PostType objects into the CloakWP singleton's state, so anyone can access/process them
    $this->postTypes = array_merge($this->postTypes, $validPostTypes); // todo: might need a custom merge method here to handle duplicates?

    return $this;
  }

  public function getPostType(string $postTypeSlug)
  {
    return array_filter($this->postTypes, fn ($postType) => $postType->slug == $postTypeSlug);
  }
  public function getPostTypeByPostId(int $postId)
  {
    return $this->getPostType(get_post_type($postId));
  }

  /**
   * Either provide an array of CloakWP\ACF\Block class instances, or a string pointing to the directory where your
   * CloakWP\ACF\Block class instances live; if the latter, your directory structure is expected to follow this:
   *   {blocks-directory-name}/
   *     -- {block-name}/
   *       -- block.json
   *       -- block.php
   *     -- {block-name}/
   *       -- block.json
   *       -- block.php
   *     ...
   * 
   * While Block classes can register themselves, the benefit of registering through the CloakWP class as an intermediary 
   * is that it (1) provides a smart file-based registration system which is arguably cleaner/nicer, and (2) automatically 
   * enables CloakWP's decoupled iframe preview feature for each block, among other sensible defaults.
   */
  public function blocks(string|array $blocksOrPath): static
  {
    $blocks = [];
    // Handle case where user provides a file directory string pointing at where their Block instances live (rather than a direct array of Block instances)
    if (is_string($blocksOrPath)) {
      if (file_exists($blocksOrPath) && is_dir($blocksOrPath)) {
        // Get a list of all subdirectories in the root directory
        $subdirectories = glob($blocksOrPath . '/*', GLOB_ONLYDIR);

        foreach ($subdirectories as $subdirectory) {
          // Check if "block.json" and "block.php" files exist in the subdirectory
          $jsonFile = $subdirectory . '/block.json';
          $phpFile = $subdirectory . '/block.php';

          if (file_exists($jsonFile) && file_exists($phpFile)) {
            $blockObject = require $phpFile;
            if ($blockObject && is_object($blockObject)) $blocks[] = $blockObject;
          } else {
            continue; // not a valid block directory, so skip it
          }
        }
      } else {
        throw new InvalidArgumentException("You provided a string argument, which blocks() expects to be a directory path, but the directory does not exist.");
      }
    } else {
      $blocks = $blocksOrPath;
    }

    foreach ($blocks as $block) {
      if (!is_object($block) || !method_exists($block, 'getFieldGroupSettings')) continue; // invalid block

      // Make each ACF block use CloakWP's iframe preview render template
      $block->args([
        'render_callback' => array($this, 'renderIframePreview') // idea here is to remove/abstract the need for dev to specify { ... "renderCallback": "function_name" ... } in block.json
      ]);

      if (!$block->emptyFieldsMessage) $block->emptyFieldsMessage('This block has no fields/controls. Simply drop it wherever you wish to display it.');
      
      // now register each block
      $block->register();
    }

    $this->blocks = array_merge($this->blocks, $blocks); // todo: might need a custom merge method here to handle duplicates?

    return $this;
  }

  public static function renderIframePreview($block, $content, $is_preview, $post_id, $wp_block, $context)
  {
    include(Utils::cloakwp_plugin_path() . '/block-preview.php');
  }

  /**
   * Define which core blocks to include. Any that aren't defined will be excluded from use in Gutenberg.
   * You can also specify post type rules, so that certain blocks are only allowed on certain post types. 
   */
  public function enabledCoreBlocks(array|bool $blocksToInclude): static
  {
    add_filter('allowed_block_types_all', function ($allowed_block_types, $editor_context) use ($blocksToInclude) {
      return $this->getAllowedBlocks($allowed_block_types, $editor_context, $blocksToInclude);
    }, 10, 2);

    return $this;
  }

  private function getAllowedBlocks(bool|array $allowed_block_types, object $editor_context, array|bool $blocks): bool|array
  {
    $registeredBlockTypes = WP_Block_Type_Registry::get_instance()->get_all_registered();
    $registeredBlockTypeKeys = array_keys($registeredBlockTypes);

    $currentPostType = $editor_context->post->post_type;
    $finalAllowedBlocks = array_filter($registeredBlockTypeKeys, fn ($b) => str_starts_with($b, 'acf/')); // start with only ACF blocks, then we'll add user-provided blocks to this list
    if (is_array($blocks)) {
      foreach ($blocks as $key => $value) {
        if (is_string($value)) {
          $finalAllowedBlocks[] = $value;
        } else if (is_array($value)) {
          $blockName = $key;
          if (isset($value['postTypes'])) {
            if (is_array($value['postTypes'])) {
              foreach ($value['postTypes'] as $postType) {
                if ($currentPostType == $postType) {
                  $finalAllowedBlocks[] = $blockName;
                }
              }
            } else {
              throw new InvalidArgumentException("postTypes argument must be an array of post type slugs");
            }
          } else {
            $finalAllowedBlocks[] = $blockName;
          }
        } else {
          continue; // current $block is invalid, move on to next one.
        }
      }
    } else if (is_bool($blocks)) {
      return $blocks;
    } else {
      throw new InvalidArgumentException("Invalid argument type passed to coreBlocks() -- must be of type array or boolean.");
    }

    return $finalAllowedBlocks;
  }

  /**
   * Provide an array of Frontend class instances, defining your custom configuration for one or more decoupled frontends.
   */
  public function frontends(array $frontends): static
  {
    $this->frontends = $frontends;
    return $this;
  }

  /**
   * Retrieve a specific Frontend class instance via key (you provided the keys in Frontend::make(...)).
   */
  public function getFrontend(string $key)
  {
    foreach ($this->frontends as $frontend) {
      if ($frontend->getKey() == $key) return $frontend;
    }
    return null;
  }

  /**
   * getActiveFrontend returns the currently selected Frontend instance. 
   * For now, it just returns the first instance provided to CloakWP->frontends([...]),
   * but the intention for the future is to provide a "Frontend Switcher" in the wp-admin UI
   * that allows switching between any of the frontends provided to CloakWP->frontends([...]),
   * and this method will return that currently active frontend. All wp-admin links referencing 
   * the frontend will point to the "active" frontend's URL.
   */
  public function getActiveFrontend(): Frontend | null
  {
    // TODO: in future, need to build a "frontend switcher" as described above, and return the currently selected frontend here
    if (is_array($this->frontends)) return $this->frontends[0];
    return null;
  }

  /**
   * registerAuthEndpoint creates a REST Endpoint that our frontend can ping to determine if the
   * current site visitor is logged in to WP. The `cloakwp` NPM package provides a helper for properly
   * passing cookies to determine the auth status.
   */
  public function registerAuthEndpoint(): static
  {
    add_action('rest_api_init', function () {
      register_rest_route('jwt-auth/v1', '/is-logged-in', array(
        'methods' => 'GET',
        'callback' => function () {
          $isLoggedIn = false;
          if ($_COOKIE && is_array($_COOKIE)) {
            if (array_key_exists(LOGGED_IN_COOKIE, $_COOKIE))
              $isLoggedIn = true;
          }
          Utils::write_log("Is user logged into WP? $isLoggedIn");
          return rest_ensure_response($isLoggedIn);
        },
        'permission_callback' => function ($request) {
          $auth_header = ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( $_SERVER['HTTP_AUTHORIZATION'] ) : false;

          /* Double check for different auth header string (server dependent) */
          if ( ! $auth_header ) {
            $auth_header = ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? sanitize_text_field( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) : false;
          }

          if ( ! $auth_header ) {
            return false; // no token provided, don't give access
          }

          /**
           * Check if the auth header is not bearer, if so, return false
           */
          if ( strpos( $auth_header, 'Bearer' ) !== 0 ) {
            return false;
          }

          /**
           * Check the token from the headers.
           */
          $JWT = new \Jwt_Auth_Public('jwt_auth', '2');
          $token = $JWT->validate_token( new \WP_REST_Request(), $auth_header );

          if ( is_wp_error( $token ) ) {
            return $token; // return error
          }

          // User provided valid JWT token, so we return true to let them in:
          return true;


          /*
            if JWT is passed as header, is_user_logged_in() should return true, otherwise false;
            but for some reason this only works if the route namespace is 'jwt-auth/v1'.
            TODO: look into making this work on routes with custom namespaces -- might need to fork the JWT Auth WP plugin
          */
          // Utils::write_log("In permission callback");
          // Utils::write_log($request);
          // return is_user_logged_in();
        }
      ));
    });

    return $this;
  }


  /* 
    We purposely separate blocks() from init() in order for the 'InnerBlocks' field type to be able 
    to pull in blocks classes defined by blocks() before they get processed/registered by init()
  */
  // public function init(): void
  // {
  //   add_action('acf/init', function () {
  //     // register all ACF Blocks (using Extended ACF package):
  //     $blocks = Utils::array_deep_copy($this->blocks); // Copy $this->blocks to another variable to prevent modifying the original value of $this->blocks (fixes bugs related to InnerBlocks field)
  //     foreach ($blocks as $block) {
  //       register_extended_field_group($block);
  //     }
  //   });
  // }


  /**
   * Define the locale for this plugin for internationalization.
   *
   * Uses the CloakWP_i18n class in order to set the domain and to register the hook
   * with WordPress.
   *
   * @since    0.6.0
   * @access   private
   */
  private function setLocale()
  {
    add_action('plugins_loaded', function () {
      load_plugin_textdomain(
        'cloakwp',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
      );
    });
  }

  /**
   * The name of the plugin used to uniquely identify it within the context of
   * WordPress and to define internationalization functionality.
   */
  public function getPluginName()
  {
    return $this->plugin_name;
  }

  public function getBlocks()
  {
    return $this->blocks;
  }

  public function getPostTypes()
  {
    return $this->postTypes;
  }

  /**
   * Retrieve the version number of the plugin.
   */
  public function getVersion()
  {
    return $this->version;
  }
}
