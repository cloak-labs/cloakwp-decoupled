# CloakWP Decoupled

A PHP/Composer package with everything you need to turn WordPress into a decoupled/headless CMS.

CloakWP is a suite of open-source tools that makes it incredibly easy and fast to build high-quality decoupled/headless WordPress websites. Unlike traditional WordPress, you get to build your front-end using the latest and greatest JavaScript frameworks, such as Next.js, and benefit from the vastly better developer experience, productivity, site performance, and ultimately business results for you and/or your clients.

And unlike most existing headless WordPress solutions, you don't have to sacrifice the benefits of the traditional "coupled" approach, such as the Gutenberg editor, post preview mode, ACF block previews within the editor, the front-end admin toolbar, and more. AND you don't have to maintain all of the underlying headless infrastructure yourself (trust us, it's a lot); we've extracted the infrastructure into a maintainable, version-controlled suite of software tools that you can easily upgrade as we release updates over time. These tools include:

- CloakWP Plugin (what you're looking at right now)
- [CloakWP.js](https://github.com/cloak-labs/cloakwp-js) (NPM package for your decoupled front-end that communicates with this plugin, provides a Gutenberg block rendering framework for React, and so much more)
- [CloakWP Base Theme](https://github.com/cloak-labs/cloakwp-base-theme) (basic headless-friendly WordPress theme)
- Optional: [CloakWP Bedrock](https://github.com/cloak-labs/cloakwp-bedrock) (a free production-ready headless WordPress boilerplate for CloakWP projects, extending the popular Bedrock boilerplate, including Spinup Local WP (simple Dockerized WordPress for local development), Composer for dependency management, and a collection of best-practice headless plugins pre-installed)
- Optional: [CloakWP Inception](https://github.com/cloak-labs/cloakwp-inception-nextjs) (a free, integrated WP child theme + Next.js frontend to jump-start your headless projects)

Headless architecture is the future, but WordPress isn't built for it out-of-the-box. CloakWP is the answer. It's simply the best way to build modern WordPress websites.

## Plugin Features

As mentioned above, the CloakWP plugin is just one piece of the puzzle. It provides the following features:

- Rewrites WordPress URLs to your decoupled front-end URLs
- Integrates post preview mode with your decoupled front-end
- Improves/extends the WordPress REST API to be more feature-complete and headless-friendly, including:
  - Converts & exposes Gutenberg Blocks data as JSON (read more about mapping Gutenberg blocks to your own React components from your decoupled front-end using the block rendering framework in [CloakWP.js](https://github.com/cloak-labs/cloakwp-js))
  - Extends default post/page routes to include the full data for the post's featured image, taxonomies, ACF relation fields, complete URL path, and more -- solving many headless-specific issues and preventing the need for multiple API requests just to retrieve a single post's data
  - Provides a custom `/wp-json/wp/v2/frontpage` route to selectively retrieve the page set as the "Homepage" in "WP Admin" > "Settings" > "Reading"
  - Provides a custom `/wp-json/cloakwp/menus/{menu_slug}` route to make it easier to retrieve WordPress menu data
- Enables on-demand Incremental Static Regeneration (ISR) of your decoupled front-end; i.e. when you save changes to a WP post, the plugin triggers an immediate rebuild of that particular static page on your decoupled front-end so that the changes are viewable within a couple seconds -- enabling a blazing-fast website thanks to static site generation, but without the usual downside of having to wait minutes/hours for content changes to take effect (that's right, server-side rendering no longer has any advantages over static site generation, for 99% of content/marketing sites)
- Hides wp-admin pages that are irrelevant in a headless context
- Keeps your authentication status in sync with your decoupled front-end (eg. enabling you to only render the CloakWP.js `AdminBar` component for logged-in users)
- Adds custom ACF fields, `ThemeColorPicker` and `Alignment`, for users who follow our recommended approach to ACF field registration (i.e. using [ExtendedACF](https://github.com/vinkla/extended-acf)'s object-oriented PHP)

## Installation

If you're not using [CloakWP Bedrock](https://github.com/cloak-labs/cloakwp-bedrock), which pre-installs the CloakWP Plugin for you, you can install the plugin via Composer by running:

```bash
composer require cloak-labs/cloakwp-plugin
```

Not using Composer? First, strongly consider using Composer. Otherwise, download the plugin's GitHub repo and upload it to WordPress as a .zip

## Configuration

We have made a concerted effort across all CloakWP tooling to embrace "code as configuration". This is why you don't see a configurable plugin settings page in wp-admin; instead, you define PHP constants and use filter and action hooks to configure, extend, and override things.

Why? Unlike saving config in the database via a UI, config defined via code ensures your local dev environment is the source of truth, and enables you to push/merge config changes up to production rather than pulling it down via an arduous database merging methodology, or worse, having to manually redo your config changes in production. It keeps things clean, version-controlled, re-usable, automate-able, etc.

### PHP Constants

Add the following required constant declarations to your `wp-config.php` file, or your .env files if using the CloakWP Bedrock starter or your own implementation of Bedrock:

```php
# Required
define('MY_FRONTEND_URL', 'https://example.com'); // decoupled frontend URL
define('CLOAKWP_AUTH_SECRET', '1234_CUSTOMIZE_ME'); // secure secret key

# Optional
# define('CLOAKWP_API_BASE_PATH', 'custom-route'); // defaults to "cloakwp"; must match your front-end's dynamic API route folder name where you import the CloakWP.js `ApiRouter`
# define('CLOAKWP_PREVIEW_BLOCK_PATHNAME', '/custom-route'); // defaults to "/preview-block"; must match your front-end's page route where you import the CloakWP.js `BlockPreviewPage`
```

### Hooks

Our goal in the near-future is to release a version of this plugin that provides all kinds of filter/action hooks to extend/override certain functionalities; for now, you just get the PHP Constants above.

## Frequently Asked Questions

### Is there a premium version of the plugin?

CloakWP's entire suite of tools is completely free and open-source, and we intend to keep it that way. We will eventually look to build complementary paid products/services in order to make this a sustainable project over the long-term, but we will always maintain an open-source-first ideaology, especially for the core infrastructure tooling such as those listed above.
