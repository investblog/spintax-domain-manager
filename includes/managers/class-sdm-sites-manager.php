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
     * Adds a new site and assigns the main domain to it.
     */
    public function add_site( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';
        $domains_table = $wpdb->prefix . 'sdm_domains';

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

        // Start transaction for data consistency
        $wpdb->query( 'START TRANSACTION' );

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
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_insert_error', __( 'Could not insert site into database.', 'spintax-domain-manager' ) );
        }

        $site_id = $wpdb->insert_id;

        // Check if the domain exists before assigning
        $domain_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$domains_table} WHERE domain = %s",
            $main_domain
        ));

        if ( $domain_exists <= 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'domain_not_found', __( 'Specified main domain does not exist.', 'spintax-domain-manager' ) );
        }

        // Assign the main_domain to this site by updating site_id in sdm_domains
        $updated_domain = $wpdb->update(
            $domains_table,
            array( 'site_id' => $site_id, 'updated_at' => current_time('mysql') ),
            array( 'domain' => $main_domain, 'site_id' => NULL ), // Only unassigned domains
            array( '%d', '%s' ),
            array( '%s' )
        );

        if ( false === $updated_domain ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not assign domain to site.', 'spintax-domain-manager' ) );
        }

        $wpdb->query( 'COMMIT' );

        return $site_id;
    }

    /**
     * Update for inline editing and assign the main domain to the site.
     */
    public function update_site( $site_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';
        $domains_table = $wpdb->prefix . 'sdm_domains';

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

        if ( empty( $main_domain ) ) {
            return new WP_Error( 'invalid_main_domain', __( 'Main domain is required.', 'spintax-domain-manager' ) );
        }
        if ( empty( $language ) ) {
            return new WP_Error( 'invalid_language', __( 'Language is required.', 'spintax-domain-manager' ) );
        }

        // Start transaction for data consistency
        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->update(
            $table,
            array(
                'site_name'   => $site_name,
                'main_domain' => $main_domain,
                'server_ip'   => $server_ip,
                'language'    => $language,
                'updated_at'  => current_time('mysql'),
            ),
            array( 'id' => $site_id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not update site.', 'spintax-domain-manager' ) );
        }

        // If main_domain has changed, update site_id in sdm_domains
        if ($main_domain !== $old->main_domain) {
            // Check if the new domain exists
            $domain_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$domains_table} WHERE domain = %s",
                $main_domain
            ));

            if ( $domain_exists <= 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'domain_not_found', __( 'Specified main domain does not exist.', 'spintax-domain-manager' ) );
            }

            // Unassign the old domain (set site_id to NULL)
            $unassign_old = $wpdb->update(
                $domains_table,
                array( 'site_id' => NULL, 'updated_at' => current_time('mysql') ),
                array( 'domain' => $old->main_domain ),
                array( '%s', '%s' ),
                array( '%s' )
            );

            if ( false === $unassign_old ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_update_error', __( 'Could not unassign old domain from site.', 'spintax-domain-manager' ) );
            }

            // Assign the new main_domain to this site
            $assign_new = $wpdb->update(
                $domains_table,
                array( 'site_id' => $site_id, 'updated_at' => current_time('mysql') ),
                array( 'domain' => $main_domain, 'site_id' => NULL ), // Only unassigned domains
                array( '%d', '%s' ),
                array( '%s' )
            );

            if ( false === $assign_new ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_update_error', __( 'Could not assign new domain to site.', 'spintax-domain-manager' ) );
            }
        }

        $wpdb->query( 'COMMIT' );

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
        $domains_table = $wpdb->prefix . 'sdm_domains';

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

        // Unassign related domains (set site_id to NULL)
        $updated_domains = $wpdb->update(
            $domains_table,
            array( 'site_id' => NULL, 'updated_at' => current_time('mysql') ),
            array( 'site_id' => $site_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated_domains ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not unassign domains from the site.', 'spintax-domain-manager' ) );
        }

        // Optionally update related redirects to maintain data consistency (if redirects are implemented)
        $wpdb->delete(
            $wpdb->prefix . 'sdm_redirects',
            array( 'site_id' => $site_id ),
            array( '%d' )
        );

        $wpdb->query( 'COMMIT' );

        return true;
    }
} /** <-- End of Class **/

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
    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    if ( empty( $domain ) ) {
        wp_send_json_error( __( 'Domain is required.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Проверяем, существует ли домен в sdm_domains и соответствует требованиям
    $domain_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_domains 
         WHERE domain = %s 
           AND status = 'active' 
           AND is_blocked_provider = 0 
           AND is_blocked_government = 0",
        $domain
    ) );

    if ( $domain_exists <= 0 ) {
        wp_send_json_error( __( 'Domain is not active, blocked, or does not exist.', 'spintax-domain-manager' ) );
    }

    // Проверяем, не используется ли домен другим сайтом в sdm_sites, исключая текущий сайт
    $domain_in_use = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_sites 
         WHERE main_domain = %s" . ($site_id > 0 ? " AND id != %d" : ""),
        $domain,
        $site_id
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

/**
 * AJAX Handler: Get Non-Blocked Domains for a Project
 */
function sdm_ajax_get_non_blocked_domains() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    $domains = $wpdb->get_col( $wpdb->prepare(
        "SELECT domain FROM {$prefix}sdm_domains 
         WHERE project_id = %d 
           AND site_id IS NULL 
           AND status = 'active' 
           AND is_blocked_provider = 0 
           AND is_blocked_government = 0" .
           ( $term ? " AND domain LIKE %s" : "" ),
        $project_id,
        $term ? '%' . $wpdb->esc_like( $term ) . '%' : ''
    ) );

    if ( empty( $domains ) ) {
        wp_send_json_success( array() );
    }

    wp_send_json_success( $domains );
}
add_action( 'wp_ajax_sdm_get_non_blocked_domains', 'sdm_ajax_get_non_blocked_domains' );

/**
 * AJAX Handler: Get Non-Blocked Domains for a Site
 */
function sdm_ajax_get_non_blocked_domains_for_site() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';

    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Get domains for the project that are active and not blocked
    $domains_query = "SELECT domain FROM {$prefix}sdm_domains 
                     WHERE project_id = %d 
                       AND status = 'active' 
                       AND is_blocked_provider = 0 
                       AND is_blocked_government = 0";
    $params = array( $project_id );

    // If site_id is provided, include domains assigned to this site or unassigned
    if ( $site_id > 0 ) {
        $domains_query .= " AND (site_id IS NULL OR site_id = %d)";
        $params[] = $site_id;
    }

    // Add search term if provided
    if ( $term ) {
        $domains_query .= " AND domain LIKE %s";
        $params[] = '%' . $wpdb->esc_like( $term ) . '%';
    }

    $domains = $wpdb->get_col( $wpdb->prepare( $domains_query, $params ) );

    // Filter out duplicates and sort
    $domains = array_unique( $domains );
    sort( $domains );

    if ( empty( $domains ) ) {
        wp_send_json_success( array() );
    }

    wp_send_json_success( $domains );
}
add_action( 'wp_ajax_sdm_get_non_blocked_domains_for_site', 'sdm_ajax_get_non_blocked_domains_for_site' );