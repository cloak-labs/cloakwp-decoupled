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


/**
 * Define PHP Constant defaults.
 */
if (!defined('CLOAKWP_FRONTEND_URL')) define('CLOAKWP_FRONTEND_URL', 'http://localhost:5000');
if (!defined('CLOAKWP_PREVIEW_SECRET')) define('CLOAKWP_PREVIEW_SECRET', 'wefPWh8XDU43fgNUmi9IC9hjKOrvfvjijKNKNh8uf8');
if (!defined('CLOAKWP_ENABLE_DEV_MODE') ) define('CLOAKWP_ENABLE_DEV_MODE', FALSE);
if (!defined('CLOAKWP_PREVIEW_BLOCK_PATHNAME') ) define('CLOAKWP_PREVIEW_BLOCK_PATHNAME', '/preview-block');
if (!defined('CLOAKWP_API_BASE_PATH')) define('CLOAKWP_API_BASE_PATH', 'cloakwp');
if (!defined('CLOAKWP_DEBUG')) define('CLOAKWP_DEBUG', TRUE);

/**
 * Autoloads plugin classes and functions.
 *
 * @param string $class_or_function The fully-qualified class or function name.
 */
function cloakwp_autoload($class_or_function)
{
  // Base plugin namespace.
  $plugin_namespace = 'CloakWP\\';

  // Check if the class or function belongs to the plugin.
  if ( 0 === strpos( $class_or_function, $plugin_namespace ) ) {
    // Remove the plugin namespace.
    $relative_path = str_replace( $plugin_namespace, '', $class_or_function );
    // error_log('AUTOLOADER - $relative_path: ' . $relative_path);
    
    // Convert namespace separators to directory separators.
    $relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_path );
    // error_log('AUTOLOADER - $relative_path 2: ' . $relative_path);
    
    // Determine if it's a class or function based on the file name.
    $is_class = substr( $relative_path, -4 ) === '.php';
    // error_log('AUTOLOADER - $is_class: ' . $is_class);

    $directory = 'includes';
    if ($relative_path == 'Admin') $directory = 'admin';
    
    // Get the full path to the class or function file.
    $file_path = plugin_dir_path( __FILE__ ) . $directory . DIRECTORY_SEPARATOR . $relative_path . '.php';
    // error_log('AUTOLOADER - $file_path: ' . $file_path);

    // Check if the file exists and load it.
    if ( file_exists( $file_path ) ) {
        if ( $is_class ) {
            require_once $file_path;
        } else {
            require $file_path;
        }
    }
}
}

// Register the autoloader function.
spl_autoload_register('cloakwp_autoload');

// Pull in vendor autoloader (for autoloading 3rd party classes such as pQuery)
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-cloakwp-activator.php
 */
function activate_cloakwp()
{
  // require_once plugin_dir_path(__FILE__) . 'includes/CloakWP/General/PluginActivator.php';
  PluginActivator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-cloakwp-deactivator.php
 */
function deactivate_cloakwp()
{
  // require_once plugin_dir_path(__FILE__) . 'includes/CloakWP/General/PluginDeactivator.php';
  PluginDeactivator::deactivate();
}

register_activation_hook(__FILE__, 'activate_cloakwp');
register_deactivation_hook(__FILE__, 'deactivate_cloakwp');

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, public-facing site hooks, and all other plugin functionality.
 */
// require plugin_dir_path(__FILE__) . 'includes/CloakWP/CloakWP.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    0.6.0
 */
function run_cloakwp()
{
  $plugin = new CloakWP();
  $plugin->run();
}
run_cloakwp();
