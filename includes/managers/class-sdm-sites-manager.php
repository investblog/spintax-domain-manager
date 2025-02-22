<?php
/**
 * File: includes/managers/class-sdm-sites-manager.php
 * Description: Manager for handling site CRUD operations.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Sites_Manager {

    /**
     * Adds a new site.
     */
    public function add_site( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';

        $project_id = isset( $data['project_id'] ) ? absint( $data['project_id'] ) : 0;
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_project', __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }

        $site_name = isset( $data['site_name'] ) ? sanitize_text_field( $data['site_name'] ) : '';
        if ( empty( $site_name ) ) {
            return new WP_Error( 'invalid_site_name', __( 'Site name is required.', 'spintax-domain-manager' ) );
        }

        $server_ip   = isset( $data['server_ip'] )   ? sanitize_text_field( $data['server_ip'] )   : '';
        $main_domain = isset( $data['main_domain'] ) ? sanitize_text_field( $data['main_domain'] ) : '';
        if ( empty( $main_domain ) ) {
            return new WP_Error( 'invalid_main_domain', __( 'Main domain is required.', 'spintax-domain-manager' ) );
        }

        $language = isset( $data['language'] ) ? sanitize_text_field( $data['language'] ) : '';
        if ( empty( $language ) ) {
            return new WP_Error( 'invalid_language', __( 'Language is required.', 'spintax-domain-manager' ) );
        }

        // Используем NULL для svg_icon и override_accounts, если их нет
        $svg_icon = null;
        $override_accounts = null;

        $result = $wpdb->insert(
            $table,
            array(
                'project_id'        => $project_id,
                'site_name'         => $site_name,
                'server_ip'         => $server_ip,
                'svg_icon'          => $svg_icon,
                'override_accounts' => $override_accounts,
                'main_domain'       => $main_domain,
                'language'          => $language,
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ),
            array(
                '%d','%s','%s','%s','%s','%s','%s','%s','%s'
            )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_insert_error', __( 'Could not insert site into database.', 'spintax-domain-manager' ) );
        }
        return $wpdb->insert_id;
    }

    /**
     * Update for inline editing
     */
    public function update_site( $site_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';

        $site_id = absint( $site_id );
        if ( $site_id <= 0 ) {
            return new WP_Error( 'invalid_site_id', __( 'Invalid site ID.', 'spintax-domain-manager' ) );
        }

        // Получаем старую запись
        $old = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table WHERE id=%d", $site_id) );
        if ( ! $old ) {
            return new WP_Error( 'not_found', __( 'Site not found.', 'spintax-domain-manager' ) );
        }

        $site_name   = isset($data['site_name'])   ? sanitize_text_field($data['site_name'])   : $old->site_name;
        $main_domain = isset($data['main_domain']) ? sanitize_text_field($data['main_domain']) : $old->main_domain;
        $server_ip   = isset($data['server_ip'])   ? sanitize_text_field($data['server_ip'])   : $old->server_ip;
        $language    = isset($data['language'])    ? sanitize_text_field($data['language'])    : $old->language;

        $updated = $wpdb->update(
            $table,
            array(
                'site_name'   => $site_name,
                'main_domain' => $main_domain,
                'server_ip'   => $server_ip,
                'language'    => $language, // Add language update
                'updated_at'  => current_time('mysql'),
            ),
            array( 'id' => $site_id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_error', __( 'Could not update site.', 'spintax-domain-manager' ) );
        }
        return true;
    }

    /**
     * Update site icon
     */
    public function update_site_icon( $site_id, $svg_icon ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';

        $site_id = absint( $site_id );
        if ( $site_id <= 0 ) {
            return new WP_Error( 'invalid_site_id', __( 'Invalid site ID.', 'spintax-domain-manager' ) );
        }

        // Sanitize SVG (allow only SVG tags and attributes)
        $svg_icon = wp_kses( $svg_icon, array(
            'svg' => array(
                'width' => true,
                'height' => true,
                'viewBox' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'class' => true,
            ),
            'path' => array(
                'd' => true,
                'fill' => true,
                'stroke' => true,
            ),
            'g' => array(),
        ));

        $updated = $wpdb->update(
            $table,
            array(
                'svg_icon' => $svg_icon,
                'updated_at' => current_time('mysql'),
            ),
            array( 'id' => $site_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_error', __( 'Could not update site icon.', 'spintax-domain-manager' ) );
        }
        return $svg_icon;
    }

    /**
     * Delete a site and update related domains if necessary.
     *
     * @param int $site_id The ID of the site to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_site( $site_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';

        $site_id = absint( $site_id );
        if ( $site_id <= 0 ) {
            return new WP_Error( 'invalid_site_id', __( 'Invalid site ID.', 'spintax-domain-manager' ) );
        }

        // Start transaction to ensure data consistency
        $wpdb->query( 'START TRANSACTION' );

        // Delete the site
        $deleted = $wpdb->delete(
            $table,
            array( 'id' => $site_id ),
            array( '%d' )
        );

        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_delete_error', __( 'Could not delete site from database.', 'spintax-domain-manager' ) );
        }

        // Optionally update related domains to set site_id to NULL (unassign domains)
        $updated_domains = $wpdb->update(
            $wpdb->prefix . 'sdm_domains',
            array( 'site_id' => NULL ),
            array( 'site_id' => $site_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false === $updated_domains ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not unassign domains from the site.', 'spintax-domain-manager' ) );
        }

        // Optionally update related redirects to maintain data consistency
        $wpdb->delete(
            $wpdb->prefix . 'sdm_redirects',
            array( 'site_id' => $site_id ),
            array( '%d' )
        );

        $wpdb->query( 'COMMIT' );

        return true;
    }
} // <-- ВАЖНО: закрывающая скобка класса


/**
 * AJAX Handler: Update Site
 */
function sdm_ajax_update_site() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $manager = new SDM_Sites_Manager();
    $result  = $manager->update_site( $site_id, $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Site updated successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_update_site', 'sdm_ajax_update_site' );

/**
 * AJAX Handler: Add Site
 * Action: wp_ajax_sdm_add_site
 */
function sdm_ajax_add_site() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $manager = new SDM_Sites_Manager();
    $result  = $manager->add_site( $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'site_id' => $result ) );
}
add_action( 'wp_ajax_sdm_add_site', 'sdm_ajax_add_site' );


/**
 * AJAX Handler: Update Site Icon
 */
function sdm_ajax_update_site_icon() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $svg_icon = isset( $_POST['svg_icon'] ) ? wp_unslash( $_POST['svg_icon'] ) : '';

    $manager = new SDM_Sites_Manager();
    $result = $manager->update_site_icon( $site_id, $svg_icon );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 
        'message' => __( 'Icon updated successfully.', 'spintax-domain-manager' ),
        'svg_icon' => $result
    ) );
}
add_action( 'wp_ajax_sdm_update_site_icon', 'sdm_ajax_update_site_icon' );

/**
 * AJAX Handler: Validate Domain
 */
function sdm_ajax_validate_domain() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain = isset( $_POST['domain'] ) ? sanitize_text_field( $_POST['domain'] ) : '';
    if ( empty( $domain ) ) {
        wp_send_json_error( __( 'Domain is required.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Проверяем, существует ли домен в sdm_domains и соответствует требованиям
    $domain_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_domains 
         WHERE domain = %s 
           AND site_id IS NULL 
           AND is_blocked_provider = 0 
           AND is_blocked_government = 0",
        $domain
    ) );

    if ( $domain_exists <= 0 ) {
        wp_send_json_error( __( 'Domain is not available, blocked, or already assigned to a site.', 'spintax-domain-manager' ) );
    }

    // Проверяем, не используется ли домен другим сайтом в sdm_sites
    $domain_in_use = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_sites 
         WHERE main_domain = %s",
        $domain
    ) );

    if ( $domain_in_use > 0 ) {
        wp_send_json_error( __( 'This domain is already assigned to another site.', 'spintax-domain-manager' ) );
    }

    wp_send_json_success( __( 'Domain is valid and available.', 'spintax-domain-manager' ) );
}
add_action( 'wp_ajax_sdm_validate_domain', 'sdm_ajax_validate_domain' );

/**
 * AJAX Handler: Delete Site
 */
function sdm_ajax_delete_site() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    if ( $site_id <= 0 ) {
        wp_send_json_error( __( 'Invalid site ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Sites_Manager();
    $result = $manager->delete_site( $site_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Site deleted successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_delete_site', 'sdm_ajax_delete_site' );