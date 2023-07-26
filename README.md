# CloakWP Wordpress Plugin
This WordPress plugin is designed to integrate a WordPress backend with a NextJS frontend -- it's part of a larger ecosystem of open-source tools designed to make Headless WordPress development simple & easy; it has a required "sister" [NPM package](https://github.com/cloak-labs/cloakwp) to be installed on your Next front-end, and is intended to be used alongside the [HeadlessWP WordPress Plugin](https://github.com/cloak-labs/headless-wp-plugin), which extends the WordPress REST API to be more headless-friendly (eg. adds Gutenberg blocks data and Yoast SEO data to the REST API, expands ACF data in the REST API, extends JWT expiration dates, etc.).

## Features
- Integrates post and page previews with NextJS frontend
- Integrates logging in and out of WordPress with NextJS frontend (so you can conditionally display the CloakWP AdminBar provided by the front-end NPM package)
- Integrates on-demand Incremental Static Regeneration (ISR) functionality with NextJS frontend. As posts/pages are edited and saved in WordPress, regeneration is triggered immediately (as opposed to waiting for the next regeneration interval) and for that particular frontend page only (as opposed to triggering a full sitewide build process). You get all the benefits of a fully static site, without the frustration of waiting for your saved changes to take effect.
- Uses Code as Configuration approach; all config for this plugin is done through defining PHP constants, no configuration data is saved to the database. This allows you to version-control your config and easily define different options per environment (particularly useful for those using a modern WordPress development approach, such as our [WP Backend Starter](https://github.com/cloak-labs/headless-wordpress-backend-starter)).
- Adds a CloakWP menu item to the backend for viewing state of config variables

## CloakWP NPM Package
- [npm](https://www.npmjs.com/package/cloakwp)
- [github](https://github.com/cloak-labs/cloakwp)

## Dependencies
- [HeadlessWP WordPress Plugin](https://github.com/cloak-labs/headless-wp-plugin)

## Installation
1. Install the latest release of `cloakwp.zip` on your WordPress site (or install via Composer, pointing at the latest GitHub release -- our recommended approach)
2. Add the following constants to your `wp-config.php` to configure the plugin
```php
/* 
Define your CloakWP Plugin settings here
*/

# Main Settings
define('CLOAKWP_FRONTEND_URL', 'https://example.com');
define('CLOAKWP_PREVIEW_SECRET', '1234_CUSTOMIZE_ME');
define('CLOAKWP_ENABLE_DEV_MODE', TRUE);

# API Route
define('CLOAKWP_API_BASE_PATH', 'custom'); // defaults to "cloakwp", must match your CloakWP dynamic API route folder name

```
3. Install & configure the [CloakWP NPM package](https://www.npmjs.com/package/cloakwp) on your NextJS front-end
4. Profit
