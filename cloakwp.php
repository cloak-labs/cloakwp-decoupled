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
 * Plugin Name:       CloakWP - Headless WP Framework
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

use CloakWP\CloakWP;
use CloakWP\General\PluginActivator;
use CloakWP\General\PluginDeactivator;

// If this file is called directly, abort.
if (!defined('WPINC')) {
  die;
}

/**
 * Current plugin version.
 * Start at version 0.6.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define('CLOAKWP_VERSION', '0.6.0');
if (!defined('CLOAKWP_DEBUG')) define('CLOAKWP_DEBUG', TRUE);

// Pull in vendor autoloader (for autoloading 3rd party classes such as pQuery)
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * The code that runs during plugin activation.
 */
function activate_cloakwp()
{
  PluginActivator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_cloakwp()
{
  PluginDeactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_cloakwp');
register_deactivation_hook(__FILE__, 'deactivate_cloakwp');


/**
 * Begin execution of the plugin:
 */
CloakWP::getInstance(); // creates CloakWP singleton instance, if it hasn't already been created.
