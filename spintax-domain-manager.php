<?php
/**
 * Plugin Name:       Spintax Domain Manager
 * Plugin URI:        https://spintax.net/domain_manager
 * Description:       A WordPress plugin for managing domains, redirects, and external API integrations.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Divisor & ChatGPT
 * Author URI:        https://spintax.net
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spintax-domain-manager
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'SDM_VERSION', '1.0.0' );
define( 'SDM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SDM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load translation files
function sdm_load_textdomain() {
    load_plugin_textdomain( 'spintax-domain-manager', false, dirname( SDM_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'sdm_load_textdomain' );

// Include core files
require_once SDM_PLUGIN_DIR . 'includes/database.php';
require_once SDM_PLUGIN_DIR . 'includes/common-functions.php';
require_once SDM_PLUGIN_DIR . 'includes/encryption.php';

// Include managers (CRUD operations, API integrations, etc.)
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-projects-manager.php';
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-sites-manager.php';
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-domains-manager.php';
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-accounts-manager.php';
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-redirects-manager.php';
require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-service-types-manager.php';

// Include API integrations
require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-xmlstock-api.php';

// Optionally include GraphQL support if needed
if ( file_exists( SDM_PLUGIN_DIR . 'includes/graphql-support.php' ) ) {
    require_once SDM_PLUGIN_DIR . 'includes/graphql-support.php';
}

// Load admin interface (admin menu and pages)
if ( is_admin() ) {
    require_once SDM_PLUGIN_DIR . 'admin/admin-menu.php';
}

// Register activation, deactivation and uninstall hooks
register_activation_hook( __FILE__, 'sdm_activate' );
register_deactivation_hook( __FILE__, 'sdm_deactivate' );
register_uninstall_hook( __FILE__, 'sdm_uninstall' );

/**
 * Plugin activation callback.
 * Creates necessary database tables.
 */
function sdm_activate() {
    sdm_create_tables();
}

/**
 * Plugin deactivation callback.
 * Currently no actions required.
 */
function sdm_deactivate() {
    // You can add deactivation code here if needed.
}

/**
 * Plugin uninstall callback.
 * Removes database tables.
 */
function sdm_uninstall() {
    sdm_remove_tables();
}

/**
 * Define constants and helper functions for a global nonce.
 */

// We define one "action" and one "field" name for the main nonce.
// If you prefer separate nonces for each action, you can define multiple pairs.
define( 'SDM_NONCE_ACTION', 'sdm_main_nonce_action' );
define( 'SDM_NONCE_FIELD',  'sdm_main_nonce_field' );

/**
 * Create a main nonce for forms.
 *
 * @return string The nonce string.
 */
function sdm_create_main_nonce() {
    return wp_create_nonce( SDM_NONCE_ACTION );
}

/**
 * Echo a hidden input field with the main nonce.
 * Usage: sdm_nonce_field();
 */
function sdm_nonce_field() {
    echo '<input type="hidden" name="' . esc_attr( SDM_NONCE_FIELD ) . '" value="' . esc_attr( sdm_create_main_nonce() ) . '" />';
}

/**
 * Check the main nonce (used in Ajax or form submissions).
 * If verification fails, it will exit with a 403 error.
 */
function sdm_check_main_nonce() {
    check_ajax_referer( SDM_NONCE_ACTION, SDM_NONCE_FIELD );
}

function sdm_enqueue_admin_assets() {
    // Подключаем общий admin.js
    wp_enqueue_script( 'sdm-admin-js', SDM_PLUGIN_URL . 'admin/js/admin.js', array(), SDM_VERSION, true );

    // Подключаем стили
    wp_enqueue_style( 'sdm-admin-css', SDM_PLUGIN_URL . 'admin/css/admin.css', array(), SDM_VERSION );
}
add_action( 'admin_enqueue_scripts', 'sdm_enqueue_admin_assets' );
