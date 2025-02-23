<?php
/**
 * File: includes/managers/class-sdm-redirects-manager.php
 * Description: Manager for handling redirects operations, including CRUD for redirects and integration with CloudFlare.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Redirects_Manager {

    /**
     * Adds a new redirect.
     *
     * @param array $data Associative array with keys: domain_id, source_url, target_url, type, redirect_type, preserve_query_string, user_agent
     * @param int $project_id The ID of the project.
     * @return int|WP_Error Redirect ID on success, WP_Error on failure.
     */
    public function add_redirect( $data, $project_id ) {
        global $wpdb;

        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_project', __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }

        $domain_id = isset( $data['domain_id'] ) ? absint( $data['domain_id'] ) : 0;
        if ( $domain_id <= 0 ) {
            return new WP_Error( 'invalid_domain', __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
        }

        $source_url = isset( $data['source_url'] ) ? sanitize_text_field( $data['source_url'] ) : '';
        $target_url = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';
        $type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '301';
        $redirect_type = isset( $data['redirect_type'] ) ? sanitize_text_field( $data['redirect_type'] ) : 'main';
        $preserve_query_string = isset( $data['preserve_query_string'] ) ? (bool) $data['preserve_query_string'] : true;
        $user_agent = isset( $data['user_agent'] ) ? sanitize_text_field( $data['user_agent'] ) : '';

        if ( empty( $source_url ) || empty( $target_url ) ) {
            return new WP_Error( 'invalid_urls', __( 'Source and target URLs are required.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $type, array( '301', '302' ) ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid redirect type. Use 301 or 302.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $redirect_type, array( 'main', 'clue', 'hidden' ) ) ) {
            return new WP_Error( 'invalid_redirect_type', __( 'Invalid redirect type. Use main, clue, or hidden.', 'spintax-domain-manager' ) );
        }

        // Check if domain belongs to the project
        $domain_project_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT project_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
            $domain_id
        ));

        if ( $domain_project_id != $project_id ) {
            return new WP_Error( 'domain_mismatch', __( 'Domain does not belong to this project.', 'spintax-domain-manager' ) );
        }

        // Start transaction for data consistency
        $wpdb->query( 'START TRANSACTION' );

        $result = $wpdb->insert(
            $wpdb->prefix . 'sdm_redirects',
            array(
                'domain_id' => $domain_id,
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'redirect_type' => $redirect_type,
                'preserve_query_string' => $preserve_query_string,
                'user_agent' => $user_agent,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_insert_error', __( 'Could not insert redirect into database.', 'spintax-domain-manager' ) );
        }

        $redirect_id = $wpdb->insert_id;

        // Optionally sync with CloudFlare if needed
        $this->sync_redirect_to_cloudflare( $redirect_id );

        $wpdb->query( 'COMMIT' );

        return $redirect_id;
    }

    /**
     * Updates an existing redirect.
     *
     * @param int $redirect_id The ID of the redirect to update.
     * @param array $data Associative array with keys: domain_id, source_url, target_url, type, redirect_type, preserve_query_string, user_agent
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function update_redirect( $redirect_id, $data ) {
        global $wpdb;

        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            return new WP_Error( 'invalid_redirect', __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
        }

        $domain_id = isset( $data['domain_id'] ) ? absint( $data['domain_id'] ) : 0;
        if ( $domain_id <= 0 ) {
            return new WP_Error( 'invalid_domain', __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
        }

        $source_url = isset( $data['source_url'] ) ? sanitize_text_field( $data['source_url'] ) : '';
        $target_url = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';
        $type = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '301';
        $redirect_type = isset( $data['redirect_type'] ) ? sanitize_text_field( $data['redirect_type'] ) : 'main';
        $preserve_query_string = isset( $data['preserve_query_string'] ) ? (bool) $data['preserve_query_string'] : true;
        $user_agent = isset( $data['user_agent'] ) ? sanitize_text_field( $data['user_agent'] ) : '';

        if ( empty( $source_url ) || empty( $target_url ) ) {
            return new WP_Error( 'invalid_urls', __( 'Source and target URLs are required.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $type, array( '301', '302' ) ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid redirect type. Use 301 or 302.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $redirect_type, array( 'main', 'clue', 'hidden' ) ) ) {
            return new WP_Error( 'invalid_redirect_type', __( 'Invalid redirect type. Use main, clue, or hidden.', 'spintax-domain-manager' ) );
        }

        // Check if redirect exists and belongs to a domain in the project
        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, d.project_id
             FROM {$wpdb->prefix}sdm_redirects r
             LEFT JOIN {$prefix}sdm_domains d ON r.domain_id = d.id
             WHERE r.id = %d",
            $redirect_id
        ));

        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }

        // Start transaction for data consistency
        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->update(
            $wpdb->prefix . 'sdm_redirects',
            array(
                'domain_id' => $domain_id,
                'source_url' => $source_url,
                'target_url' => $target_url,
                'type' => $type,
                'redirect_type' => $redirect_type,
                'preserve_query_string' => $preserve_query_string,
                'user_agent' => $user_agent,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $redirect_id ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not update redirect in database.', 'spintax-domain-manager' ) );
        }

        // Optionally sync with CloudFlare if needed
        $this->sync_redirect_to_cloudflare( $redirect_id );

        $wpdb->query( 'COMMIT' );

        return true;
    }

    /**
     * Deletes a redirect.
     *
     * @param int $redirect_id The ID of the redirect to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_redirect( $redirect_id ) {
        global $wpdb;

        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            return new WP_Error( 'invalid_redirect', __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
        }

        // Check if redirect exists
        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sdm_redirects WHERE id = %d",
            $redirect_id
        ));

        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }

        // Start transaction for data consistency
        $wpdb->query( 'START TRANSACTION' );

        // Delete the redirect
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'sdm_redirects',
            array( 'id' => $redirect_id ),
            array( '%d' )
        );

        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_delete_error', __( 'Could not delete redirect from database.', 'spintax-domain-manager' ) );
        }

        // Optionally remove from CloudFlare if needed
        $this->remove_redirect_from_cloudflare( $redirect_id );

        $wpdb->query( 'COMMIT' );

        return true;
    }

    /**
     * Gets a redirect by ID.
     *
     * @param int $redirect_id The ID of the redirect to retrieve.
     * @return array|WP_Error Redirect data on success, WP_Error on failure.
     */
    public function get_redirect( $redirect_id ) {
        global $wpdb;

        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            return new WP_Error( 'invalid_redirect', __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
        }

        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, d.domain, d.is_blocked_provider, d.is_blocked_government
             FROM {$wpdb->prefix}sdm_redirects r
             LEFT JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id
             WHERE r.id = %d",
            $redirect_id
        ));

        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }

        return array(
            'id' => $redirect->id,
            'domain_id' => $redirect->domain_id,
            'domain' => $redirect->domain,
            'source_url' => $redirect->source_url,
            'target_url' => $redirect->target_url,
            'type' => $redirect->type,
            'redirect_type' => $redirect->redirect_type,
            'preserve_query_string' => (bool) $redirect->preserve_query_string,
            'user_agent' => $redirect->user_agent,
            'created_at' => $redirect->created_at,
            'updated_at' => $redirect->updated_at,
            'is_blocked' => (bool) ($redirect->is_blocked_provider || $redirect->is_blocked_government),
        );
    }

    /**
     * Creates a default redirect (Main type) for a domain.
     *
     * @param int $domain_id The ID of the domain.
     * @param int $project_id The ID of the project.
     * @return int|WP_Error Redirect ID on success, WP_Error on failure.
     */
    public function create_default_redirect( $domain_id, $project_id ) {
        global $wpdb;

        $domain_id = absint( $domain_id );
        $project_id = absint( $project_id );
        if ( $domain_id <= 0 || $project_id <= 0 ) {
            return new WP_Error( 'invalid_ids', __( 'Invalid domain or project ID.', 'spintax-domain-manager' ) );
        }

        // Check if domain belongs to the project
        $domain_project_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT project_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
            $domain_id
        ));

        if ( $domain_project_id != $project_id ) {
            return new WP_Error( 'domain_mismatch', __( 'Domain does not belong to this project.', 'spintax-domain-manager' ) );
        }

        // Get the site and its main domain
        $site = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.id, s.main_domain FROM {$wpdb->prefix}sdm_sites s
             JOIN {$wpdb->prefix}sdm_domains d ON d.site_id = s.id
             WHERE d.id = %d",
            $domain_id
        ));

        if ( ! $site ) {
            return new WP_Error( 'no_site', __( 'No site found for this domain.', 'spintax-domain-manager' ) );
        }

        $main_domain = $site->main_domain;
        if ( empty( $main_domain ) ) {
            return new WP_Error( 'no_main_domain', __( 'No main domain found for the site.', 'spintax-domain-manager' ) );
        }

        // Check if this is not the main domain
        $domain = $wpdb->get_var( $wpdb->prepare(
            "SELECT domain FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
            $domain_id
        ));
        if ( $domain === $main_domain ) {
            return new WP_Error( 'main_domain', __( 'Cannot create a redirect for the main domain.', 'spintax-domain-manager' ) );
        }

        // Create default redirect (Main type: wildcard to main domain, 301, preserve query string)
        $data = array(
            'domain_id' => $domain_id,
            'source_url' => '/*',
            'target_url' => 'https://' . $main_domain . '/*',
            'type' => '301',
            'redirect_type' => 'main',
            'preserve_query_string' => true,
            'user_agent' => '',
        );

        return $this->add_redirect( $data, $project_id );
    }

    /**
     * Creates default redirects (Main type) for multiple domains.
     *
     * @param array $domain_ids Array of domain IDs.
     * @param int $project_id The ID of the project.
     * @return array Associative array with success count, failed count, and messages.
     */
    public function create_default_redirects( $domain_ids, $project_id ) {
        global $wpdb;

        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return array(
                'success' => 0,
                'failed' => count( $domain_ids ),
                'message' => __( 'Invalid project ID.', 'spintax-domain-manager' )
            );
        }

        $success = 0;
        $failed = 0;
        $messages = array();

        foreach ( $domain_ids as $domain_id ) {
            $domain_id = absint( $domain_id );
            if ( $domain_id <= 0 ) {
                $failed++;
                continue;
            }

            $result = $this->create_default_redirect( $domain_id, $project_id );
            if ( is_wp_error( $result ) ) {
                $failed++;
                $messages[] = sprintf( __( 'Failed to create default redirect for domain ID %d: %s', 'spintax-domain-manager' ), $domain_id, $result->get_error_message() );
            } else {
                $success++;
            }
        }

        $message = sprintf(
            __( '%d default redirects created successfully, %d failed.', 'spintax-domain-manager' ),
            $success,
            $failed
        );
        if ( ! empty( $messages ) ) {
            $message .= ' ' . implode( ' ', $messages );
        }

        return array(
            'success' => $success,
            'failed' => $failed,
            'message' => $message
        );
    }

    /**
     * Syncs a redirect to CloudFlare (placeholder for now, to be implemented).
     *
     * @param int $redirect_id The ID of the redirect to sync.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function sync_redirect_to_cloudflare( $redirect_id ) {
        // Placeholder for CloudFlare integration
        return true;
    }

    /**
     * Removes a redirect from CloudFlare (placeholder for now, to be implemented).
     *
     * @param int $redirect_id The ID of the redirect to remove.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function remove_redirect_from_cloudflare( $redirect_id ) {
        // Placeholder for CloudFlare integration
        return true;
    }

    /**
     * Syncs all redirects for a project to CloudFlare.
     *
     * @param int $project_id The ID of the project.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function sync_redirects_to_cloudflare( $project_id ) {
        global $wpdb;

        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_project', __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }

        $redirects = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, d.cf_zone_id
                 FROM {$wpdb->prefix}sdm_redirects r
                 LEFT JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id
                 WHERE d.project_id = %d",
                $project_id
            )
        );

        if ( empty( $redirects ) ) {
            return new WP_Error( 'no_redirects', __( 'No redirects found for this project.', 'spintax-domain-manager' ) );
        }

        $cf_api = new SDM_Cloudflare_API(); // Assume this class exists and is configured
        $success_count = 0;
        $error_messages = array();

        foreach ( $redirects as $redirect ) {
            $zone_id = $redirect->cf_zone_id;
            if ( empty( $zone_id ) ) {
                $error_messages[] = sprintf( __( 'Zone ID not found for domain in redirect ID %d.', 'spintax-domain-manager' ), $redirect->id );
                continue;
            }

            $result = $this->sync_single_redirect_to_cloudflare( $redirect, $zone_id, $cf_api );
            if ( is_wp_error( $result ) ) {
                $error_messages[] = sprintf( __( 'Failed to sync redirect ID %d: %s', 'spintax-domain-manager' ), $redirect->id, $result->get_error_message() );
            } else {
                $success_count++;
            }
        }

        if ( empty( $error_messages ) ) {
            return true;
        }

        $message = sprintf(
            __( '%d redirects synced successfully, %d failed.', 'spintax-domain-manager' ),
            $success_count,
            count( $error_messages )
        );
        if ( ! empty( $error_messages ) ) {
            $message .= ' ' . implode( ' ', $error_messages );
        }

        return new WP_Error( 'partial_sync', $message );
    }

    /**
     * Syncs a single redirect to CloudFlare.
     *
     * @param object $redirect Redirect object from the database.
     * @param string $zone_id CloudFlare zone ID.
     * @param SDM_Cloudflare_API $cf_api CloudFlare API instance.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function sync_single_redirect_to_cloudflare( $redirect, $zone_id, $cf_api ) {
        // Check existing ruleset and limits
        $ruleset = $cf_api->get_ruleset( $zone_id, 'http_request_dynamic_redirect' );
        if ( is_wp_error( $ruleset ) ) {
            return $ruleset;
        }

        $ruleset_id = $ruleset['id'] ?? '';
        if ( empty( $ruleset_id ) ) {
            $result = $cf_api->create_ruleset( $zone_id, 'http_request_dynamic_redirect' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $ruleset_id = $result['id'];
        }

        // Get current rules
        $current_rules = $cf_api->list_rules( $zone_id, $ruleset_id );
        if ( is_wp_error( $current_rules ) ) {
            return $current_rules;
        }

        // Clear existing rules if needed (simplified for this example, can be optimized)
        foreach ( $current_rules as $rule ) {
            $cf_api->delete_rule( $zone_id, $ruleset_id, $rule['id'] );
        }

        // Build redirect rule expression based on redirect_type
        $expression = "http.host eq \"{$redirect->domain}\" and http.request.uri.path matches \"*\"";
        if ( $redirect->redirect_type === 'hidden' && ! empty( $redirect->user_agent ) ) {
            $user_agents = explode( ',', $redirect->user_agent );
            $user_agent_conditions = array();
            foreach ( $user_agents as $agent ) {
                $user_agent_conditions[] = "http.request.user_agent contains \"{$agent}\"";
            }
            $expression .= ' and (' . implode( ' or ', $user_agent_conditions ) . ')';
        }

        $rule_data = array(
            'expression' => $expression,
            'action' => 'redirect',
            'action_parameters' => array(
                'from_value' => array(
                    'target_url' => array(
                        'value' => $redirect->target_url
                    ),
                    'status_code' => (int) $redirect->type,
                    'preserve_query_string' => $redirect->preserve_query_string
                )
            )
        );

        $result = $cf_api->create_rule( $zone_id, $ruleset_id, $rule_data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }
}

/**
 * Normalize language code for Flag Icons (e.g., RU_ru -> ru, en_US -> en).
 *
 * @param string $language_code The language code to normalize.
 * @return string Normalized language code for Flag Icons.
 */
function sdm_normalize_language_code( $language_code ) {
    if ( empty( $language_code ) ) {
        return 'us'; // Default to US flag for English
    }
    // Convert to lowercase and take the first two characters (language code)
    $normalized = strtolower( substr( $language_code, 0, 2 ) );
    // Map specific codes to Flag Icons format if needed
    $mappings = array(
        'ru' => 'ru',
        'en' => 'us', // English -> US flag
        'es' => 'es',
        'fr' => 'fr',
        // Add more mappings as needed (e.g., for other languages like 'de' for German, 'it' for Italian, etc.)
    );
    return isset( $mappings[$normalized] ) ? $mappings[$normalized] : $normalized;
}

/**
 * AJAX Handler: Save Redirect
 */
function sdm_ajax_save_redirect() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    $redirect_id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
    $data = array(
        'domain_id' => isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0,
        'source_url' => isset( $_POST['source_url'] ) ? sanitize_text_field( $_POST['source_url'] ) : '',
        'target_url' => isset( $_POST['target_url'] ) ? esc_url_raw( $_POST['target_url'] ) : '',
        'type' => isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '301',
        'redirect_type' => isset( $_POST['redirect_type'] ) ? sanitize_text_field( $_POST['redirect_type'] ) : 'main',
        'preserve_query_string' => isset( $_POST['preserve_query_string'] ) ? (bool) $_POST['preserve_query_string'] : true,
        'user_agent' => isset( $_POST['user_agent'] ) ? sanitize_text_field( $_POST['user_agent'] ) : '',
    );

    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    if ( $redirect_id > 0 ) {
        $result = $manager->update_redirect( $redirect_id, $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( 'Redirect updated successfully.', 'spintax-domain-manager' ) ) );
    } else {
        $result = $manager->add_redirect( $data, $project_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( array( 'message' => __( 'Redirect added successfully.', 'spintax-domain-manager' ) ) );
    }
}
add_action( 'wp_ajax_sdm_save_redirect', 'sdm_ajax_save_redirect' );

/**
 * AJAX Handler: Get Redirect
 */
function sdm_ajax_get_redirect() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $redirect_id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
    if ( $redirect_id <= 0 ) {
        wp_send_json_error( __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->get_redirect( $redirect_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'data' => $result ) );
}
add_action( 'wp_ajax_sdm_get_redirect', 'sdm_ajax_get_redirect' );

/**
 * AJAX Handler: Delete Redirect
 */
function sdm_ajax_delete_redirect() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $redirect_id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
    if ( $redirect_id <= 0 ) {
        wp_send_json_error( __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->delete_redirect( $redirect_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Redirect deleted successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_delete_redirect', 'sdm_ajax_delete_redirect' );

/**
 * AJAX Handler: Sync Redirects to CloudFlare
 */
function sdm_ajax_sync_redirects_to_cloudflare() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->sync_redirects_to_cloudflare( $project_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Redirects synced with CloudFlare successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_sync_redirects_to_cloudflare', 'sdm_ajax_sync_redirects_to_cloudflare' );

/**
 * AJAX Handler: Mass Delete Redirects
 */
function sdm_ajax_mass_delete_redirects() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $redirect_ids = isset( $_POST['redirect_ids'] ) ? json_decode( stripslashes( $_POST['redirect_ids'] ), true ) : array();
    if ( empty( $redirect_ids ) ) {
        wp_send_json_error( __( 'No redirects selected.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $success = 0;
    $failed = 0;
    $messages = array();

    foreach ( $redirect_ids as $redirect_id ) {
        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            $failed++;
            continue;
        }

        $result = $manager->delete_redirect( $redirect_id );
        if ( is_wp_error( $result ) ) {
            $failed++;
            $messages[] = sprintf( __( 'Failed to delete redirect ID %d: %s', 'spintax-domain-manager' ), $redirect_id, $result->get_error_message() );
        } else {
            $success++;
        }
    }

    $message = sprintf(
        __( '%d redirects deleted successfully, %d failed.', 'spintax-domain-manager' ),
        $success,
        $failed
    );
    if ( ! empty( $messages ) ) {
        $message .= ' ' . implode( ' ', $messages );
    }

    if ( $success > 0 || $failed > 0 ) {
        wp_send_json_success( array( 'message' => $message ) );
    } else {
        wp_send_json_error( $message );
    }
}
add_action( 'wp_ajax_sdm_mass_delete_redirects', 'sdm_ajax_mass_delete_redirects' );

/**
 * AJAX Handler: Mass Sync Redirects to CloudFlare
 */
function sdm_ajax_mass_sync_redirects_to_cloudflare() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $redirect_ids = isset( $_POST['redirect_ids'] ) ? json_decode( stripslashes( $_POST['redirect_ids'] ), true ) : array();
    if ( empty( $redirect_ids ) ) {
        wp_send_json_error( __( 'No redirects selected.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $success = 0;
    $failed = 0;
    $messages = array();

    global $wpdb;
    $prefix = $wpdb->prefix;

    foreach ( $redirect_ids as $redirect_id ) {
        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            $failed++;
            continue;
        }

        $redirect = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, d.cf_zone_id
             FROM {$prefix}sdm_redirects r
             LEFT JOIN {$prefix}sdm_domains d ON r.domain_id = d.id
             WHERE r.id = %d",
            $redirect_id
        ));

        if ( ! $redirect ) {
            $failed++;
            $messages[] = sprintf( __( 'Redirect ID %d not found.', 'spintax-domain-manager' ), $redirect_id );
            continue;
        }

        $result = $manager->sync_single_redirect_to_cloudflare( $redirect, $redirect->cf_zone_id, new SDM_Cloudflare_API() );
        if ( is_wp_error( $result ) ) {
            $failed++;
            $messages[] = sprintf( __( 'Failed to sync redirect ID %d: %s', 'spintax-domain-manager' ), $redirect_id, $result->get_error_message() );
        } else {
            $success++;
        }
    }

    $message = sprintf(
        __( '%d redirects synced with CloudFlare successfully, %d failed.', 'spintax-domain-manager' ),
        $success,
        $failed
    );
    if ( ! empty( $messages ) ) {
        $message .= ' ' . implode( ' ', $messages );
    }

    if ( $success > 0 || $failed > 0 ) {
        wp_send_json_success( array( 'message' => $message ) );
    } else {
        wp_send_json_error( $message );
    }
}
add_action( 'wp_ajax_sdm_mass_sync_redirects_to_cloudflare', 'sdm_ajax_mass_sync_redirects_to_cloudflare' );

/**
 * AJAX Handler: Create Default Redirect
 */
function sdm_ajax_create_default_redirect() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_id = isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0;
    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

    if ( $domain_id <= 0 || $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid domain or project ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->create_default_redirect( $domain_id, $project_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Default redirect created successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_create_default_redirect', 'sdm_ajax_create_default_redirect' );

/**
 * AJAX Handler: Mass Create Default Redirects
 */
function sdm_ajax_mass_create_default_redirects() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_ids = isset( $_POST['domain_ids'] ) ? json_decode( stripslashes( $_POST['domain_ids'] ), true ) : array();
    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

    if ( empty( $domain_ids ) ) {
        wp_send_json_error( __( 'No domains selected.', 'spintax-domain-manager' ) );
    }
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->create_default_redirects( $domain_ids, $project_id );

    if ( $result['success'] > 0 || $result['failed'] > 0 ) {
        wp_send_json_success( array( 'message' => $result['message'] ) );
    } else {
        wp_send_json_error( $result['message'] );
    }
}
add_action( 'wp_ajax_sdm_mass_create_default_redirects', 'sdm_ajax_mass_create_default_redirects' );


/**
 * AJAX Handler: Fetch Redirects List with Sorting
 */
function sdm_ajax_fetch_redirects_list() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    ob_start(); // Start output buffering for HTML
    ?>

    <div class="wrap">
        <h1><?php esc_html_e( 'Redirects', 'spintax-domain-manager' ); ?></h1>

        <!-- Hidden field for global nonce -->
        <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr( sdm_create_main_nonce() ); ?>">

        <!-- Notice container -->
        <div id="sdm-redirects-notice" class="sdm-notice"></div>

        <!-- Project Indicator (additional context) -->
        <p class="sdm-project-indicator" style="margin: 10px 0 20px; font-size: 14px; color: #666;">
            <?php 
            $project = $wpdb->get_row( $wpdb->prepare(
                "SELECT project_name FROM {$prefix}sdm_projects WHERE id = %d",
                $project_id
            ));
            echo sprintf( __( 'Viewing redirects for project: %d - %s', 'spintax-domain-manager' ), 
                $project_id, 
                esc_html( $project->project_name ?: 'Unknown' ) ); 
            ?>
        </p>

        <!-- Action Buttons -->
        <div style="margin-bottom: 20px;">
            <button id="sdm-sync-cloudflare" class="button sdm-action-button" style="background-color: #0073aa; color: #fff;">
                <?php esc_html_e( 'Sync with CloudFlare', 'spintax-domain-manager' ); ?>
            </button>
        </div>

        <?php
        // Получаем все сайты проекта для группировки
        $sites = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.site_name, s.language, s.svg_icon, s.main_domain
                 FROM {$prefix}sdm_sites s
                 WHERE s.project_id = %d
                 ORDER BY s.site_name ASC",
                $project_id
            )
        );

        if ( ! empty( $sites ) ) : ?>
            <?php foreach ( $sites as $site ) : ?>
                <h3><?php echo esc_html( $site->site_name ); ?>
                    <?php if ( ! empty( $site->svg_icon ) ) : ?>
                        <span class="sdm-site-icon" style="vertical-align: middle; margin-left: 5px;"><?php echo wp_kses( $site->svg_icon, array( 'svg' => array( 'class' => true, 'width' => true, 'height' => true ), 'path' => array( 'd' => true ) ) ); ?></span>
                    <?php else : ?>
                        <span class="fi fi-<?php echo esc_attr( sdm_normalize_language_code( $site->language ?: 'en' ) ); ?>" style="vertical-align: middle; margin-left: 5px;"></span>
                    <?php endif; ?>
                </h3>
                <p><?php echo esc_html__( 'Main Domain:', 'spintax-domain-manager' ) . ' ' . esc_html( $site->main_domain ); ?></p>
                <table class="wp-list-table widefat fixed striped sdm-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Domain', 'spintax-domain-manager' ); ?></th>
                            <th><?php esc_html_e( 'Redirect Status', 'spintax-domain-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?>
                                <input type="checkbox" class="sdm-select-all-site-redirects" data-site-id="<?php echo esc_attr( $site->id ); ?>" style="margin-left: 5px; vertical-align: middle;">
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $domains = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT d.*, r.id AS redirect_id, r.source_url, r.target_url, r.type, r.redirect_type, r.preserve_query_string, r.user_agent, r.created_at AS redirect_created_at
                                 FROM {$prefix}sdm_domains d
                                 LEFT JOIN {$prefix}sdm_redirects r ON d.id = r.domain_id
                                 WHERE d.project_id = %d AND d.site_id = %d
                                 ORDER BY d.domain ASC",
                                $project_id,
                                $site->id
                            )
                        );
                        if ( ! empty( $domains ) ) : ?>
                            <?php foreach ( $domains as $domain ) : ?>
                                <?php
                                $is_blocked = ( $domain->is_blocked_provider || $domain->is_blocked_government );
                                $redirect = (object) array(
                                    'id' => $domain->redirect_id,
                                    'domain_id' => $domain->id,
                                    'source_url' => $domain->source_url,
                                    'target_url' => $domain->target_url,
                                    'type' => $domain->type,
                                    'redirect_type' => $domain->redirect_type,
                                    'preserve_query_string' => $domain->preserve_query_string,
                                    'user_agent' => $domain->user_agent,
                                    'created_at' => $domain->redirect_created_at,
                                );
                                $redirect_type = $redirect->id ? $redirect->redirect_type : '';
                                $redirect_status = $redirect->id ? sprintf( __( 'Redirect exists (%s)', 'spintax-domain-manager' ), ucfirst( $redirect_type ) ) : __( 'No redirect', 'spintax-domain-manager' );
                                $is_main_domain = ($domain->domain === $site->main_domain);
                                ?>
                                <tr id="redirect-row-<?php echo esc_attr( $domain->id ); ?>"
                                    data-domain-id="<?php echo esc_attr( $domain->id ); ?>"
                                    data-update-nonce="<?php echo esc_attr( sdm_create_main_nonce() ); ?>"
                                    data-redirect-type="<?php echo esc_attr( $redirect_type ); ?>"
                                    data-domain="<?php echo esc_attr( $domain->domain ); ?>"
                                    data-site-id="<?php echo esc_attr( $site->id ); ?>"
                                    data-source-url="<?php echo esc_attr( $redirect->source_url ?: '' ); ?>"
                                    data-target-url="<?php echo esc_attr( $redirect->target_url ?: '' ); ?>"
                                    data-type="<?php echo esc_attr( $redirect->type ?: '' ); ?>"
                                    data-created-at="<?php echo esc_attr( $redirect->created_at ?: '' ); ?>">

                                    <td class="<?php echo $is_blocked ? 'sdm-blocked-domain' : ''; ?>">
                                        <?php echo esc_html( $domain->domain ); ?>
                                        <?php if ( !$is_main_domain ) : ?>
                                            <span class="sdm-redirect-arrow" data-redirect-type="<?php echo esc_attr( $redirect_type ); ?>"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $redirect_status ); ?></td>
                                    <td>
                                        <?php if ( !$is_main_domain ) : ?>
                                            <input type="checkbox" class="sdm-redirect-checkbox" value="<?php echo esc_attr( $domain->id ); ?>" data-site-id="<?php echo esc_attr( $site->id ); ?>">
                                            <?php if ( $redirect->id ) : ?>
                                                <a href="#" class="sdm-action-button sdm-delete-redirect" data-redirect-id="<?php echo esc_attr( $redirect->id ); ?>" style="background-color: #dc3232; color: #fff; margin-left: 5px;">
                                                    <?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?>
                                                </a>
                                            <?php else : ?>
                                                <a href="#" class="sdm-action-button sdm-create-redirect" data-domain-id="<?php echo esc_attr( $domain->id ); ?>" style="background-color: #0073aa; color: #fff;">
                                                    <?php esc_html_e( 'Create Default Redirect', 'spintax-domain-manager' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <?php esc_html_e( 'Main Domain (no redirect)', 'spintax-domain-manager' ); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e( 'No domains found for this site.', 'spintax-domain-manager' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="sdm-mass-actions" style="margin: 20px 0;">
                    <select class="sdm-mass-action-select-site" data-site-id="<?php echo esc_attr( $site->id ); ?>">
                        <option value=""><?php esc_html_e( 'Select Mass Action', 'spintax-domain-manager' ); ?></option>
                        <option value="create_default"><?php esc_html_e( 'Create Default Redirects', 'spintax-domain-manager' ); ?></option>
                        <option value="sync_cloudflare"><?php esc_html_e( 'Sync with CloudFlare', 'spintax-domain-manager' ); ?></option>
                    </select>
                    <button class="button button-primary sdm-mass-action-apply-site" data-site-id="<?php echo esc_attr( $site->id ); ?>">Apply</button>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p style="margin: 20px 0; color: #666;"><?php esc_html_e( 'No sites found for this project.', 'spintax-domain-manager' ); ?></p>
        <?php endif; ?>
    </div>

    <?php
    $html = ob_get_clean(); // Get the buffered HTML
    wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_sdm_fetch_redirects_list', 'sdm_ajax_fetch_redirects_list' );