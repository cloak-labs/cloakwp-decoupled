<?php

declare(strict_types=1);

namespace CloakWP\Content;

use Extended\ACF\Location;

class PostType
{
  public readonly string $slug;
  protected array $settings = [];
  protected array $labels = [];
  protected array|null $fieldGroups = null;
  protected array|null $virtualFields = null;

  /**
   * @var callable|null $afterChangeCallback
   */
  protected $afterChangeCallback;

  /**
   * @var callable|null $afterReadCallback
   */
  protected $afterReadCallback;

  /**
   * @var callable|null $apiResponseCallback
   */
  protected $apiResponseCallback;


  public function __construct(string $slug)
  {
    $this->slug = sanitize_key($slug); // sanitize_key ensures consistency/correctness if user provides improper slug, such as non-lowercase
  }

  public static function make(string|null $slug = null): static
  {
    return new static($slug);
  }

  /**
   * The URL to the icon to be used for this menu. Pass a base64-encoded SVG using a data URI, which will 
   * be colored to match the color scheme -- this should begin with 'data:image/svg+xml;base64,'. Pass the 
   * name of a Dashicons helper class to use a font icon, e.g.'dashicons-chart-pie'. Pass 'none' to leave 
   * div.wp-menu-image empty so an icon can be added via CSS. Defaults to use the posts icon.
   */
  public function menuIcon(string $dashiconName): static
  {
    $this->settings['menu_icon'] = $dashiconName;
    return $this;
  }

  /**
   * The position in the menu order the post type should appear. To work, $show_in_menu must be true. 
   * Default null (at the bottom).
   */
  public function menuPosition(int $menuPosition): static
  {
    $this->settings['menu_position'] = $menuPosition;
    return $this;
  }

  /**
   * The string to use to build the read, edit, and delete capabilities. May be passed as 
   * an array to allow for alternative plurals when using this argument as a base to 
   * construct the capabilities, e.g. array('story', 'stories'). Default 'post'.
   */
  public function capabilityType(string|array $capabilityType): static
  {
    $this->settings['capability_type'] = $capabilityType;
    return $this;
  }

  /**
   * Array of capabilities for this post type. $capability_type is used as a base to construct 
   * capabilities by default. See get_post_type_capabilities() --> https://developer.wordpress.org/reference/functions/get_post_type_capabilities/
   */
  public function capabilities(array $capabilities): static
  {
    $this->settings['capabilities'] = $capabilities;
    return $this;
  }

  /**
   * Whether to use the internal default meta capability handling. Default false.
   */
  public function mapMetaCap(bool $mapMetaCap): static
  {
    $this->settings['map_meta_cap'] = $mapMetaCap;
    return $this;
  }

  /**
   * Core feature(s) the post type supports. Serves as an alias for calling add_post_type_support() directly.
   * Core features include 'title', 'editor', 'comments', 'revisions', 'trackbacks', 'author', 'excerpt', 
   * 'page-attributes', 'thumbnail', 'custom-fields', and 'post-formats'. Additionally, the 'revisions' 
   * feature dictates whether the post type will store revisions, and the 'comments' feature dictates 
   * whether the comments count will show on the edit screen. A feature can also be specified as an 
   * array of arguments to provide additional information about supporting that feature. Example: 
   * 
   *    array( 'my_feature', array( 'field' => 'value' ) ). 
   * 
   * Default is an array containing 'title', 'editor', and 'thumbnail' (the latter is made default 
   * by Extended CPTs library -- isn't usually).
   */
  public function supports(array $supports): static
  {
    $this->settings['supports'] = $supports;
    return $this;
  }

  /**
   * Provide a callback function that sets up the meta boxes for the edit form.
   * Do remove_meta_box() (https://developer.wordpress.org/reference/functions/remove_meta_box/)
   * and add_meta_box() (https://developer.wordpress.org/reference/functions/add_meta_box/) calls 
   * in the callback. Default null.
   */
  public function registerMetaBoxCallback(callable $callback): static
  {
    $this->settings['register_meta_box_cb'] = $callback;
    return $this;
  }

  public function hasArchive(bool $hasArchive): static
  {
    $this->settings['has_archive'] = $hasArchive;
    return $this;
  }

  /**
   * Customize the archive page behaviour.
   * 
   * Example -- show all posts on the post type archive:
   *  archive([
   *	  'nopaging' => true
   *  ])
   * 
   */
  public function archive(array $archive): static
  {
    $this->settings['archive'] = $archive;
    return $this;
  }

  /**
   * An array of taxonomy identifiers that will be registered for the post type.
   * Taxonomies can alternatively be registered and attached to this post type later 
   * on via the CloakWP/Content/Taxonomy class (recommended).
   */
  public function taxonomies(array $taxonomies): static
  {
    $this->settings['taxonomies'] = $taxonomies;
    return $this;
  }

  /**
   * Sets the query_var key for this post type. Defaults to $post_type key. If false, a 
   * post type cannot be loaded at ?{query_var}={post_slug}. If specified as a string, 
   * the query ?{query_var_string}={post_slug} will be valid.
   */
  public function queryVar(string|bool $queryVar): static
  {
    $this->settings['query_var'] = $queryVar;
    return $this;
  }

  /**
   * Whether to allow this post type to be exported. Default true.
   */
  public function canExport(bool $canExport): static
  {
    $this->settings['can_export'] = $canExport;
    return $this;
  }

  /**
   * Whether to delete posts of this type when deleting a user.
   *    If true, posts of this type belonging to the user will be moved to Trash when the user is deleted.
   *    If false, posts of this type belonging to the user will *not* be trashed or deleted.
   *    If not set (the default), posts are trashed if post type supports the 'author' feature. Otherwise posts are not trashed or deleted.
   * Default null.
   */
  public function deleteWithUser(bool $deleteWithUser): static
  {
    $this->settings['delete_with_user'] = $deleteWithUser;
    return $this;
  }

  /**
   * Array of blocks to use as the default initial state for a Gutenberg editor session. Each item should
   * be an array containing block name and optional attributes; more info: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-templates/
   * 
   * Example:
   *  template([
   *    ['core/image', [ 'align' => 'left' ]],
   *    ['core/paragraph', [ 'placeholder' => 'Image Details...' ]],
   *    ['core/heading', []],
   *  ])
   */
  public function template(array $blocks): static
  {
    $this->settings['template'] = $blocks;
    return $this;
  }

  /**
   * Whether the block template should be locked if $template is set.
   *    If set to 'all', the user is unable to insert new blocks, move existing blocks and delete blocks.
   *    If set to 'insert', the user is able to move existing blocks but is unable to insert new blocks and delete blocks.
   * Default false.
   */
  public function templateLock(string|false $templateLock): static
  {
    $this->settings['template_lock'] = $templateLock;
    return $this;
  }

  /**
   * Whether a post type is intended for use publicly either via the admin interface or by 
   * front-end users. While the default settings of $exclude_from_search, $publicly_queryable, 
   * $show_ui, and $show_in_nav_menus are inherited from $public, each does not rely on this 
   * relationship and controls a very specific intention. Default false.
   */
  public function public (bool $isPublic): static
  {
    $this->settings['public'] = $isPublic;
    return $this;
  }

  /**
   * Whether to exclude posts with this post type from front end search results. Default 
   * is the opposite value of $public.
   */
  public function excludeFromSearch(bool $excludeFromSearch): static
  {
    $this->settings['exclude_from_search'] = $excludeFromSearch;
    return $this;
  }

  /**
   * Whether queries can be performed on the front end for the post type as part of parse_request(). 
   * Endpoints would include: * ?post_type={post_type_key} * ?{post_type_key}={single_post_slug} * ?{post_type_query_var}={single_post_slug} 
   * If not set, the default is inherited from $public.
   */
  public function publiclyQueryable(bool $isPubliclyQueryable): static
  {
    $this->settings['publicly_queryable'] = $isPubliclyQueryable;
    return $this;
  }

  /**
   * Whether the post type is hierarchical. Default false.
   */
  public function hierarchical(bool $isHierarchical): static
  {
    $this->settings['hierarchical'] = $isHierarchical;
    return $this;
  }

  /**
   * A short descriptive summary of what the post type is.
   */
  public function description(string $description): static
  {
    $this->settings['description'] = $description;
    return $this;
  }

  /**
   * Whether to expose this post type to the REST API.
   */
  public function showInRest(bool $showInRest): static
  {
    $this->settings['show_in_rest'] = $showInRest;
    return $this;
  }

  /**
   * Whether to add the post type to the site's main RSS feed.
   */
  public function showInFeed(bool $showInFeed): static
  {
    $this->settings['show_in_feed'] = $showInFeed;
    return $this;
  }

  /**
   * Where to show the post type in the admin menu. To work, $show_ui must be true. If true, the post 
   * type is shown in its own top level menu. If false, no menu is shown. If a string of an existing 
   * top level menu ('tools.php' or 'edit.php?post_type=page', for example), the post type will be 
   * placed as a sub-menu of that. Default is value of $show_ui.
   */
  public function showInMenu(bool|string $showInMenu): static
  {
    $this->settings['show_in_menu'] = $showInMenu;
    return $this;
  }

  /**
   * Makes this post type available for selection in navigation menus. Default is value of $public.
   */
  public function showInNavMenus(bool $showInNavMenus): static
  {
    $this->settings['show_in_nav_menus'] = $showInNavMenus;
    return $this;
  }

  /**
   * Makes this post type available via the admin bar. Default is value of $show_in_menu.
   */
  public function showInAdminBar(bool $showInAdminBar): static
  {
    $this->settings['show_in_admin_bar'] = $showInAdminBar;
    return $this;
  }

  /**
   * Whether to generate and allow a UI for managing this post type in the admin. 
   * Default is value of $public.
   */
  public function showUi(bool $showUi): static
  {
    $this->settings['show_ui'] = $showUi;
    return $this;
  }

  /**
   * To change the base URL of REST API route. Default is post type's slug.
   */
  public function restBase(string $restBase): static
  {
    $this->settings['rest_base'] = $restBase;
    return $this;
  }

  /**
   * To change the namespace URL of REST API route. Default is `wp/v2`.
   */
  public function restNamespace(string $restNamespace): static
  {
    $this->settings['rest_namespace'] = $restNamespace;
    return $this;
  }

  /**
   * Customize the REST API controller class name. 
   * Default is 'WP_REST_Posts_Controller' (https://developer.wordpress.org/reference/classes/wp_rest_posts_controller/)
   */
  public function restControllerClass(string $restControllerClass): static
  {
    $this->settings['rest_controller_class'] = $restControllerClass;
    return $this;
  }

  /**
   * Use the blockEditor method to forcefully enable or disable the block editor for post type, 
   * which takes precedence over the Classic Editor plugin. It's typically used when you want
   * a post type to be accessible via the REST API (i.e. `showInRest(true)`) while still using
   * the classic editor (i.e. `blockEditor(false)`). It must be used alongside the showInRest method.
   */
  public function blockEditor(bool $hasBlockEditor): static
  {
    $this->settings['block_editor'] = $hasBlockEditor;
    return $this;
  }

  /**
   * Override the "Enter title here" placeholder text When creating/editing a post of this type.
   */
  public function titlePlaceholder(string $title): static
  {
    $this->settings['enter_title_here'] = $title;
    return $this;
  }

  /**
   * Override the "Featured Image" label when selecting a featured image for a post of this type.
   */
  public function featuredImageLabel(string $label): static
  {
    $this->settings['featured_image'] = $label;
    return $this;
  }

  /**
   * Define a custom permalink structure for posts of this type.
   */
  public function rewrite(array $permastructs): static
  {
    $this->settings['rewrite'] = $permastructs;
    return $this;
  }

  /**
   * Add some custom columns to the Post Type admin listing page.
   * 
   * Example:
   *  adminCols([
   *    'featured_image' => array(
   *      'title'          => 'Illustration',
   *      'featured_image' => 'thumbnail'
   *    ),
   *    'published' => array(
   *      'title'       => 'Published',
   *      'meta_key'    => 'published_date',
   *      'date_format' => 'd/m/Y'
   *    ),
   *    'genre' => array(
   *      'taxonomy' => 'genre'
   *    )
   *  ])
   */
  public function adminCols(array $adminCols): static
  {
    $this->settings['admin_cols'] = $adminCols;
    return $this;
  }

  /**
   * Add a dropdown filter to the Post Type admin listing page.
   * 
   * Example:
   *   adminFilters([
   *     'genre' => [
   *       'taxonomy' => 'genre'
   *     ]
   *   ])
   */
  public function adminFilters(array $adminCols): static
  {
    $this->settings['admin_cols'] = $adminCols;
    return $this;
  }

  /**
   * Quick Edit functionality is enabled for all post types by default. 
   * Pass in `false` to disable it for this post type. 
   */
  public function quickEdit(bool $enableQuickEdit): static
  {
    $this->settings['quick_edit'] = $enableQuickEdit;
    return $this;
  }

  /**
   * An entry is added to the "At a Glance" dashboard widget for your post type by default. 
   * Pass in `false` to disable it for this post type. 
   */
  public function dashboardGlance(bool $enableDashboardGlance): static
  {
    $this->settings['dashboard_glance'] = $enableDashboardGlance;
    return $this;
  }

  /**
   * It's possible to include your post type in the "Recently Published" section of the 
   * "Activity" widget on the dashboard. This isn't enabled by default, and can be 
   * enabled by passing in `true`. 
   */
  public function dashboardActivity(bool $enableDashboardActivity): static
  {
    $this->settings['dashboard_activity'] = $enableDashboardActivity;
    return $this;
  }

  /** 
   * A catch-all method allowing you to specify any other settings made available by 
   * Extended CPTs and the default register_post_type function, that don't already have 
   * their own method in this class (or use as alternative to individual methods).
   */
  public function withSettings(array $settings): static
  {
    $this->settings = array_merge($this->settings, $settings);
    return $this;
  }

  /**
   * Post Type labels are auto-generated based on the post type slug, but you can 
   * customize these labels using this method.
   * 
   * Example:
   *   labels([
   *     'singular' => 'Story',
   *     'plural'   => 'Stories',
   *     'slug'     => 'stories'
   *   ])
   */
  public function labels(array $labels): static
  {
    $this->labels = $labels;
    return $this;
  }

  /**
   * Provide an array of CloakWP `FieldGroup` class instances to attach groups of ACF Fields to this post type. 
   */
  public function fieldGroups(array $fieldGroups): static
  {
    $this->fieldGroups = $fieldGroups;
    return $this;
  }

  /**
   * Run some code before a post of this type is saved, either to trigger a 
   * side-effect or to transform the post data before saving it in the database.
   */
  public function afterChange(callable $callback): static
  {
    $this->afterChangeCallback = $callback;
    return $this;
  }

  /**
   * Run some code after a post of this type is fetched from the database, either to 
   * trigger a side-effect or to transform the post data before returning it -- will 
   * transform the result of PHP fetching functions such as `get_posts` and `WP_Query`,
   * as well as REST API responses for this post type -- providing a simple, single 
   * abstraction around both.
   */
  public function afterRead(callable $callback): static
  {
    $this->afterReadCallback = $callback;
    return $this;
  }

  /**
   * Attach some extra "virtual" fields to all post response objects for this post type.
   * A "virtual" field's value isn't stored in the database -- it's computed at runtime
   * for every post request. For example, you may have two fields on an "invoice" post
   * type, "hours" and "hourly_rate"; instead of saving the invoice "total" in the 
   * database, you could create a virtual field called "total" like so:
   *    virtualFields([ "total" => fn ($post) => $post["hours"] * $post["hourly_rate"] ]) 
   */
  public function virtualFields(array $fields): static
  {
    $this->virtualFields = $fields;
    return $this;
  }

  /**
   * Customize the REST API response for posts of this type. Provide a callback
   * that receives the default response as an argument and returns your modified response.
   */
  public function apiResponse(callable $filterCallback): static
  {
    $this->apiResponseCallback = $filterCallback;
    return $this;
  }

  /**
   * Finally, register the Post Type and, if necessary, its ACF Field Groups.
   * Make sure to call this method last -- you can't continue chaining methods after it.
   */
  public function register()
  {
    add_action('init', function () {
      register_extended_post_type($this->slug, $this->settings, $this->labels);
    });

    if ($this->fieldGroups) {
      foreach ($this->fieldGroups as $fieldGroup) {
        $fieldGroup
          ->location([
            Location::where('post_type', '==', $this->slug)
          ])
          ->register();
      }
    }

    if ($this->afterChangeCallback) {
      $callback = $this->afterChangeCallback;
      add_action("save_post_$this->slug", function ($post_id, $post, $update) use ($callback) {
        if (wp_is_post_autosave($post_id)) {
          return;
        }

        if (!$update) { // if new object
          return;
        }

        $callback($post_id, $post, $update);
      }, 10, 3);
    }

    // if ($this->afterReadCallback) {
    //   $callback = $this->afterReadCallback;
    //   add_filter("the_posts", function ($posts, $query) use ($callback) {
    //     if ($query->query_vars['post_type'] != $this->slug) return $posts;
    //     if (!is_array($posts) || !count($posts)) return $posts;

    //     return $callback($posts, $query);
    //   }, 20, 2);
    // }

    if ($this->virtualFields) {
      register_virtual_fields($this->slug, $this->virtualFields);
    }

    if ($this->apiResponseCallback) {
      $callback = $this->apiResponseCallback;
      add_filter("rest_prepare_$this->slug", function ($response, $post, $context) use ($callback) {
        // First check if the REST response is an error:
        if (is_wp_error($response)) {
          return $response;
        }

        // otherwise, return whatever the user's custom apiResponseCallback returns
        return $callback($response, $post, $context);
      }, 50, 3);
    }
  }
}
