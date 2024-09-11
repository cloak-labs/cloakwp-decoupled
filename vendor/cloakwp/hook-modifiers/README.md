# WP Hook Modifiers

WP Hook Modifiers is a WordPress plugin/Composer package that allows you to extend existing hooks with "modifiers", enabling more granular filtering and actions.

For example, if you've ever worked with ACF hooks, you're probably familiar with the `acf/format_value` filter, which has [modifiers](https://www.advancedcustomfields.com/resources/acf-format_value/#modifers) like `name` and `type` that allow you to target your filter to specific fields by name or type (eg. `acf/format_value/name=my_field_name` or `acf/format_value/type=image`). This package makes it easy for you to create your own hook modifiers like ACF, whether you're a plugin/theme developer or a developer looking to extend someone else's plugin or theme.

## Installation

You can install this package via Composer:

```bash
composer require cloakwp/hook-modifiers
```

## Usage

```php
use CloakWP\HookModifiers;

// Apply desired modifiers to an existing hook
HookModifiers::make(['post_type']) // note it accepts an array of multiple modifiers
  ->forFilter('wp_insert_post_data')
  ->register();

// Now you can use the modifier when calling the hook to target a specific post type
add_filter('wp_insert_post_data/post_type=page', function ($data, $postarr, $unsanitized_postarr) {
  $data['post_title'] = 'Page: ' . $data['post_title'];
  return $data;
}, 10, 2);

// The post_type example above is equivalent to:
add_filter('wp_insert_post_data', function ($data, $postarr, $unsanitized_postarr) {
  if ($data['post_type'] === 'page') {
    $data['post_title'] = 'Page: ' . $data['post_title'];
  }
  return $data;
}, 10, 2);
```

The important thing to note is that the modifier must be a property key of one of the values being passed to the filter/action. In the example above, it is known that `post_type` is a property of the `data` array (1st argument) being filtered. By default, we check the first hook argument for the modifier key/value pairs, but this can be changed by calling the `modifiersArgPosition` method. For example, if a filter has 3 arguments and the modifier key/value pairs are in the third argument, you would do this:

```php
HookModifiers::make(['type'])
  ->forFilter('some_filter_with_many_args')
  ->modifiersArgPosition(2) // 0 indexed, so the third arg is `2`
  ->register();

add_filter('some_filter_with_many_args/type=image', function ($one, $two, $three) {
  // This filter will only run if the arg $three is an associative array with a property 'type' equal to 'image'
}, 10, 3);
```

Similar to `forFilter`, there is a `forAction` method to apply modifiers to action hooks.

**Note:** you must call `register` on the HookModifiers object to actually apply the modifiers to the hook.
