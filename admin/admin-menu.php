<?php
/**
 * Registers the Spintax Domain Manager admin menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function sdm_admin_menu() {
    add_menu_page(
        __('Spintax Manager', 'spintax-domain-manager'),
        __('Spintax Manager', 'spintax-domain-manager'),
        'manage_options',
        'spintax-domain-manager',
        'sdm_projects_dashboard',
        'data:image/svg+xml;base64,' . base64_encode( file_get_contents( SDM_PLUGIN_DIR . 'assets/icons/spintax-icon.svg' ) ),
        25
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Projects', 'spintax-domain-manager'),
        __('Projects', 'spintax-domain-manager'),
        'manage_options',
        'sdm-projects',
        'sdm_projects_dashboard'
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Sites', 'spintax-domain-manager'),
        __('Sites', 'spintax-domain-manager'),
        'manage_options',
        'sdm-sites',
        'sdm_sites_dashboard'
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Domains', 'spintax-domain-manager'),
        __('Domains', 'spintax-domain-manager'),
        'manage_options',
        'sdm-domains',
        'sdm_domains_dashboard'
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Accounts', 'spintax-domain-manager'),
        __('Accounts', 'spintax-domain-manager'),
        'manage_options',
        'sdm-accounts',
        'sdm_accounts_dashboard'
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Redirects', 'spintax-domain-manager'),
        __('Redirects', 'spintax-domain-manager'),
        'manage_options',
        'sdm-redirects',
        'sdm_redirects_dashboard'
    );
    add_submenu_page(
        'spintax-domain-manager',
        __('Settings', 'spintax-domain-manager'),
        __('Settings', 'spintax-domain-manager'),
        'manage_options',
        'sdm-settings',
        'sdm_settings_page'
    );
}
add_action('admin_menu', 'sdm_admin_menu');

function sdm_projects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/projects-page.php';
}

function sdm_sites_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/sites-page.php';
}

function sdm_domains_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/domains-page.php';
}

function sdm_accounts_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/accounts-page.php';
}

function sdm_redirects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/redirects-page.php';
}

function sdm_settings_page() {
    include SDM_PLUGIN_DIR . 'admin/pages/settings-page.php';
}

// Register settings using WP Settings API
function sdm_register_settings() {
    register_setting('sdm_settings_group', 'sdm_encryption_key', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => '',
    ));
    // Add more global settings here if needed.
    
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

function sdm_settings_section_callback() {
    echo '<p>' . esc_html__('General settings for Spintax Domain Manager.', 'spintax-domain-manager') . '</p>';
}

function sdm_encryption_key_field_callback() {
    $encryption_key = get_option('sdm_encryption_key', '');
    echo '<input type="text" name="sdm_encryption_key" value="' . esc_attr($encryption_key) . '" class="regular-text" />';
}
