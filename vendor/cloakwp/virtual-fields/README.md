# WP Virtual Fields

This is a small PHP/Composer package (intended to be used by WordPress plugin/theme developers) that provides a simple object-oriented API for registering "virtual fields" on WordPress posts (for both built-in and custom post types).

## Installation

Require this package, with Composer, in the root directory of your project.

```bash
composer require cloak-labs/wp-virtual-fields
```

## What is a Virtual Field?

The term "virtual field" is used to describe a field that is dynamically populated at runtime -- it is not stored in the database.

When you need to access or manipulate data that doesn't need to be stored in your database, virtual fields are the way to go. This is an incredibly useful feature that has countless possibilities. Here are a few common use cases:

- A full name field dynamically joining the user's first and last name
- A dynamic title field that takes the values of several text fields and combines them
- Users count field which runs a fetch request on the users post type and returns the total number of users
- Calculations where the field reads values from other number fields, computes the calculation and returns the output
- Reference field which fetches and returns data from another post

## Why use this package?

WordPress does NOT have a single filter you can hook into to modify all WP_Post objects regardless of the context they were fetched from. So if you want to include a virtual field whenever you fetch a post object, whether it's via core functions or the REST API, you'll have to deal with multiple core filters/functions (for each field) -- you'll go down a rabbit-hole trying to find the right filter hooks, repeat yourself, and/or end up writing your own implementation of this package. We've done it for you, providing a simple, re-usable, and maintainable abstraction over all the ugly stuff.

At Cloak Labs, we found the need for this abstraction in particular with headless WordPress projects; if you haven't already, check out [CloakWP](https://github.com/cloak-labs/cloakwp-js), our full-stack headless WordPress framework.

## How it works

The abstraction takes care of adding these fields to Post Objects returned by core WP functions such as `get_posts` and `WP_Query`, as well as REST API responses (including revisions endpoints).

The following adds two virtual fields, `pathname` and `featured_image`, to all posts, pages, and a testimonials custom post type:

```php
register_virtual_fields(['post', 'page', 'testimonial'], [
  VirtualField::make('pathname')
    ->value(fn ($post) => parse_url(get_permalink($post->ID), PHP_URL_PATH),
  VirtualField::make('featured_image')
    ->value(function ($post) {
      $image_id = get_post_thumbnail_id($post->ID);
      $sizes = ['medium', 'large', 'full'];
      $result = [];

      foreach ($sizes as $size) {
        $img = wp_get_attachment_image_src($image_id, $size);
        $url = is_array($img) ? $img['0'] : false;
        $result[$size] = $url;
      }

      return $result;
    })
]);
```

With the above, any time you fetch a post/page/testimonial, either via core WP functions or via the REST API, these virtual fields will be present in the returned Post Objects, like so:

```php
[ // WP_Post object
  ...
  'pathname' => '/blog/post-xyz/',
  'featured_image' => [
    'medium' => 'https://example.com/wp-content/uploads/2023/10/example-img-300x225.jpg',
    'large' => 'https://example.com/wp-content/uploads/2023/10/example-img-1024x768.jpg',
    'full' => 'https://example.com/wp-content/uploads/2023/10/example-img.jpg',
  ]
  ...
]
```

The `value` method of the `VirtualField` class is a callback that runs for each WP_Post object, where whatever you return is assigned to that field.

You can exclude a virtual field from post object responses for particular contexts, like so:

```php
register_virtual_fields(['post', 'page'], [
  VirtualField::make('pathname')
    ->value(fn ($post) => parse_url(get_permalink($post->ID), PHP_URL_PATH)
    ->excludeFrom(['rest']) // exclude from REST API
]);
```

Pass an array of strings to the `excludeFrom` method, including one or more of these values:

- `rest` -- exclude from parent REST API responses
- `rest_revisions` -- exclude from revision REST API responses
- `core` -- exclude from core WP function responses (i.e. `get_posts` and `WP_Query`)

This may be ideal if for example you have a particularly expensive `value` retrieval function for a field that you only ever use via the REST API, in which case doing `excludeFrom(['core'])` would help with general performance.
