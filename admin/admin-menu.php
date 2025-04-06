<?php
/**
 * File: admin/admin-menu.php
 * Description: Registers the Spintax Domain Manager admin menu with a Dashboard.
 *
 * Top-level: "Spintax Manager" → Dashboard
 * Submenu items: Projects, Sites, Domains, Accounts, Services, Redirects, Settings
 */

if (!defined('ABSPATH')) {
    exit;
}

function sdm_admin_menu() {
    // Top-level menu: "Spintax Manager" leads to a Dashboard page
    add_menu_page(
        __('Spintax Manager - Dashboard', 'spintax-domain-manager'),
        __('Spintax Manager', 'spintax-domain-manager'),
        'manage_options',
        'sdm-dashboard',
        'sdm_dashboard_page',
        'data:image/svg+xml;base64,' . base64_encode(file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/spintax-icon.svg')),
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

    // Submenu: Accounts
    add_submenu_page(
        'sdm-dashboard',
        __('Accounts', 'spintax-domain-manager'),
        __('Accounts', 'spintax-domain-manager'),
        'manage_options',
        'sdm-accounts',
        'sdm_accounts_dashboard'
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

    // Submenu: Redirects
    add_submenu_page(
        'sdm-dashboard',
        __('Redirects', 'spintax-domain-manager'),
        __('Redirects', 'spintax-domain-manager'),
        'manage_options',
        'sdm-redirects',
        'sdm_redirects_dashboard'
    );

    // Submenu: Services
    add_submenu_page(
        'sdm-dashboard',
        __('Services', 'spintax-domain-manager'),
        __('Services', 'spintax-domain-manager'),
        'manage_options',
        'sdm-services',
        'sdm_services_dashboard'
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

    // Submenu: Help
    add_submenu_page(
        'sdm-dashboard',                             // ← Важно: тот же slug, что и у top-level меню
        __('Help & Instructions', 'spintax-domain-manager'), // Page title
        __('Help & Instructions', 'spintax-domain-manager'), // Menu title
        'manage_options',                            // Capability
        'sdm-help-page',                             // Menu slug (URL параметр)
        'sdm_render_help_page'                       // Callback для содержимого
    );


}
add_action('admin_menu', 'sdm_admin_menu');

/**
 * Dashboard page callback
 */
function sdm_dashboard_page() {
    include SDM_PLUGIN_DIR . 'admin/pages/dashboard-page.php';
}

/**
 * Projects page callback
 */
function sdm_projects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/projects-page.php';
}

/**
 * Sites page callback
 */
function sdm_sites_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/sites-page.php';
}

/**
 * Domains page callback
 */
function sdm_domains_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/domains-page.php';
}

/**
 * Accounts page callback
 */
function sdm_accounts_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/accounts-page.php';
}

/**
 * Services page callback
 */
function sdm_services_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/services-page.php';
}

/**
 * Redirects page callback
 */
function sdm_redirects_dashboard() {
    include SDM_PLUGIN_DIR . 'admin/pages/redirects-page.php';
}

/**
 * Settings page callback
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
        [
            'type' => 'string',
            'sanitize_callback' => 'sdm_encryption_key_sanitize',
            'default' => '',
        ]
    );

    register_setting(
        'sdm_settings_group',
        'sdm_enable_graphql',
        [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]
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

    add_settings_field(
        'sdm_enable_graphql_field',
        __('Enable GraphQL Support', 'spintax-domain-manager'),
        'sdm_enable_graphql_field_callback',
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
    $encryption_key = get_option('sdm_encryption_key', '');
    $is_key_saved = !empty($encryption_key);

    if (!$is_key_saved) {
        $encryption_key = wp_generate_password(32, false, false);
    }

    $icon_class = $is_key_saved ? 'sdm-redirect-type-hidden' : 'sdm-redirect-type-main';
    $svg_path = SDM_PLUGIN_DIR . 'assets/icons/hidden.svg';
    $svg_content = file_exists($svg_path) ? file_get_contents($svg_path) : '<svg width="16" height="16" fill="currentColor"><path d="M12 4.5v.5h-.5V3.5H11V4H4v-.5h-.5v.5h-.5v-.5C3 3.224 3.224 3 3.5 3h9c.276 0 .5.224.5.5zM3.5 13c-.276 0-.5-.224-.5-.5v-.5h.5v.5h.5v-.5h7v.5h.5v-.5h.5v.5c0 .276-.224.5-.5.5h-9zM11 7v4H5V7h6m.5-1H4.5c-.275 0-.5.225-.5.5v4c0 .275.225.5.5.5h7c.275 0 .5-.225.5-.5V6.5c0-.275-.225-.5-.5-.5z"/></svg>';
    $svg_content = preg_replace('/<svg[^>]+>/', '<svg>', $svg_content, 1);
    $svg_content = str_replace('width="16" height="16"', '', $svg_content);

    echo '<div class="sdm-encryption-key-wrapper">';
    echo '<input type="text" id="sdm_encryption_key_field" name="sdm_encryption_key" value="' . esc_attr($encryption_key) . '" class="regular-text" ' . ($is_key_saved ? 'readonly' : '') . ' />';
    echo '<button type="button" id="sdm_copy_key_button" class="button">';
    echo esc_html($is_key_saved ? 'Key Saved' : 'Copy Key');
    echo '<span class="' . esc_attr($icon_class) . '" style="margin-left: 8px;">' . $svg_content . '</span>';
    echo '</button>';
    echo '</div>';
    echo '<p class="description" style="margin-top: 10px;">' . esc_html__('This key will be used to encrypt sensitive data. It won’t be saved until you submit the form.', 'spintax-domain-manager') . '</p>';
}

/**
 * GraphQL enable field callback (moved to settings-page.php)
 */

/**
 * Sanitization callback for encryption key
 */
function sdm_encryption_key_sanitize($new_value) {
    $old_value = get_option('sdm_encryption_key', '');

    if (empty($new_value)) {
        add_settings_error(
            'sdm_encryption_key',
            'sdm_encryption_key_error',
            __('Encryption Key cannot be empty. Previous key restored.', 'spintax-domain-manager'),
            'error'
        );
        return $old_value;
    }

    if ($new_value !== $old_value) {
        add_settings_error(
            'sdm_encryption_key',
            'sdm_encryption_key_warning',
            __('Warning: Changing the Encryption Key may make previously encrypted data unreadable.', 'spintax-domain-manager'),
            'warning'
        );
    }

    return $new_value;
}