<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://github.com/cloak-labs
 * @package           WPHookVariations
 *
 * @wordpress-plugin
 * Plugin Name:       WP Hook Variations
 * Plugin URI:        https://github.com/cloak-labs/wp-hook-variations
 * Description:       A package to create WordPress hook variations for more granular filtering and actions.
 * Version:           1.0.0
 * Author:            Cloak Labs
 * Author URI:        https://github.com/cloak-labs
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-hook-variations
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Include the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Optional: You can add any plugin-specific initialization code here if needed