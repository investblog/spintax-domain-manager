<?php
/**
 * Plugin Name:       Spintax Domain Manager
 * Plugin URI:        https://spintax.net/domain_manager
 * Description:       A WordPress plugin for managing domains, redirects, and external API integrations.
 * Version:           1.0.5
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Divisor & ChatGPT
 * Author URI:        https://spintax.net
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spintax-domain-manager
 * Domain Path:       /languages
 * GitHub Plugin URI: https://github.com/investblog/spintax-domain-manager
 * GitHub Branch: main
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('SDM_VERSION', '1.0.0');
define('SDM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SDM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SDM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load translation files
function sdm_load_textdomain() {
    load_plugin_textdomain('spintax-domain-manager', false, dirname(SDM_PLUGIN_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'sdm_load_textdomain');

// Include core files
require_once SDM_PLUGIN_DIR . 'includes/database.php';
require_once SDM_PLUGIN_DIR . 'includes/common-functions.php';
require_once SDM_PLUGIN_DIR . 'includes/encryption.php';
require_once SDM_PLUGIN_DIR . 'includes/cron.php';


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
require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-hosttracker-api.php';

// Optionally include GraphQL support
if (file_exists(SDM_PLUGIN_DIR . 'includes/graphql-support.php')) {
    require_once SDM_PLUGIN_DIR . 'includes/graphql-support.php';
}

// Load admin interface (admin menu and pages)
if (is_admin()) {
    require_once SDM_PLUGIN_DIR . 'admin/admin-menu.php';
}

// Register activation, deactivation and uninstall hooks
register_activation_hook(__FILE__, 'sdm_activate');
register_deactivation_hook(__FILE__, 'sdm_deactivate');
register_uninstall_hook(__FILE__, 'sdm_uninstall');

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
define('SDM_NONCE_ACTION', 'sdm_main_nonce_action');
define('SDM_NONCE_FIELD', 'sdm_main_nonce_field');

/**
 * Create a main nonce for forms.
 *
 * @return string The nonce string.
 */
function sdm_create_main_nonce() {
    return wp_create_nonce(SDM_NONCE_ACTION);
}

/**
 * Echo a hidden input field with the main nonce.
 * Usage: sdm_nonce_field();
 */
function sdm_nonce_field() {
    echo '<input type="hidden" name="' . esc_attr(SDM_NONCE_FIELD) . '" value="' . esc_attr(sdm_create_main_nonce()) . '" />';
}

/**
 * Check the main nonce (used in Ajax or form submissions).
 * If verification fails, it will return a JSON error.
 */
function sdm_check_main_nonce() {
    $nonce = isset($_POST[SDM_NONCE_FIELD]) ? $_POST[SDM_NONCE_FIELD] : '';
    if (!wp_verify_nonce($nonce, SDM_NONCE_ACTION)) {
        wp_send_json_error(array('message' => __('Invalid nonce.', 'spintax-domain-manager')));
        wp_die();
    }
    return true;
}
/**
 * Enqueue admin assets including Flag Icons for language flags.
 */
function sdm_enqueue_admin_assets() {
    wp_enqueue_style('wp-admin');

    // Подключаем общий админский JS
    wp_enqueue_script('sdm-admin-js', SDM_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), SDM_VERSION, true);

    $screen = get_current_screen();

    // Подключаем domains.js только на странице доменов
    if ($screen && $screen->id === 'spintax-manager_page_sdm-domains') {
        wp_enqueue_script(
            'sdm-domains-js',
            SDM_PLUGIN_URL . 'admin/js/domains.js',
            array('jquery'),
            SDM_VERSION,
            true
        );
    }

    // Подключаем sites.js только на странице сайтов
    if ($screen && $screen->id === 'spintax-manager_page_sdm-sites') {
        wp_enqueue_script(
            'sdm-sites-js',
            SDM_PLUGIN_URL . 'admin/js/sites.js',
            array('jquery'),
            SDM_VERSION,
            true
        );
    }

    // Подключаем redirects.js и локализацию только на странице редиректов
    if ($screen && $screen->id === 'spintax-manager_page_sdm-redirects') {
        wp_enqueue_script(
            'sdm-redirects-js',
            SDM_PLUGIN_URL . 'admin/js/redirects.js',
            array('jquery'),
            SDM_VERSION,
            true
        );
        wp_localize_script('sdm-redirects-js', 'SDM_Data', array(
            'pluginUrl' => SDM_PLUGIN_URL,
        ));
    }

    // Подключаем accounts.js и локализацию только на странице акаунтов
    if ($screen && $screen->id === 'spintax-manager_page_sdm-accounts') {
        wp_enqueue_script('sdm-accounts-js', SDM_PLUGIN_URL . 'admin/js/accounts.js', array('jquery'), SDM_VERSION, true);
        $service_manager = new SDM_Service_Types_Manager();
        $services = $service_manager->get_all_services();
        $services_options = implode('', array_map(function($srv) {
            return '<option value="' . esc_attr($srv->service_name) . '">' . esc_html(ucfirst($srv->service_name)) . '</option>';
        }, $services));
        wp_localize_script('sdm-accounts-js', 'SDM_Accounts_Data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => sdm_create_main_nonce(),
            'servicesOptions' => $services_options
        ));
    }

    // Подключаем Select2 на страницах доменов, редиректов и сайтов
    if ($screen && in_array($screen->id, array(
        'spintax-manager_page_sdm-domains',
        'spintax-manager_page_sdm-redirects',
        'spintax-manager_page_sdm-sites'
    ), true)) {
        wp_enqueue_style(
            'select2-css',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css',
            array(),
            '4.0.13'
        );
        wp_enqueue_script(
            'select2',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js',
            array('jquery'),
            '4.0.13',
            true
        );
    }

    wp_enqueue_style(
        'sdm-admin-css',
        SDM_PLUGIN_URL . 'admin/css/admin.css',
        array(),
        SDM_VERSION
    );

    // Подключаем флаги (flag-icons)
    wp_enqueue_style(
        'flag-icons',
        'https://cdn.jsdelivr.net/npm/flag-icons/css/flag-icons.min.css',
        array(),
        '6.6.6'
    );
}
add_action('admin_enqueue_scripts', 'sdm_enqueue_admin_assets');