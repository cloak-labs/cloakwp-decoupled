# CloakWP Block Parser

CloakWP Block Parser is a PHP library designed to parse and transform Gutenberg and ACF blocks into structured objects/JSON. This package is part of the CloakWP ecosystem, but can be used independently or as a dependency of your own plugins/themes/packages.

## Features

- Parse Gutenberg (core) blocks into objects/JSON
- Parse Advanced Custom Fields (ACF) blocks into objects/JSON
- Extensible architecture for custom block transformers
- Filters for modifying parsed block data

## Motivation

WordPress block content is stored as HTML strings in the database rather than as structured data (e.g. JSON). This is particularly a problem for decoupled/headless projects where you may wish to render things your own way; to do so, you need the blocks in JSON/structured object form -- turns out this is surprisingly difficult to achieve. CloakWP Block Parser simplifies this process by providing a clean and organized way to parse and transform blocks content into structured data.

## Installation

You can install this package via Composer:

```bash
composer require cloakwp/block-parser
```

## Example Output

<details>
 <summary>Structured data</summary>
 
```json
[
  {
    "name": "core/paragraph",
    "type": "core",
    "attrs": {
      "content": "Contact us via phone <a href=\"tel:123-456-7890\">(123) 456-7890</a> or email <a href=\"mailto:info@example.com\">info@example.com</a>.",
      "dropCap": false
    }
  },
  {
    "name": "acf/hero",
    "type": "acf",
    "attrs": {
      "style": {
        "spacing": {
          "margin": {
            "bottom": "var:preset|spacing|60"
          }
        }
      },
      "className": "pb-8 md:pb-10",
      "align": "full",
      "backgroundColor": "bg-root-dim"
    },
    // ACF field data:
    "data": {
      "hero_style": "image_right",
      "image": {
        "medium": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example-300x200.jpeg",
          "width": 300,
          "height": 200
        },
        "large": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example-1024x683.jpeg",
          "width": 1024,
          "height": 683
        },
        "full": {
          "src": "http://localhost/app/uploads/sites/8/2024/08/example.jpeg",
          "width": 1620,
          "height": 1080
        },
        "alt": "example alt description",
        "caption": "example caption"
      },
      "eyebrow": "WordPress Experts",
      "h1": "Build your dream website.",
      "subtitle": "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
      "cta_buttons": false,
      "show_social_proof": false
    }
  }
]
```
</details>

## Usage

### Basic Usage

```php
use CloakWP\BlockParser\BlockParser;

$postId = 123;
$blockParser = new BlockParser();
$blockData = $blockParser->parseBlocksFromPost($postId);
```

### Extending

<details>
 <summary>Custom Transformers</summary>

The BlockParser uses the built-in core function, `parse_blocks()`, to initially parse the blocks, but unfortunately this function doesn't do the full job. So, we extend the basic built-in parsing with block "transformers".

By default, the BlockParser uses the following transformers:

- CoreBlockTransformer (for Gutenberg core blocks)
- ACFBlockTransformer (for ACF blocks)

You can extend the BlockParser by registering your own custom block transformers for certain block types, or to override the default transformers:

```php
// when you define your Transformer class, you must implement the BlockTransformerInterface and define
// a static $type property, which indicates the block type that the transformer should be applied to:
class MyCustomACFBlockTransformer implements BlockTransformerInterface
{
  protected static string $type = 'acf'; // this will override the default ACFBlockTransformer

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    // your custom data transformation code here -- whatever you return here will be the final block data
  }
}

// now register the transformer with your BlockParser instance:
$blockParser = new BlockParser();
$blockParser->registerBlockTransformer(MyCustomACFBlockTransformer::class);
```

If in the above example you want to add a transformer for some custom block type, you just specify a custom value for the static `$type` property, and then extend the `BlockParser` class and override the `determineBlockType()` method to add your logic for determining when a block is of your custom type; for example:

```php
class MyCustomBlockParser extends BlockParser
{
  protected function determineBlockType(WP_Block $block): string
  {
    if ($block->blockName === 'my-custom-block-name') {
      return 'custom';
    }

    return parent::determineBlockType($block);
  }
}

class MyCustomBlockTransformer implements BlockTransformerInterface
{
  protected static string $type = 'custom';

  public function transform(WP_Block $block, int|null $postId = null): array
  {
    // ..
  }
}

$postId = 123;
$blockParser = new MyCustomBlockParser();
$blockParser->registerBlockTransformer(MyCustomBlockTransformer::class)

// now any blocks with name 'my-custom-block-name' will be transformed by MyCustomBlockTransformer's transform() method
$blockData = $blockParser->parseBlocksFromPost($postId);
```

</details>

<details>
 <summary>Filter Hooks</summary>

Besides creating custom transformers, you can also modify parsed block data using filters. These filters are applied after the block has been transformed by the appropriate transformer, but before the block is returned:

```php
add_filter('cloakwp/block', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);
```

The `cloakwp/block` filter accepts two modifiers, `name` and `type`, for more granular targeting:

```php
add_filter('cloakwp/block/name=core/paragraph', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);

add_filter('cloakwp/block/type=acf', function(array $parsedBlock, WP_Block $wpBlock) {
  // modify $parsedBlock here
  return $parsedBlock;
}, 10, 2);
```

You can also filter ACF field values within ACF blocks using the `cloakwp/block/field` filter:

```php
add_filter('cloakwp/block/field', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);
```

The `cloakwp/block/field` filter accepts three modifiers, `name` (i.e. ACF field name), `type` (i.e. ACF field type), and `blockName` (i.e. ACF block name), for more granular targeting:

```php

add_filter('cloakwp/block/field/name=my_acf_field', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);

add_filter('cloakwp/block/field/type=image', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);

add_filter('cloakwp/block/field/blockName=acf/hero-section', function(mixed $fieldValue, array $fieldObject) {
  // modify $fieldValue here
  return $fieldValue;
}, 10, 2);
```

</details>
