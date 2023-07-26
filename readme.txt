=== CloakWP ===
Contributors: kaelansmith, cypressjack
Donate link: https://cloakwp.com/
Tags: cloakwp, cloak, headless, nextjs, decoupled, composable-architecture
Requires at least: 5.7
Tested up to: 6.0.3
Stable tag: 1.0.0
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transform WordPress into a headless CMS.

== Description ==

CloakWP is a suite of open-source tools that makes it incredibly easy and fast to build high-quality headless WordPress websites. Unlike traditional WordPress, you get to build your front-end using the latest and greatest JavaScript frameworks, such as NextJS, and benefit from the vastly better developer experience, productivity, site performance, and ultimately business results for you and/or your clients.

And unlike most existing headless WordPress solutions, you don't have to sacrifice the benefits of the traditional "coupled" approach, such as the Gutenberg editor, post preview mode, ACF block previews within the editor, the front-end admin toolbar, and more. AND you don't have to maintain all of the underlying headless infrastructure yourself; we've extracted the infrastructure into a maintainable, version-controlled suite of software tools that you can easily upgrade as we release updates over time, which includes:

- CloakWP Plugin (what you're looking at right now)
- CloakWP NPM Package (installed on your decoupled front-end)
- CloakWP Theme (super lightweight headless-friendly theme)
- Optional: CloakWP Child Theme (get a jump-start on your content modelling)
- Optional: CloakWP Project Starter (CLI tool coming soon) -- scaffold a production-ready CloakWP project that works out-of-the-box, including a modern WordPress backend using the popular Bedrock boilerplate, Docker for local development, and Composer for dependency management, complete with a fully-integrated NextJS front-end.

Headless architecture is the future, but WordPress isn't built for it out-of-the-box. CloakWP is the answer. It's simply the best way to build modern WordPress websites.

== The Plugin ==

As mentioned above, the CloakWP plugin is just one piece of the puzzle. It provides the following features:

- Rewrites WordPress URLs to your decoupled front-end URLs
- Integrates post preview mode with your decoupled front-end
- Improves/extends the WordPress REST API to be more feature-complete and headless-friendly, including:
  - Exposes a post's Gutenberg Blocks as JSON (read more about mapping Gutenberg blocks to your own React components from your decoupled front-end using the block rendering framework in CloakWP's NPM package)
  - Extends default post/page routes to include the full data for the post's featured image, taxonomies, ACF relation fields, complete URL path, and more -- solving many headless-specific issues and preventing the need for multiple API requests just to retrieve a single page's data
  - Provides a custom `/wp-json/wp/v2/frontpage` route to selectively retrieve the page set as the "Homepage" in "WP Admin" > "Settings" > "Reading"
  - Provides a custom `/wp-json/cloakwp/menus/{menu_slug}` route to make it easier to retrieve WordPress menu data
- Enables on-demand Incremental Static Regeneration (ISR) of your decoupled front-end (i.e. save changes to a post, and the plugin triggers an on-demand rebuild of that particular page on the front-end so that changes are viewable within a couple seconds -- enabling static site generation without the downside of waiting minutes/hours for changes to take effect)
- Hides wp-admin pages that are irrelevant in a headless context
- Keeps your authentication status in sync with your decoupled front-end (eg. enabling you to only render the front-end CloakWP `AdminBar` component to logged-in users)
- Adds custom ACF fields `ThemeColorPicker` and `Alignment`, for users who follow our recommended approach to ACF field registration (using [ExtendedACF](https://github.com/vinkla/extended-acf)'s object-oriented PHP)

== Configuration ==

We have made a concerted effort across all CloakWP tooling to embrace "code as configuration". This is why you don't see a configurable plugin settings page in wp-admin; instead, you define PHP constants and use filter and action hooks to configure, extend, and override things.

Why? Unlike saving config in the database via a UI, config defined via code ensures your local dev environment is the source of truth, and enables you to push/merge config changes up to production rather than pulling it down via an arduous database merging methodology, or worse, having to manually redo your config changes in production. It keeps things clean, version-controlled, re-usable, automate-able, etc.

= PHP Constants =
It's recommended to add these to your `wp-config.php` file, or your .env files if using the CloakWP Project Starter or your own implementation of Bedrock:

```php
# Required
define('CLOAKWP_FRONTEND_URL', 'https://example.com');
define('CLOAKWP_PREVIEW_SECRET', '1234_CUSTOMIZE_ME');

# Optional
define('CLOAKWP_API_BASE_PATH', 'custom-route'); // defaults to "cloakwp"; must match your front-end's dynamic API route folder name where you import the CloakWP `ApiRouter`
define('CLOAKWP_PREVIEW_BLOCK_PATHNAME', '/custom-route'); // defaults to "/preview-block"; must match your front-end's page route where you import the CloakWP `BlockPreviewPage`
```
 
= Hooks =
Our goal in the near-future is to release a version of this plugin that provides all kinds of filter/action hooks to extend/override certain functionalities.

== Frequently Asked Questions ==

= Is there a premium version? =

CloakWP is completely free and open-source, and we intend on keeping it that way. We will eventually look to build complementary paid products/services in order to make this a sustainable project over the long-term, but we will always maintain an open-source-first ideaology.

= Where can I dig into the code? =

Check out our [GitHub repo](https://github.com/cloak-labs/cloakwp). It's a monorepo containing all CloakWP tools.

== Screenshots == 

== Changelog ==

= 1.0 =
* Initial release

== Upgrade Notice == 