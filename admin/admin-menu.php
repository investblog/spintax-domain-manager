<?php
/**
 * File: admin/admin-menu.php
 * Description: Registers the Spintax Domain Manager admin menu with a Dashboard.
 *
 * Top-level: "Spintax Manager" → Dashboard
 * Submenu items: Projects, Sites, Domains, Accounts, Redirects, Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function sdm_admin_menu() {
    // Top-level menu: "Spintax Manager" leads to a Dashboard page
    add_menu_page(
        // Page title
        __('Spintax Manager - Dashboard', 'spintax-domain-manager'),
        // Menu title
        __('Spintax Manager', 'spintax-domain-manager'),
        // Capability
        'manage_options',
        // Menu slug
        'sdm-dashboard',
        // Callback function for the top-level page
        'sdm_dashboard_page',
        // Icon (encoded SVG)
        'data:image/svg+xml;base64,' . base64_encode( file_get_contents( SDM_PLUGIN_DIR . 'assets/icons/spintax-icon.svg' ) ),
        // Position
        25
    );

    // Submenu: Projects
    add_submenu_page(
        'sdm-dashboard',
        __('Projects', 'spintax-domain-manager'),
        __('Projects', 'spintax-domain-manager'),
        'manage_options',
        'sdm-projects',
        'sdm_projects_dashboard'
    );

    // Submenu: Sites
    add_submenu_page(
        'sdm-dashboard',
        __('Sites', 'spintax-domain-manager'),
        __('Sites', 'spintax-domain-manager'),
        'manage_options',
        'sdm-sites',
        'sdm_sites_dashboard'
    );

    // Submenu: Domains
    add_submenu_page(
        'sdm-dashboard',
        __('Domains', 'spintax-domain-manager'),
        __('Domains', 'spintax-domain-manager'),
        'manage_options',
        'sdm-domains',
        'sdm_domains_dashboard'
    );

    // Submenu: Accounts
    add_submenu_page(
        'sdm-dashboard',
        __('Accounts', 'spintax-domain-manager'),
        __('Accounts', 'spintax-domain-manager'),
        'manage_options',
        'sdm-accounts',
        'sdm_accounts_dashboard'
    );

    // Submenu: Redirects
    add_submenu_page(
        'sdm-dashboard',
        __('Redirects', 'spintax-domain-manager'),
        __('Redirects', 'spintax-domain-manager'),
        'manage_options',
        'sdm-redirects',
        'sdm_redirects_dashboard'
    );

    // Submenu: Settings
    add_submenu_page(
        'sdm-dashboard',
        __('Settings', 'spintax-domain-manager'),
        __('Settings', 'spintax-domain-manager'),
        'manage_options',
        'sdm-settings',
        'sdm_settings_page'
    );
}
add_action('admin_menu', 'sdm_admin_menu');

/**
 * Dashboard page callback
 * File: admin/pages/dashboard-page.php
 */
function sdm_dashboard_page() {
    include SDM_PLUGIN_DIR . 'admin/pages/dashboard-page.php';
}

/**
 * Projects page callback
 * File: admin/pages/projects-page.php
 */
function sdm_projects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/projects-page.php';
}

/**
 * Sites page callback
 * File: admin/pages/sites-page.php
 */
function sdm_sites_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/sites-page.php';
}

/**
 * Domains page callback
 * File: admin/pages/domains-page.php
 */
function sdm_domains_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/domains-page.php';
}

/**
 * Accounts page callback
 * File: admin/pages/accounts-page.php
 */
function sdm_accounts_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/accounts-page.php';
}

/**
 * Redirects page callback
 * File: admin/pages/redirects-page.php
 */
function sdm_redirects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/redirects-page.php';
}

/**
 * Settings page callback
 * File: admin/pages/settings-page.php
 */
function sdm_settings_page() {
    include SDM_PLUGIN_DIR . 'admin/pages/settings-page.php';
}

/**
 * Register settings using WP Settings API
 */
function sdm_register_settings() {
    register_setting(
        'sdm_settings_group',
        'sdm_encryption_key',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'sdm_encryption_key_sanitize',
            'default'           => '',
        )
    );

    add_settings_section(
        'sdm_settings_section',
        __('General Settings', 'spintax-domain-manager'),
        'sdm_settings_section_callback',
        'sdm_settings'
    );
    
    add_settings_field(
        'sdm_encryption_key_field',
        __('Encryption Key', 'spintax-domain-manager'),
        'sdm_encryption_key_field_callback',
        'sdm_settings',
        'sdm_settings_section'
    );
}
add_action('admin_init', 'sdm_register_settings');

/**
 * Settings section callback
 */
function sdm_settings_section_callback() {
    echo '<p>' . esc_html__('General settings for Spintax Domain Manager.', 'spintax-domain-manager') . '</p>';
}

/**
 * Encryption key field callback
 */
function sdm_encryption_key_field_callback() {
    // Retrieve the encryption key from the options
    $encryption_key = get_option('sdm_encryption_key', '');
    
    // If no key is set, generate a new one (32 characters) using WordPress function.
    if ( empty( $encryption_key ) ) {
        $encryption_key = wp_generate_password(32, false, false);
    }
    
    // Output a readonly input field and a copy button
    echo '<input type="text" id="sdm_encryption_key_field" name="sdm_encryption_key" value="' . esc_attr( $encryption_key ) . '" class="regular-text" readonly />';
    echo '<button type="button" id="sdm_copy_key_button" class="button">' . esc_html__( 'Copy Key', 'spintax-domain-manager' ) . '</button>';
    
    // Info text
    echo '<p class="description">' . esc_html__( 'This key is used to encrypt sensitive account data. You can copy it for backup purposes.', 'spintax-domain-manager' ) . '</p>';
}

/**
 * Enqueue admin scripts for copying the encryption key
 * File: admin/js/admin.js
 */
function sdm_enqueue_admin_scripts() {
    wp_enqueue_script( 'sdm-admin-js', SDM_PLUGIN_URL . 'admin/js/admin.js', array(), SDM_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'sdm_enqueue_admin_scripts' );


/**
 * Sanitization callback for encryption key.
 */
function sdm_encryption_key_sanitize( $new_value ) {
    $old_value = get_option('sdm_encryption_key', '');

    // If new_value is empty, revert to old_value and add error
    if ( empty( $new_value ) ) {
        add_settings_error(
            'sdm_encryption_key',
            'sdm_encryption_key_error',
            __('Encryption Key cannot be empty. Previous key restored.', 'spintax-domain-manager'),
            'error'
        );
        return $old_value; // revert
    }

    // If user actually changed the key, warn them about potential data loss
    if ( $new_value !== $old_value ) {
        add_settings_error(
            'sdm_encryption_key',
            'sdm_encryption_key_warning',
            __('Warning: Changing the Encryption Key may make previously encrypted data unreadable.', 'spintax-domain-manager'),
            'warning'
        );
    }

    return $new_value;
}
