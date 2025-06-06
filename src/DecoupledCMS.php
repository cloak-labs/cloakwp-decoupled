<?php

namespace CloakWP;

use CloakWP\Core\CMS;
use CloakWP\Core\Utils;
use CloakWP\Core\Enqueue\Stylesheet;
use CloakWP\BlockParser\BlockParser;
use CloakWP\VirtualFields\VirtualField;
use CloakWP\HookModifiers;

use CloakWP\API\FrontpageEndpoint;
use CloakWP\API\MenusEndpoint;
use CloakWP\API\OptionsEndpoint;

use CloakWP\JWTAuth\JWTAuthRegistrar;
use CloakWP\JWTAuth\JWTAuth;
use WP_Error;
use WP_REST_Response;

/* 
  TODO: consider breaking this class up into more standalone "services", and use a service container design pattern (like Laravel) to manage dependency injection. 
  Would enable users to swap in their own service implementations, for things like:
    - REST API Authentication (eg. perhaps they don't want to use JWTs, or our specific JWT Auth implementation)
    - Image formatting
    - Block parsing
    - Virtual field management
    - Custom REST API Endpoint management

  Just not sure if this added complexity is worth it, to be honest. Currently users can just extend this class and override certain methods.
*/

class DecoupledCMS extends CMS
{
  /**
   * Stores the DecoupledCMS Singleton instance.
   */
  private static $instance;

  /**
   * Stores an instance of the BlockParser class.
   */
  private BlockParser $blockParser;

  /**
   * Stores one or more DecoupledFrontend instances.
   */
  protected array $frontends = [];

  /**
   * Stores one or more ACF Block instances.
   */
  protected array $blocks = [];

  /**
   * Stores the maintenance mode state.
   */
  public static bool $isMaintenanceMode = false;

  /**
   * Define the core functionality of the plugin.
   */
  public function __construct()
  {
    parent::__construct();

    $isCore = self::$context->isCore();
    $isAdmin = self::$context->isBackoffice();
    $isRest = self::$context->isRest();

    if ($isAdmin) {
      // Enqueue CloakWP custom CSS/JS assets:
      $this->assets([
        // some style improvements for the Gutenberg editor, including styles for the decoupled ACF Block Iframe previewer
        Stylesheet::make("cloakwp_gutenberg_styles")
          ->hooks(['enqueue_block_editor_assets'])
          ->src(WP_PLUGIN_URL . "/decoupled/css/editor.css")
          ->version(\WP_ENV == "development" ? filemtime(WP_PLUGIN_DIR . '/decoupled/css/editor.css') : '1.1.23')
      ]);
    }

    if ($isRest) {
      // Register CloakWP Decoupled's custom REST API endpoints:
      MenusEndpoint::register();
      FrontpageEndpoint::register();
      OptionsEndpoint::register();
    }

    if ($isCore) {
      add_action('init', function () {
        // Set up the default BlockParser and its filters
        $this->blockParser = new BlockParser();
      });

      HookModifiers::make(['post_type'])
        ->forFilter('cloakwp/eloquent/posts')
        ->register();
    }

    $this->bootstrap();
  }

  /**
   * Returns the DecoupledCMS Singleton instance.
   */
  public static function getInstance(): self
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * This function gets called when initiating the CloakWP instance via getInstance().
   * We extracted this logic into this separate method so that users can extend DecoupledCMS 
   * and customize the bootstrapping process without having to also redeclare __construct(),
   * which most likely folks will NOT want to customize.
   */
  private function bootstrap()
  {
    // Core functionality needed in all contexts
    if (self::$context->isCore()) {
      $this
        ->registerVirtualFields()
        ->enableCors()
        ->enableSVGsForACF()
        ->enableImageFormatting()
        ->enableDecoupledPreview()
        ->enablePostFiltersForACF()
        ->replaceFrontendLinks()
        ->disableWpTexturize()
        ->disableCapitalPDangit()
        ->disableEmojis()
        // ->disableAssetUrlVersioning()
        ->disableJpegCompression();
    }

    // Admin dashboard customizations:
    if (self::$context->isBackoffice()) {
      $this
        ->enableFeaturedImages()
        ->enableExcerpts()
        // ->enableBrowserSync()
        ->enableXdebugInfoPage()
        ->enableMenusForEditors()
        ->disableLegacyCustomizer()
        ->disableWidgets()
        ->disableComments()
        ->disableRoles()
        ->disableFontLibrary()
        ->disableOpenVerse()
        ->disableDashboard()
        ->disableToolsForEditors()
        ->disablePostsArchiveToolbarMenu()
        ->disableUpdateNotices()
        ->disableDashboardWidgets()
        ->disableSearchEngineIndexingWarnings();

      // Reduce default heartbeat interval to prevent overwhelming the server, especially in development:
      if (WP_ENV == 'development') {
        $this->throttleHeartbeat(300);
      } else {
        $this->throttleHeartbeat(60);
      }

      // Yoast SEO plugin config:
      if (function_exists('is_plugin_active')) {
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
          $this->disableYoastForEditors()
            ->disableYoastSitemap()
            ->disableYoastBlocks()
            ->disableYoastToolbarMenu()
            ->deprioritizeYoastMetabox()
            ->streamlineYoastInDevelopment();
        }
      }
    }

    if (self::$context->isLogin()) {
      $this->replaceLoginLogoLink();
    }

    // Block editor customizations:
    add_action('current_screen', function () {
      if ($this->isBlockEditor()) {
        $this
          ->disableSvgFilters()
          ->disableBlockPluginRecommendations()
          ->disableDefaultPatterns();
      }
    }, 11);

    // Features only needed for REST API requests
    if (self::$context->isRest()) {
      $this
        ->enableAuthViaJWT()
        ->extendExpiryDateForJWT()
        ->enableHtmlEntityDecodingForRestApi()
        ->enableLoginStatusEndpoint()
        ->enableStandardRestFormatForACF()
        ->enableCleanParamForRestApi();
    }

    // TODO: some of these methods are too opinionated; we should extend DecoupledCMS in our _base_theme and move those methods there, so they only apply to Pillar Labs' own projects.
    // $this
    //   ->replaceFrontendLinks()
    //   ->replaceLoginLogoLink()
    //   ->registerVirtualFields()
    //   ->extendExpiryDateForJWT()
    //   ->enableLoginStatusEndpoint()
    //   ->enableImageFormatting()
    //   ->enableDecoupledPreview()
    //   ->enableAuthViaJWT()
    //   ->enableStandardRestFormatForACF()
    //   ->enablePostFiltersForACF()
    //   ->enableSvgUploads()
    //   ->enableSVGsForACF()
    //   ->enableCleanParamForRestApi()
    //   ->enableMenusForEditors()
    //   ->enableFeaturedImages()
    //   ->enableExcerpts()
    //   ->enableBrowserSync()
    //   ->enableXdebugInfoPage()
    //   ->enableCors()
    //   ->enableHtmlEntityDecodingForRestApi()
    //   ->disableWpTexturize()
    //   ->disableCapitalPDangit()
    //   ->disableBlockPluginRecommendations()
    //   ->disableLegacyCustomizer()
    //   ->disableWidgets()
    //   ->disableComments()
    //   ->disableEmojis()
    //   ->disableSvgFilters()
    //   ->disableAssetUrlVersioning()
    //   ->disableRoles()
    //   ->disableFontLibrary()
    //   ->disableOpenVerse()
    //   ->disableJpegCompression()
    //   ->disableDashboard()
    //   ->disableLazyLoading()
    //   ->disableDefaultPatterns()
    //   ->disableToolsForEditors()
    //   ->disableYoastForEditors()
    //   ->disableYoastSitemap()
    //   ->disableYoastBlocks()
    //   ->disableYoastToolbarMenu()
    //   ->disablePostsArchiveToolbarMenu()
    //   ->disableUpdateNotices()
    //   ->disableDashboardWidgets()
    //   ->disableSearchEngineIndexingWarnings()
    //   ->deprioritizeYoastMetabox()
    //   ->streamlineYoastInDevelopment();
  }

  /**
   * Enable decoupled maintenance mode. When enabled, the REST API will only allow requests from within wp-admin (ensuring
   * the editor still works) or localhost (enabling you to work on the frontend locally while in maintenance mode). This
   * ensures that production ISR requests from your decoupled frontend fail during maintenance, preventing static 
   * pages from being regenerated, which is useful when purposefully breaking things in WordPress for a temporary period.
   */
  public function enableMaintenanceMode(): static
  {
    self::$isMaintenanceMode = true;

    add_action('rest_api_init', function () {
      add_filter('rest_pre_dispatch', function ($result, $server, $request) {
        // Allow internal WP requests (those with a valid nonce)
        $headers = getallheaders();
        if (!empty($headers['X-WP-Nonce'])) {
          return $result;
        }

        // Allow requests from authenticated WordPress users
        if (!empty($headers['Cookie']) && (
          strpos($headers['Cookie'], 'wordpress_logged_in_') !== false ||
          strpos($headers['Cookie'], 'wordpress_sec_') !== false
        )) {
          return $result;
        }

        // Optionally allow requests from your WordPress domain itself
        if (!empty($headers['Origin']) && strpos($headers['Origin'], home_url()) !== false) {
          return $result;
        }

        // Allow requests with a special bypass parameter
        $bypassParam = isset($_GET['bypass']) ? $_GET['bypass'] : $request->get_param('bypass');

        // Check for the presence of the unique bypass parameter
        if ($bypassParam !== null) {
          return $result;
        }

        // Otherwise, kill the external request
        return new WP_Error('maintenance_mode', 'Site under maintenance', ['status' => 503]);
      }, 10, 3);
    });

    return $this;
  }

  /**
   * Sets the BlockParser instance to be used for parsing blocks.
   *
   * @param \CloakWP\BlockParser\BlockParser $blockParser The BlockParser instance to set.
   * @return static
   */
  public function blockParser(BlockParser $blockParser): static
  {
    $this->blockParser = $blockParser;
    return $this;
  }

  /**
   * This method serves as a single point-of-entry for enabling all decoupled image formatting functionality. 
   * It's simply a wrapper around all instance-specific image-formatting methods.
   * 
   * See the description of the formatImage() method for more information on why image formatting is necessary for decoupled apps.
   */
  public function enableImageFormatting(): static
  {
    $this->enableImageFormattingForAttachments();
    $this->enableImageFormattingForACF();
    return $this;
  }

  /**
   * By default, WordPress exposes images via the REST API as image IDs, which is not very useful for decoupled frontends -- it
   * requires making a separate/additional REST API request for each image to get its URL, size, alt text, etc. This method serves as
   * our source-of-truth for formatting all image data for the REST API. On its own, it does not modify REST API responses -- other methods
   * (enableImageFormattingForACF(), enableImageFormattingForAttachments(), etc.) hook into the necessary places to modify image data using this method.
   */
  public static function formatImage(mixed $imageId)
  {
    if (!$imageId) return $imageId;
    if (is_array($imageId)) return $imageId;

    // Handle case where $imageId is actually an image URL
    if (is_string($imageId) && filter_var($imageId, FILTER_VALIDATE_URL)) {
      // Convert URL to post ID
      $found_id = attachment_url_to_postid($imageId);
      if ($found_id) {
        $imageId = $found_id;
      } else {
        // If we couldn't find a matching attachment, return a minimal format
        $imageSize = @getimagesize($imageId);
        return [
          'full' => [
            'src' => $imageId,
            'width' => $imageSize[0],
            'height' => $imageSize[1]
          ],
          'alt' => null,
          'caption' => null
        ];
      }
    }

    $imageId = intval($imageId); // coerces strings into integers if they start with numeric data

    $result = [];

    // IMPORTANT: the array of sizes must be ordered from smallest to largest in order for exclusion logic further below to work properly: 
    $sizes = apply_filters('cloakwp/image_format/sizes', ['medium', 'large', 'full'], $imageId);

    foreach ($sizes as $size) {
      $img = wp_get_attachment_image_src($imageId, $size);
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

    // Now we remove larger sizes if they have the same width as a previous size (i.e. the original uploaded image was small, so it's unnecassary to include larger versions if the size doesn't actually change):
    $previousWidth = null;
    $keepSizes = [];

    foreach ($sizes as $size) {
      if (isset($result[$size]) && $result[$size]) {
        if ($previousWidth === null) {
          $previousWidth = $result[$size]['width'];
          $keepSizes[] = $size;
        } else {
          if ($result[$size]['width'] === $previousWidth) {
            // Stop processing further sizes
            break;
          } else {
            $previousWidth = $result[$size]['width'];
            $keepSizes[] = $size;
          }
        }
      }
    }

    // Keep only the sizes that passed the width check
    $filteredResult = [];
    foreach ($keepSizes as $size) {
      $filteredResult[$size] = $result[$size];
    }

    $alt_desc = get_post_meta($imageId, '_wp_attachment_image_alt', true);
    $filteredResult['alt'] = $alt_desc;
    $filteredResult['caption'] = get_post_field('post_excerpt', $imageId);

    return $filteredResult;
  }


  /**
   * Ensures ACF images are formatted using our `formatImage()` method so they can be consumed more easily by decoupled frontends.
   */
  public function enableImageFormattingForACF(): static
  {
    add_filter('acf/format_value/type=image', function ($value, $post_id, $field) {
      if (is_array($value)) return $this->formatImage($value['ID']);
      return $value;
    }, 20, 3);

    add_filter('acf/format_value/type=gallery', function ($value, $post_id, $field) {
      if (!is_array($value)) return $value;

      $gallery = [];
      foreach ($value as $image) {
        if (is_array($image)) {
          $gallery[] = $this->formatImage($image['ID']);
        } else {
          $gallery[] = $this->formatImage($image);
        }
      }
      return $gallery;
    }, 99, 3);

    return $this;
  }

  /**
   * Ensures Attachment post types (aka Media Library images) are formatted using our `formatImage()` 
   * method, so that they can be consumed more easily by decoupled frontends.
   */
  public function enableImageFormattingForAttachments(): static
  {
    add_filter('cloakwp/eloquent/posts/post_type=attachment', function ($attachments) {
      $formatted = [];
      foreach ($attachments as $attachment) {
        $formatted[] = $this->formatImage($attachment['ID']);
      }

      return $formatted;
    });

    return $this;
  }

  /**
   * Registers post virtual fields that are convenient/necessary for decoupled frontends.
   */
  public function registerVirtualFields(): static
  {
    add_action("init", function () {
      $customPostTypes = Utils::getCustomPostTypes();
      $publicPostTypes = Utils::getPublicPostTypes();
      $allPostTypes = array_merge($customPostTypes, $publicPostTypes);
      $gutenbergPostTypes = Utils::getEditorPostTypes();

      /**
       * `pathname` -- a virtual field on all PUBLIC posts (i.e. all posts that map to a front-end page). This allows
       * frontends to more easily determine the full URL path of a post by simply accessing the `pathname` property. 
       */
      register_virtual_fields($publicPostTypes, [
        VirtualField::make('pathname')
          ->value(fn($post) => Utils::getPostPathname(is_array($post) ? $post['id'] : $post->ID))
      ]);

      // add some virtual fields to all CPTs + public built-in post types:
      register_virtual_fields($allPostTypes, [
        /**
         * `featured_image` -- makes it easy for frontends to access a post's full featured image data (the default REST 
         * responses only include the image ID); prevents having to make a separate/additional REST API request.
         */
        VirtualField::make('featured_image')
          ->value(function ($post) {
            if ($post === null) return;
            $post_id = is_array($post) ? $post['id'] : $post->ID;
            $image_id = get_post_thumbnail_id($post_id);
            return $this->formatImage($image_id);
          }),

        /**
         * `author` -- makes it easy for frontends to access a post's full author data (the default REST responses
         *  only include the author ID); prevents having to make a separate/additional REST API request.
         */
        VirtualField::make('author')
          ->value(function ($post) {
            if ($post === null) return;
            $authorId = is_array($post) ? $post['author'] : $post->post_author;
            return Utils::getPrettyAuthor($authorId);
          }),

        /**
         * `acf` -- makes it easy for frontends to access a post's ACF field values;
         */
        VirtualField::make('acf')
          ->value(function ($post) {
            if ($post === null) return;
            $postId = is_array($post) ? $post['id'] : $post->ID;
            return get_fields($postId);
          }),

        /**
         * `taxonomies` -- makes it easy for frontends to access a post's taxonomies data (the default REST responses
         *  only include the taxonomy slugs); prevents having to make separate/additional REST API request.
         */
        VirtualField::make('taxonomies')
          ->value(function ($post) {
            if ($post === null) return;

            $post = Utils::asPostObject($post);

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
          /**
           * `blocks_data` -- exposes Gutenberg block data (parsed to JSON) to the REST API, enabling frontends to easily render blocks however they see fit.
           */
          VirtualField::make('blocks_data')
            ->value(function ($post) {
              if (!$post) return [];
              return $this->blockParser->parseBlocksFromPost($post);
            })
            ->excludeFrom(['core', 'acf'])
        ]);
      }
    }, 99);

    return $this;
  }

  /**
   * This method allows you to add a URL parameter `clean` to any WP REST API request, which will 
   * remove fields that are usually unused by decoupled frontends. It makes REST responses
   * nicer to look at and quicker to mentally parse when debugging in the browser.
   */
  public function enableCleanParamForRestApi(): static
  {
    $cleanFn = function (WP_REST_Response|WP_Error $response, $post, $context) {
      // First check if the REST response is an error:
      if (is_wp_error($response)) {
        return $response;
      }

      // Check if the 'clean' parameter is present and not set to false. This ensures that we don't break stuff that core WordPress or 3rd party plugins/themes expect to be present in the REST API.
      if (!isset($_GET['clean']) || $_GET['clean'] === 'false') {
        return $response;
      }

      $original_data = $response->data;
      $modified_data = $response->data;

      unset(
        $modified_data['date_gmt'],
        $modified_data['modified_gmt'],
        $modified_data['featured_media'],
        $modified_data['comment_status'],
        $modified_data['ping_status'],
        $modified_data['guid'],
        $modified_data['content'],
        // Remove categories & tags in favour of "taxonomies" virtual field added in registerVirtualFields() method:
        $modified_data['categories'],
        $modified_data['tags'],
      );

      // Remove footnotes if it's empty:
      if (isset($modified_data['meta']) && $modified_data['meta']['footnotes'] == "") unset($modified_data['meta']);

      if (isset($response->data['title'])) $modified_data['title'] = html_entity_decode($response->data['title']['rendered'], ENT_QUOTES, 'UTF-8');
      if (isset($modified_data['excerpt']['rendered'])) $modified_data['excerpt'] = html_entity_decode($response->data['excerpt']['rendered'], ENT_QUOTES, 'UTF-8');

      // Apply a filter to the final modified data so users can customize further (eg. they can remove more fields, and/or add back in some that we removed above)
      $final_data = apply_filters('cloakwp/clean_rest_response', $modified_data, $original_data);

      $response->data = $final_data;
      return $response;
    };

    add_action("init", function () use ($cleanFn) {
      $publicPostTypes = Utils::getPublicPostTypes();
      $customPostTypes = Utils::getCustomPostTypes();
      $allPostTypes = array_merge($customPostTypes, $publicPostTypes);
      $allPostTypes[] = 'revision'; // make sure "revisions" responses also get cleaned in same way

      foreach ($allPostTypes as $postType) {
        add_filter("rest_prepare_{$postType}", $cleanFn, 50, 3);
      }
    }, 99);


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

    // chop off domain portion of internal links within menu items:
    add_filter('cloakwp/eloquent/model/menu_item/formatted_meta', function ($meta) {
      if ($meta['link_type'] != 'custom') {
        $url = $meta['url'];
        $frontendUrl = DecoupledCMS::getInstance()->getActiveFrontend()->getUrl();
        $url = str_replace($frontendUrl, "", $url);
        $meta['url'] = untrailingslashit($url);
      }

      return $meta;
    }, 10, 2);

    if (self::$context->isBackoffice()) {
      // Override the href for the site name & view site links in the wp-admin top toolbar, and open links in new tab:
      add_action('admin_bar_menu', function (\WP_Admin_Bar $wp_admin_bar) {
        // Get references to the 'view-site' and 'site-name' nodes to modify.
        $view_site_node = $wp_admin_bar->get_node('view-site');
        $site_name_node = $wp_admin_bar->get_node('site-name');

        if ($view_site_node && $site_name_node) {
          // Change targets
          $view_site_node->meta['target'] = '_blank';
          $site_name_node->meta['target'] = '_blank';

          // Change hrefs to our frontend URL
          $url = $this->getActiveFrontend()->getUrl();
          $view_site_node->href = $url;
          $site_name_node->href = $url;

          // Update Nodes
          $wp_admin_bar->add_node((array)$view_site_node);
          $wp_admin_bar->add_node((array)$site_name_node);
        }
      }, 80);
    }

    return $this;
  }

  /**
   * Given a default WP post permalink, this returns the decoupled frontend URL for that particular post (used with filters)
   */
  public function convertToDecoupledUrl($permalink, $post): string
  {
    $decoupledFrontend = $this->getActiveFrontend();
    if ($decoupledFrontend) $decoupledPostUrl = $decoupledFrontend->getUrl();
    else return $permalink ? home_url() . $permalink : home_url();

    if ($permalink) {
      // str_replace below ensures that the permalink path gets appended to the active frontend url:
      $decoupledPostUrl = str_replace(home_url(), $decoupledPostUrl,  $permalink);
    }

    $decoupledPostUrl = apply_filters('cloakwp/decoupled_post_link', $decoupledPostUrl, $permalink, $post);
    return $decoupledPostUrl;
  }

  /** 
   * Hijack the default WordPress preview system so that all preview links initiate and redirect you 
   * to preview mode on your decoupled frontend. If you're using CloakWP.js on your frontend, and you 
   * have the CloakWP API Router configured, this decoupled preview mode should just work.
   */
  public function enableDecoupledPreview(): static
  {
    // Modify 'preview' links on posts/pages to point to this frontend URL
    add_filter('preview_post_link', function ($preview_link, $post) {
      return $this->getActiveFrontend()->getPostPreviewUrl($post);
    }, 10, 2);

    /* 
      Redirect page visits in WP's built-in preview mode to our decoupled frontend preview 
      page --> this is in addition to our 'preview_post_link' filter above that changes the 
      preview link (which doesn't work all the time due to known bugs).
   */
    add_action('template_redirect', function () {
      $this->getActiveFrontend()->redirectToFrontendPreview();
    });

    return $this;
  }

  /**
   * Enables expanded ACF field data in REST API responses; eg. image fields return full image data rather than just an ID.
   * More info: https://www.advancedcustomfields.com/resources/wp-rest-api-integration/
   */
  public function enableStandardRestFormatForACF(): static
  {
    add_filter('acf/settings/rest_api_format', function () {
      return 'standard';
    });
    return $this;
  }

  /**
   * Decode post properties in REST responses to avoid issues with JS frameworks like React not properly rendering HTML entities.
   * For example, if your post title includes the "&" character, and you don't decode the property, it will be rendered as "&amp;" 
   * in React. It's easiest to decode the strings "at the source" (i.e. server-side rather than client-side), which also 
   * ensures consistency in cases where you might have multiple clients/frontends.
   */
  public function enableHtmlEntityDecodingForRestApi(): static
  {
    add_filter('rest_prepare_post', function ($response, $post, $request) {
      /* 
        This filter allows you to specify which properties of a post should be decoded using html_entity_decode.
        By default, it decodes the "title.rendered" property, but you can add as many properties as you want, using
        dot notation for nested properties.
      */
      $properties = apply_filters('cloakwp/decode_properties', ['title.rendered'], $response, $post, $request);

      foreach ($properties as $property) {
        $this->decodeProperty($response->data, $property);
      }

      return $response;
    }, 10, 3);
    return $this;
  }

  private function decodeProperty(&$data, $property)
  {
    $parts = explode('.', $property);
    $current = &$data;

    foreach ($parts as $part) {
      if (!is_array($current) || !isset($current[$part])) {
        // Property doesn't exist in the response, so we can't decode it
        return;
      }
      $current = &$current[$part];
    }

    if (is_string($current)) {
      $current = html_entity_decode($current, ENT_QUOTES, 'UTF-8');
    }
  }
  /**
   * Enable authentication via JWT
   */
  public function enableAuthViaJWT(): static
  {
    // initialize the JWT Setup class, which registers JWT auth routes, enables CORS support, and more
    JWTAuthRegistrar::getInstance();
    return $this;
  }

  /**
   * This method enables you to override the default JWT expiry date of 7 days to something
   * far in the future, essentially making it never expire (assumes you're using the "JWT 
   * Authentication for WP-API" plugin). Defaults to setting it 500,000 days into the 
   * future -- customize this by passing in an integer.
   */
  public function extendExpiryDateForJWT($days_into_future = 500000): static
  {
    add_filter('jwt_auth_expire', function () use ($days_into_future) {
      return time() + (DAY_IN_SECONDS * $days_into_future);
    }, 10, 1);

    return $this;
  }


  /**
   * By default, posts fetched within ACF (using its internal `acf_get_posts` function) suppress filters like "the_posts",
   * preventing CloakWP Virtual Fields from affecting ACF relational fields data, for example. This can be a problem 
   * for decoupled frontends that expect these virtual fields to be present in ACF relations -- this method fixes this.
   */
  public function enablePostFiltersForACF(): static
  {
    add_filter('acf/acf_get_posts/args', function ($args) {
      return wp_parse_args(
        $args,
        array(
          'suppress_filters' => false
        )
      );
    }, 10, 1);

    return $this;
  }

  /**
   * Enable cross-origin requests from the decoupled frontend
   */
  public function enableCors(): static
  {
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

      header('Access-Control-Allow-Origin: ' . $allowed_origin);
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
   * Ignore search engine indexing warnings because that's not a concern for decoupled/headless WordPress.
   */
  public function disableSearchEngineIndexingWarnings(): static
  {
    add_filter('roots/bedrock/disallow_indexing_admin_notice', '__return_false');
    return $this;
  }

  /**
   * Provide an array of CloakWP\ACF\Block class instances to be registered.
   * While Block classes can register themselves, the benefit of registering through the CloakWP class as an intermediary 
   * is that it automatically enables CloakWP's decoupled iframe preview feature for each block, among other sensible defaults.
   */
  public function blocks(array $blocks): static
  {
    foreach ($blocks as $block) {
      if (!is_object($block) || !method_exists($block, 'getFieldGroupSettings')) continue; // invalid block

      // Make each ACF block use CloakWP's iframe preview render template
      $block->args([
        'render_callback' => array($this, 'renderBlockIframePreview') // idea here is to remove/abstract the need for dev to specify { ... "renderCallback": "function_name" ... } in block.json
      ]);

      if (!$block->emptyFieldsMessage) $block->emptyFieldsMessage('This block has no fields/controls. Simply drop it wherever you wish to display it.');

      // now register each block
      $block->register();
    }

    $this->blocks = array_merge($this->blocks, $blocks); // todo: might need a custom merge method here to handle duplicates?

    return $this;
  }

  public static function renderBlockIframePreview($block, $content, $is_preview, $post_id, $wp_block, $context)
  {
    include(dirname(__FILE__) . '/block-preview.php');
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
   * Retrieve a specific Frontend class instance via key (you provided the keys in DecoupledFrontend::make(...)).
   */
  public function getFrontend(string $key)
  {
    foreach ($this->frontends as $frontend) {
      if ($frontend->getKey() == $key) return $frontend;
    }
    return null;
  }

  /**
   * getActiveFrontend returns the currently selected DecoupledFrontend instance. 
   * For now, it just returns the first instance provided to DecoupledCMS->frontends([...]),
   * but the intention for the future is to provide a "Frontend Switcher" in the wp-admin UI
   * that allows switching between any of the frontends provided to DecoupledCMS->frontends([...]),
   * and this method will return that currently active frontend. All wp-admin links referencing 
   * the frontend will point to the "active" frontend's URL.
   */
  public function getActiveFrontend(): DecoupledFrontend | null
  {
    // TODO: in future, need to build a "frontend switcher" as described above, and return the currently selected frontend here
    if (!empty($this->frontends)) return $this->frontends[0];
    return DecoupledFrontend::make('wp', get_site_url());
  }

  /**
   * enableLoginStatusEndpoint creates a REST Endpoint that our frontend can ping to determine if the
   * current site visitor is logged in to WP. The `cloakwp` NPM package provides a `isUserLoggedIn()` helper
   * the properly passes cookies which is necessary to determine the auth status. The `@cloakwp/react` package
   * includes an AdminBar component that you can conditionally render based on this endpoint's response, for example.
   */
  public function enableLoginStatusEndpoint(): static
  {
    add_action('rest_api_init', function () {
      register_rest_route('cloakwp', '/is-logged-in', [
        'methods' => 'GET',
        'callback' => [self::class, 'isMySessionActive'],
        'permission_callback' => [self::class, 'isAuthenticated']
      ]);
    });

    return $this;
  }

  /**
   * Check if your own WP user is logged in by looking for the presence of the LOGGED_IN_COOKIE in the global $_COOKIE array.
   * This is designed to be exposed via a WP REST API endpoint that your frontend can call to determine if your own WP user is 
   * logged in (eg. to conditionally render a decoupled WP Admin Bar). In such situations, we can't just call the `is_user_logged_in()`
   * function because it's a cross-origin request (would always return false), or because if the REST endpoint requires JWT authentication, 
   * that would always return true (because the JWT validation process essentially logs you in to WP). We must rely on cookies.
   * 
   * @return WP_Error|WP_REST_Response a boolean wrapped by WP_REST_Response -- i.e. `true` if the user is logged in, `false` otherwise.
   */
  public static function isMySessionActive(): WP_Error|WP_REST_Response
  {
    $isLoggedIn = false;

    if ($_COOKIE && is_array($_COOKIE)) {
      if (array_key_exists(LOGGED_IN_COOKIE, $_COOKIE))
        $isLoggedIn = true;
    }

    return rest_ensure_response($isLoggedIn);
  }

  /**
   * Validate the JWT token from the current request headers.
   */
  public static function isAuthenticated(): bool
  {
    $auth = new JWTAuth();

    $payload = $auth->validate_token(false);

    if ($auth->is_error_response($payload)) {
      return false;
    }

    return true;
    // return false; // TEMPORARY while JWTAuth is under construction
  }

  public function getBlocks()
  {
    return $this->blocks;
  }

  public function getPostTypes()
  {
    return $this->postTypes;
  }
}
