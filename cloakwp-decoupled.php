<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://https://github.com/cloak-labs
 * @since             0.6.0
 * @package           CloakWP
 *
 * @wordpress-plugin
 * Plugin Name:       CloakWP Decoupled
 * Plugin URI:        https://https://github.com/cloak-labs/cloakwp-plugin
 * Description:       Adds the missing pieces required for headless projects. Designed for use alongside the CloakWP suite of open-source tooling. 
 * Version:           1.0.0
 * Author:            Cloak Labs
 * Author URI:        https://https://github.com/cloak-labs
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       cloakwp
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
  die;
}

if (!defined('CLOAKWP_DEBUG'))
  define('CLOAKWP_DEBUG', TRUE);

// Pull in vendor autoloader (for autoloading 3rd party classes such as pQuery)
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Define the locale for this plugin for internationalization.
 */
add_action('plugins_loaded', function () {
  load_plugin_textdomain(
    'cloakwp',
    false,
    dirname(plugin_basename(__FILE__)) . '/languages/'
  );
});

/**
 * The code that runs during plugin activation.
 */
function activate_cloakwp()
{
  // in future, do something here when plugin is activated
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_cloakwp()
{
  // in future, do something here when plugin is deactivated
}

register_activation_hook(__FILE__, 'activate_cloakwp');
register_deactivation_hook(__FILE__, 'deactivate_cloakwp');
