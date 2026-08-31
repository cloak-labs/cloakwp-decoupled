<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://github.com/cloak-labs/cloakwp-decoupled
 * @since             0.6.0
 * @package           CloakWP
 *
 * @wordpress-plugin
 * Plugin Name:       CloakWP Decoupled
 * Plugin URI:        https://github.com/cloak-labs/cloakwp-decoupled
 * Description:       Adds the WordPress services required by decoupled frontends.
 * Version:           2.0.0
 * Author:            Cloak Labs
 * Author URI:        https://github.com/cloak-labs
 * License:           LGPL-3.0-only
 * License URI:       https://www.gnu.org/licenses/lgpl-3.0.html
 * Requires PHP:      8.2
 * Text Domain:       cloakwp
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
  die;
}

if (!defined('CLOAKWP_DEBUG')) {
  define('CLOAKWP_DEBUG', false);
}

if (function_exists('wp_register_plugin_realpath')) {
  wp_register_plugin_realpath(__DIR__);
}

// Pull in vendor autoloader (for autoloading 3rd party classes such as pQuery)
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Define the locale for this plugin for internationalization.
 */
add_action('init', function () {
  load_plugin_textdomain(
    'cloakwp',
    false,
    dirname(plugin_basename(__FILE__)) . '/languages/'
  );
});

/**
 * The code that runs during plugin activation.
 */
function activate_cloakwp_decoupled()
{
  // in future, do something here when plugin is activated
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_cloakwp_decoupled()
{
  // in future, do something here when plugin is deactivated
}

register_activation_hook(__FILE__, 'activate_cloakwp_decoupled');
register_deactivation_hook(__FILE__, 'deactivate_cloakwp_decoupled');

if (class_exists(\CloakWP\Decoupled\CMS::class)) {
  \CloakWP\Decoupled\CMS::getInstance();
}
