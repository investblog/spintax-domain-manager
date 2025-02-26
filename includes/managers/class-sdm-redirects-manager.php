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
     * Adds or updates a redirect for a given domain.
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

        if ( ! in_array( $type, array( '301', '302' ), true ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid redirect type. Use 301 or 302.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $redirect_type, array( 'main', 'glue', 'hidden' ), true ) ) {
            return new WP_Error( 'invalid_redirect_type', __( 'Invalid redirect type. Use main, glue, or hidden.', 'spintax-domain-manager' ) );
        }

        // Verify that the domain belongs to the project.
        $domain_project_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT project_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
                $domain_id
            )
        );
        if ( (int) $domain_project_id !== $project_id ) {
            return new WP_Error( 'domain_mismatch', __( 'Domain does not belong to this project.', 'spintax-domain-manager' ) );
        }

        $wpdb->query( 'START TRANSACTION' );

        // Check for an existing redirect for this domain.
        $existing_redirect_id = $wpdb->get_var( 
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sdm_redirects WHERE domain_id = %d",
                $domain_id
            )
        );

        if ( $existing_redirect_id ) {
            $result = $wpdb->update(
                $wpdb->prefix . 'sdm_redirects',
                array(
                    'source_url'            => $source_url,
                    'target_url'            => $target_url,
                    'type'                  => $type,
                    'redirect_type'         => $redirect_type,
                    'preserve_query_string' => $preserve_query_string,
                    'user_agent'            => $user_agent,
                    'updated_at'            => current_time( 'mysql' ),
                ),
                array( 'id' => $existing_redirect_id ),
                array( '%s','%s','%s','%s','%d','%s','%s' ),
                array( '%d' )
            );
            if ( false === $result ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_update_error', __( 'Could not update redirect in database.', 'spintax-domain-manager' ) );
            }
            $redirect_id = $existing_redirect_id;
        } else {
            $result = $wpdb->insert(
                $wpdb->prefix . 'sdm_redirects',
                array(
                    'domain_id'             => $domain_id,
                    'source_url'            => $source_url,
                    'target_url'            => $target_url,
                    'type'                  => $type,
                    'redirect_type'         => $redirect_type,
                    'preserve_query_string' => $preserve_query_string,
                    'user_agent'            => $user_agent,
                    'created_at'            => current_time( 'mysql' ),
                    'updated_at'            => current_time( 'mysql' ),
                ),
                array( '%d','%s','%s','%s','%s','%d','%s','%s','%s' )
            );
            if ( false === $result ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'db_insert_error', __( 'Could not insert redirect into database.', 'spintax-domain-manager' ) );
            }
            $redirect_id = $wpdb->insert_id;
        }

        // Optionally, sync with CloudFlare.
        // $this->sync_redirect_to_cloudflare( $redirect_id );

        $wpdb->query( 'COMMIT' );

        return $redirect_id;
    }

    /**
     * Updates an existing redirect by its ID.
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

        if ( ! in_array( $type, array( '301', '302' ), true ) ) {
            return new WP_Error( 'invalid_type', __( 'Invalid redirect type. Use 301 or 302.', 'spintax-domain-manager' ) );
        }

        if ( ! in_array( $redirect_type, array( 'main', 'glue', 'hidden' ), true ) ) {
            return new WP_Error( 'invalid_redirect_type', __( 'Invalid redirect type. Use main, glue, or hidden.', 'spintax-domain-manager' ) );
        }

        $redirect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, d.project_id FROM {$wpdb->prefix}sdm_redirects r
                 LEFT JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id
                 WHERE r.id = %d",
                $redirect_id
            )
        );
        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }

        $wpdb->query( 'START TRANSACTION' );

        $updated = $wpdb->update(
            $wpdb->prefix . 'sdm_redirects',
            array(
                'domain_id'             => $domain_id,
                'source_url'            => $source_url,
                'target_url'            => $target_url,
                'type'                  => $type,
                'redirect_type'         => $redirect_type,
                'preserve_query_string' => $preserve_query_string,
                'user_agent'            => $user_agent,
                'updated_at'            => current_time( 'mysql' ),
            ),
            array( 'id' => $redirect_id ),
            array( '%d','%s','%s','%s','%s','%d','%s','%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_update_error', __( 'Could not update redirect in database.', 'spintax-domain-manager' ) );
        }

        // Optionally, sync with CloudFlare.
        // $this->sync_redirect_to_cloudflare( $redirect_id );

        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Deletes a redirect by ID.
     */
    public function delete_redirect( $redirect_id ) {
        global $wpdb;
        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            return new WP_Error( 'invalid_redirect', __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
        }
        $redirect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sdm_redirects WHERE id = %d",
                $redirect_id
            )
        );
        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'sdm_redirects',
            array( 'id' => $redirect_id ),
            array( '%d' )
        );
        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_delete_error', __( 'Could not delete redirect from database.', 'spintax-domain-manager' ) );
        }
        // Optionally, remove from CloudFlare.
        // $this->remove_redirect_from_cloudflare( $redirect_id );
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Gets a redirect by ID.
     */
    public function get_redirect( $redirect_id ) {
        global $wpdb;
        $redirect_id = absint( $redirect_id );
        if ( $redirect_id <= 0 ) {
            return new WP_Error( 'invalid_redirect', __( 'Invalid redirect ID.', 'spintax-domain-manager' ) );
        }
        $redirect = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, d.domain, d.is_blocked_provider, d.is_blocked_government
                 FROM {$wpdb->prefix}sdm_redirects r
                 LEFT JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id
                 WHERE r.id = %d",
                $redirect_id
            )
        );
        if ( ! $redirect ) {
            return new WP_Error( 'not_found', __( 'Redirect not found.', 'spintax-domain-manager' ) );
        }
        return array(
            'id'                    => $redirect->id,
            'domain_id'             => $redirect->domain_id,
            'domain'                => $redirect->domain,
            'source_url'            => $redirect->source_url,
            'target_url'            => $redirect->target_url,
            'type'                  => $redirect->type,
            'redirect_type'         => $redirect->redirect_type,
            'preserve_query_string' => (bool) $redirect->preserve_query_string,
            'user_agent'            => $redirect->user_agent,
            'created_at'            => $redirect->created_at,
            'updated_at'            => $redirect->updated_at,
            'is_blocked'            => (bool) ($redirect->is_blocked_provider || $redirect->is_blocked_government),
        );
    }

    /**
     * Creates a default redirect (Main type) for a domain.
     */
    public function create_default_redirect( $domain_id, $project_id ) {
        global $wpdb;
        $domain_id = absint( $domain_id );
        $project_id = absint( $project_id );
        if ( $domain_id <= 0 || $project_id <= 0 ) {
            return new WP_Error( 'invalid_ids', __( 'Invalid domain or project ID.', 'spintax-domain-manager' ) );
        }
        $domain_project_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT project_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
                $domain_id
            )
        );
        if ( (int) $domain_project_id !== $project_id ) {
            return new WP_Error( 'domain_mismatch', __( 'Domain does not belong to this project.', 'spintax-domain-manager' ) );
        }
        $site = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.id, s.main_domain FROM {$wpdb->prefix}sdm_sites s
                 JOIN {$wpdb->prefix}sdm_domains d ON d.site_id = s.id
                 WHERE d.id = %d",
                $domain_id
            )
        );
        if ( ! $site ) {
            return new WP_Error( 'no_site', __( 'No site found for this domain.', 'spintax-domain-manager' ) );
        }
        $main_domain = $site->main_domain;
        if ( empty( $main_domain ) ) {
            return new WP_Error( 'no_main_domain', __( 'No main domain found for the site.', 'spintax-domain-manager' ) );
        }
        $domain = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT domain FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
                $domain_id
            )
        );
        if ( $domain === $main_domain ) {
            return new WP_Error( 'main_domain', __( 'Cannot create a redirect for the main domain.', 'spintax-domain-manager' ) );
        }
        $data = array(
            'domain_id'             => $domain_id,
            'source_url'            => '/*',
            'target_url'            => 'https://' . $main_domain . '/*',
            'type'                  => '301',
            'redirect_type'         => 'main',
            'preserve_query_string' => true,
            'user_agent'            => '',
        );
        return $this->add_redirect( $data, $project_id );
    }

    /**
     * Creates default redirects (Main type) for multiple domains.
     */
    public function create_default_redirects( $domain_ids, $project_id ) {
        global $wpdb;
        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return array(
                'success' => 0,
                'failed'  => count( $domain_ids ),
                'message' => __( 'Invalid project ID.', 'spintax-domain-manager' )
            );
        }
        $success  = 0;
        $failed   = 0;
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
                $messages[] = sprintf(
                    __( 'Failed to create default redirect for domain ID %d: %s', 'spintax-domain-manager' ),
                    $domain_id,
                    $result->get_error_message()
                );
            } else {
                $success++;
            }
        }
        $message = sprintf(
            __( '%d default redirects created/updated successfully, %d failed.', 'spintax-domain-manager' ),
            $success,
            $failed
        );
        if ( ! empty( $messages ) ) {
            $message .= ' ' . implode( ' ', $messages );
        }
        return array(
            'success' => $success,
            'failed'  => $failed,
            'message' => $message
        );
    }

    /**
     * Syncs Glue‑redirects via CloudFlare Rulesets.
     */
    public function sync_glue_redirects_to_cloudflare( $project_id ) {
        global $wpdb;
        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_project', 'Invalid project ID.' );
        }
        $creds = SDM_Cloudflare_API::get_project_cf_credentials( $project_id );
        if ( is_wp_error( $creds ) ) {
            return $creds;
        }
        $cf_api = new SDM_Cloudflare_API( $creds );
        $zone_ids = $wpdb->get_col( $wpdb->prepare("
            SELECT DISTINCT d.cf_zone_id
            FROM {$wpdb->prefix}sdm_domains d
            JOIN {$wpdb->prefix}sdm_redirects r ON r.domain_id = d.id
            WHERE d.project_id = %d
              AND d.cf_zone_id != '' AND d.cf_zone_id IS NOT NULL
              AND r.redirect_type = 'glue'
        ", $project_id ) );
        if ( empty( $zone_ids ) ) {
            return new WP_Error( 'no_zones', 'No glue redirects found for this project.' );
        }
        $success_count = 0;
        $errors = array();
        foreach ( $zone_ids as $zone_id ) {
            $result = $cf_api->rebuild_redirect_rules( $zone_id );
            if ( is_wp_error($result) ) {
                $errors[] = sprintf( '[Zone %s]: %s', $zone_id, $result->get_error_message() );
            } else {
                $success_count++;
            }
        }
        if ( ! empty($errors) ) {
            $msg = sprintf( 'Glue redirects: %d success, %d failed. Errors: %s', $success_count, count($errors), implode(' | ', $errors) );
            return new WP_Error( 'partial_sync', $msg );
        }
        return true;
    }

    /**
     * Syncs Main‑redirects via CloudFlare Page Rules.
     * 1. Удаляет все существующие Page Rules в каждой зоне.
     * 2. Создаёт новые Page Rules для каждого main-редиректа.
     *
     * @param int $project_id
     * @return true|WP_Error
     */
    public function sync_main_redirects_to_page_rules( $project_id ) {
        global $wpdb;
        $project_id = absint($project_id);
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_project', 'Invalid project ID.' );
        }
        $creds = SDM_Cloudflare_API::get_project_cf_credentials( $project_id );
        if ( is_wp_error($creds) ) {
            return $creds;
        }
        $cf_api = new SDM_Cloudflare_API( $creds );

        // Получаем все main-редиректы для проекта
        $redirects = $wpdb->get_results( $wpdb->prepare( "
            SELECT r.*, d.domain, d.cf_zone_id 
            FROM {$wpdb->prefix}sdm_redirects r 
            JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id 
            WHERE d.project_id = %d AND r.redirect_type = 'main'
        ", $project_id ) );

        $errors = [];
        $success_count = 0;
        $byZone = [];
        foreach ( $redirects as $r ) {
            if ( empty($r->cf_zone_id) ) {
                continue;
            }
            $byZone[$r->cf_zone_id][] = $r;
        }

        foreach ( $byZone as $zone_id => $zoneRedirects ) {
            // 1. Удаляем все Page Rules в зоне
            $delResp = $cf_api->delete_sdm_page_rules( $zone_id );
            if ( is_wp_error($delResp) ) {
                error_log("Failed to delete all Page Rules for zone {$zone_id}: " . $delResp->get_error_message());
                $errors[] = "Zone {$zone_id}: " . $delResp->get_error_message();
                continue;
            }

            // 2. Создаём новые Page Rules
            foreach ( $zoneRedirects as $redirect ) {
                $sourcePattern = "https://{$redirect->domain}/*";
                $targetDomain = $this->extract_domain_from_url( $redirect->target_url );
                if ( empty($targetDomain) ) {
                    $errors[] = "Domain ID {$redirect->domain_id}: cannot extract domain from target_url";
                    continue;
                }
                $targetUrl = "https://{$targetDomain}/\$1";
                $status_code = (int) $redirect->type;
                $desc = "SDM domain_id={$redirect->domain_id}";

                error_log("Creating Page Rule for zone {$zone_id}: {$sourcePattern} -> {$targetUrl}");
                $prResp = $cf_api->create_page_rule( $zone_id, $sourcePattern, $targetUrl, $status_code, $desc );
                if ( is_wp_error($prResp) ) {
                    error_log("Failed to create Page Rule for domain ID {$redirect->domain_id}: " . $prResp->get_error_message());
                    $errors[] = "Domain ID {$redirect->domain_id}: " . $prResp->get_error_message();
                } else {
                    $success_count++;
                }
            }
        }

        if ( !empty($errors) ) {
            $msg = sprintf( 'Main redirects: %d page rules created successfully, %d failed. Errors: %s', $success_count, count($errors), implode(' | ', $errors) );
            return new WP_Error( 'partial_sync', $msg );
        }
        return true;
    }

    /**
     * Helper: Extracts the domain from a URL.
     * For example, from "https://domain.com/*" returns "domain.com".
     *
     * @param string $url
     * @return string
     */
    private function extract_domain_from_url( $url ) {
        $url = preg_replace('#/\*$#', '', rtrim($url, '/'));
        $parts = @parse_url( $url );
        if ( ! empty( $parts['host'] ) ) {
            return $parts['host'];
        }
        if ( preg_match('/((xn--)?[a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}/i', $url, $m) ) {
            return $m[0];
        }
        return '';
    }

    /**
     * Syncs all redirects (Main + Glue) to CloudFlare.
     *
     * @param int $project_id
     * @return true|WP_Error
     */
    public function sync_redirects_to_cloudflare( $project_id ) {
        $messages = [];
        $errors = [];

        // Синхронизация Main-редиректов
        $mainResult = $this->sync_main_redirects_to_page_rules( $project_id );
        if ( is_wp_error( $mainResult ) ) {
            $errors[] = $mainResult->get_error_message();
        } else {
            $messages[] = "Main redirects synced successfully.";
        }

        // Синхронизация Glue-редиректов
        $glueResult = $this->sync_glue_redirects_to_cloudflare( $project_id );
        if ( is_wp_error( $glueResult ) ) {
            $error_message = $glueResult->get_error_message();
            if ( $glueResult->get_error_code() === 'no_zones' ) {
                $messages[] = "Glue redirects: " . $error_message; // Информационное сообщение
            } else {
                $errors[] = "Glue redirects: " . $error_message;
            }
        } else {
            $messages[] = "Glue redirects synced successfully.";
        }

        // Формируем итоговый результат
        $final_message = implode(' | ', array_merge($messages, $errors));
        if ( !empty($errors) ) {
            return new WP_Error( 'partial_sync', $final_message );
        }
        return $final_message; // Возвращаем строку при успехе
    }

    /**
     * Public method to sync selected redirects to CloudFlare.
     *
     * @param int   $project_id Project ID.
     * @param array $domain_ids Array of domain IDs to sync.
     * @return true|WP_Error|string
     */
    public function sync_selected_redirects_to_cloudflare($project_id, $domain_ids) {
        return $this->mass_sync_redirects_to_cloudflare($project_id, $domain_ids);
    }



    /**
     * Syncs redirects for selected domains to CloudFlare (Main + Glue).
     *
     * @param int   $project_id Project ID.
     * @param array $domain_ids Array of domain IDs to sync.
     * @return true|WP_Error|string
     */
    private function mass_sync_redirects_to_cloudflare($project_id, $domain_ids) {
        global $wpdb;
        $project_id = absint($project_id);
        if ($project_id <= 0 || empty($domain_ids)) {
            return new WP_Error('invalid_input', 'Invalid project ID or no domains selected.');
        }

        // Получаем редиректы только для выбранных domain_ids
        $redirects = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, d.domain, d.cf_zone_id 
                 FROM {$wpdb->prefix}sdm_redirects r 
                 JOIN {$wpdb->prefix}sdm_domains d ON r.domain_id = d.id 
                 WHERE d.project_id = %d 
                   AND r.domain_id IN (" . implode(',', array_fill(0, count($domain_ids), '%d')) . ")",
                array_merge([$project_id], array_map('absint', $domain_ids))
            )
        );

        if (empty($redirects)) {
            return new WP_Error('no_redirects', 'No redirects found for the selected domains.');
        }

        $creds = SDM_Cloudflare_API::get_project_cf_credentials($project_id);
        if (is_wp_error($creds)) {
            return $creds;
        }

        $cf_api = new SDM_Cloudflare_API($creds);

        $messages = [];
        $errors = [];

        // Группируем редиректы по зонам
        $byZone = [];
        foreach ($redirects as $r) {
            if (empty($r->cf_zone_id)) {
                $errors[] = sprintf('Domain ID %d: No Cloudflare zone ID.', $r->domain_id);
                continue;
            }
            $byZone[$r->cf_zone_id][] = $r;
        }

        // Синхронизация Main-редиректов
        $main_success_count = 0;
        foreach ($byZone as $zone_id => $zoneRedirects) {
            $mainRedirects = array_filter($zoneRedirects, function($r) {
                return $r->redirect_type === 'main';
            });

            if (!empty($mainRedirects)) {
                // Удаляем все существующие Page Rules в зоне
                $delResp = $cf_api->delete_sdm_page_rules($zone_id);
                if (is_wp_error($delResp)) {
                    $errors[] = sprintf('Zone %s: Failed to delete Page Rules - %s', $zone_id, $delResp->get_error_message());
                    continue;
                }

                // Создаем новые Page Rules для main-редиректов
                foreach ($mainRedirects as $redirect) {
                    $sourcePattern = "https://{$redirect->domain}/*";
                    $targetDomain = $this->extract_domain_from_url($redirect->target_url);
                    if (empty($targetDomain)) {
                        $errors[] = sprintf('Domain ID %d: Cannot extract domain from target_url', $redirect->domain_id);
                        continue;
                    }
                    $targetUrl = "https://{$targetDomain}/\$1";
                    $status_code = (int) $redirect->type;
                    $desc = "SDM domain_id={$redirect->domain_id}";

                    $prResp = $cf_api->create_page_rule($zone_id, $sourcePattern, $targetUrl, $status_code, $desc);
                    if (is_wp_error($prResp)) {
                        $errors[] = sprintf('Domain ID %d: %s', $redirect->domain_id, $prResp->get_error_message());
                    } else {
                        $main_success_count++;
                    }
                }
            }
        }

        // Синхронизация Glue-редиректов
        $glue_success_count = 0;
        foreach ($byZone as $zone_id => $zoneRedirects) {
            $glueRedirects = array_filter($zoneRedirects, function($r) {
                return $r->redirect_type === 'glue';
            });

            if (!empty($glueRedirects)) {
                $result = $cf_api->rebuild_redirect_rules($zone_id);
                if (is_wp_error($result)) {
                    $errors[] = sprintf('Zone %s: Glue sync failed - %s', $zone_id, $result->get_error_message());
                } else {
                    $glue_success_count += count($glueRedirects);
                }
            }
        }

        // Формируем результат
        $messages[] = sprintf('Main redirects: %d synced successfully.', $main_success_count);
        $messages[] = sprintf('Glue redirects: %d synced successfully.', $glue_success_count);

        $final_message = implode(' | ', array_merge($messages, $errors));
        if (!empty($errors)) {
            return new WP_Error('partial_sync', $final_message);
        }
        return $final_message; // Возвращаем строку при успехе
    }
} /* <------------------------Last bracket of Class*/

/**
 * Normalize language code for Flag Icons.
 */
function sdm_normalize_language_code( $language_code ) {
    if ( empty( $language_code ) ) {
        return 'us';
    }
    $normalized = strtolower( substr( $language_code, 0, 2 ) );
    $mappings = array(
        'ru' => 'ru',
        'en' => 'us',
        'es' => 'es',
        'fr' => 'fr',
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
 * AJAX Handler: Sync Redirects to CloudFlare (all redirects: main + glue).
 */
function sdm_ajax_sync_redirects_to_cloudflare() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }
    $manager = new SDM_Redirects_Manager();
    $result = $manager->sync_redirects_to_cloudflare( $project_id );
    if ( is_wp_error( $result ) ) {
        error_log( 'sync_redirects_to_cloudflare error: ' . $result->get_error_message() );
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Redirects synced with CloudFlare successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_sync_redirects_to_cloudflare', 'sdm_ajax_sync_redirects_to_cloudflare' );

/**
 * AJAX Handler: Mass Delete Redirects
 */
function sdm_ajax_mass_delete_redirects() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $domain_ids = isset($_POST['domain_ids']) ? json_decode(stripslashes($_POST['domain_ids']), true) : [];
    if (empty($domain_ids)) {
        wp_send_json_error(__('No domains selected.', 'spintax-domain-manager'));
    }

    global $wpdb;
    $redirect_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sdm_redirects WHERE domain_id IN (" . implode(',', array_fill(0, count($domain_ids), '%d')) . ")",
            ...array_map('absint', $domain_ids)
        )
    );

    if (empty($redirect_ids)) {
        wp_send_json_error(__('No redirects found for the selected domains.', 'spintax-domain-manager'));
    }

    $manager = new SDM_Redirects_Manager();
    $success = 0;
    $failed = 0;
    $messages = [];

    foreach ($redirect_ids as $redirect_id) {
        $result = $manager->delete_redirect($redirect_id);
        if (is_wp_error($result)) {
            $failed++;
            $messages[] = sprintf(__('Failed to delete redirect ID %d: %s', 'spintax-domain-manager'), $redirect_id, $result->get_error_message());
        } else {
            $success++;
        }
    }

    $message = sprintf(
        __('%d redirects deleted successfully, %d failed.', 'spintax-domain-manager'),
        $success,
        $failed
    );
    if (!empty($messages)) {
        $message .= ' ' . implode(' ', $messages);
    }

    if ($success > 0 || $failed > 0) {
        wp_send_json_success(['message' => $message]);
    } else {
        wp_send_json_error($message);
    }
}
add_action('wp_ajax_sdm_mass_delete_redirects', 'sdm_ajax_mass_delete_redirects');

/**
 * AJAX Handler: Sync Main Redirects via Page Rules.
 */
function sdm_ajax_sync_main_redirects() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $project_id = isset( $_POST['project_id'] ) ? absint($_POST['project_id']) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }
    $manager = new SDM_Redirects_Manager();
    $result  = $manager->sync_main_redirects_to_page_rules( $project_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Main redirects synced via Page Rules successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_sync_main_redirects', 'sdm_ajax_sync_main_redirects' );

/**
 * AJAX Handler: Sync Glue Redirects via Rulesets.
 */
function sdm_ajax_sync_glue_redirects() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $project_id = isset( $_POST['project_id'] ) ? absint($_POST['project_id']) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }
    $manager = new SDM_Redirects_Manager();
    $result  = $manager->sync_glue_redirects_to_cloudflare( $project_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Glue redirects synced via CloudFlare Rulesets successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_sync_glue_redirects', 'sdm_ajax_sync_glue_redirects' );

/**
 * AJAX Handler: Create a default redirect for a single domain.
 */
function sdm_ajax_create_default_redirect() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $domain_id  = isset( $_POST['domain_id'] ) ? absint($_POST['domain_id']) : 0;
    $project_id = isset( $_POST['project_id'] ) ? absint($_POST['project_id']) : 0;
    if ( $domain_id <= 0 || $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid domain or project ID.', 'spintax-domain-manager' ) );
    }
    $redirects_manager = new SDM_Redirects_Manager();
    $result = $redirects_manager->create_default_redirect( $domain_id, $project_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success(
        array(
            'message'     => __( 'Default redirect created or updated successfully.', 'spintax-domain-manager' ),
            'redirect_id' => $result,
        )
    );
}
add_action( 'wp_ajax_sdm_create_default_redirect', 'sdm_ajax_create_default_redirect' );

/**
 * AJAX Handler: Mass Create Default Redirects.
 */
function sdm_ajax_mass_create_default_redirects() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $domain_ids = isset( $_POST['domain_ids'] ) ? json_decode( stripslashes($_POST['domain_ids']), true ) : array();
    $project_id = isset( $_POST['project_id'] ) ? absint($_POST['project_id']) : 0;
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
 * AJAX Handler: Update redirect_type.
 */
function sdm_ajax_update_redirect_type() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $redirect_id = isset($_POST['redirect_id']) ? absint($_POST['redirect_id']) : 0;
    $new_type = isset($_POST['new_type']) ? sanitize_text_field($_POST['new_type']) : '';

    if (empty($redirect_id) || !in_array($new_type, array('main', 'glue', 'hidden'), true)) {
        wp_send_json_error(__('Invalid parameters.', 'spintax-domain-manager'));
    }

    global $wpdb;

    // Получаем текущую запись редиректа
    $redirect = $wpdb->get_row(
        $wpdb->prepare("SELECT domain_id FROM {$wpdb->prefix}sdm_redirects WHERE id = %d", $redirect_id)
    );
    if (!$redirect) {
        wp_send_json_error(__('Redirect not found.', 'spintax-domain-manager'));
    }

    // Получаем site_id и main_domain из sdm_sites через sdm_domains
    $site = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT s.main_domain 
             FROM {$wpdb->prefix}sdm_sites s 
             JOIN {$wpdb->prefix}sdm_domains d ON d.site_id = s.id 
             WHERE d.id = %d",
            $redirect->domain_id
        )
    );
    if (!$site || empty($site->main_domain)) {
        wp_send_json_error(__('Main domain not found for this site.', 'spintax-domain-manager'));
    }

    // Формируем target_url на основе главного домена сайта
    $target_url = "https://{$site->main_domain}";
    if ($new_type === 'main') {
        $target_url .= '/*';
    } else if ($new_type === 'glue') {
        $target_url .= '/';
    } // Для hidden оставляем чистый https://main_domain

    // Обновляем redirect_type и target_url
    $updated = $wpdb->update(
        $wpdb->prefix . 'sdm_redirects',
        array(
            'redirect_type' => $new_type,
            'target_url' => $target_url
        ),
        array('id' => $redirect_id),
        array('%s', '%s'),
        array('%d')
    );

    if ($updated === false) {
        wp_send_json_error(__('Database update failed.', 'spintax-domain-manager'));
    }

    wp_send_json_success(array(
        'message' => __('Redirect type updated.', 'spintax-domain-manager'),
        'target_url' => $target_url
    ));
}
add_action('wp_ajax_sdm_update_redirect_type', 'sdm_ajax_update_redirect_type');

/**
 * AJAX Handler: Fetch Redirects List with Sorting.
 */
function sdm_ajax_fetch_redirects_list() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
    $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : '';
    $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : '';
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }
    global $wpdb;
    $prefix = $wpdb->prefix;
    ob_start();
    // Get sites for the project.
    $sites = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT s.id, s.site_name, s.language, s.svg_icon, s.main_domain
             FROM {$prefix}sdm_sites s
             WHERE s.project_id = %d
             ORDER BY s.site_name ASC",
            $project_id
        )
    );
    if ( ! empty($sites) ) :
        foreach ( $sites as $site ) : ?>
            <h3>
                <?php if ( ! empty($site->svg_icon) ) : ?>
                    <span class="sdm-site-icon" style="vertical-align: middle; margin-right: 5px;">
                        <?php echo wp_kses( $site->svg_icon, array( 'svg' => array( 'class' => true, 'width' => true, 'height' => true ), 'path' => array( 'd' => true ) ) ); ?>
                    </span>
                <?php else : ?>
                    <span class="fi fi-<?php echo esc_attr( sdm_normalize_language_code( $site->language ?: 'en' ) ); ?>" style="vertical-align: middle; margin-left: 5px;"></span>
                <?php endif; ?>
                <?php echo esc_html( $site->site_name ); ?>
            </h3>
            <p>
                <?php echo esc_html__('Main Domain:', 'spintax-domain-manager') . ' ' . esc_html( $site->main_domain ); ?>
            </p>
            <table class="wp-list-table widefat fixed striped sdm-table">
                <thead>
                    <tr>
                        <th class="sdm-sortable" data-column="domain"><?php esc_html_e('Domain', 'spintax-domain-manager'); ?></th>
                        <th><?php esc_html_e('Redirect Type', 'spintax-domain-manager'); ?></th>
                        <th>
                            <?php esc_html_e('Actions', 'spintax-domain-manager'); ?>
                            <input type="checkbox" class="sdm-select-all-site-redirects" data-site-id="<?php echo esc_attr($site->id); ?>" style="margin-left: 5px; vertical-align: middle;">
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $order_by = '';
                    if ( ! empty($sort_column) && ! empty($sort_direction) ) {
                        $valid_columns = array('domain', 'redirect_status');
                        if ( in_array( $sort_column, $valid_columns ) ) {
                            if ( $sort_column === 'redirect_status' ) {
                                $order_by = "ORDER BY COALESCE(r.redirect_type, 'no_redirect') " . ( $sort_direction === 'desc' ? 'DESC' : 'ASC' );
                            } else {
                                $order_by = "ORDER BY d.{$sort_column} " . ( $sort_direction === 'desc' ? 'DESC' : 'ASC' );
                            }
                        }
                    }
                    $domains = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT d.*, 
                                    r.id AS redirect_id, 
                                    r.source_url, 
                                    r.target_url, 
                                    r.type, 
                                    r.redirect_type, 
                                    r.preserve_query_string, 
                                    r.user_agent, 
                                    r.created_at AS redirect_created_at
                             FROM {$prefix}sdm_domains d
                             LEFT JOIN {$prefix}sdm_redirects r ON d.id = r.domain_id
                             WHERE d.project_id = %d AND d.site_id = %d $order_by",
                            $project_id,
                            $site->id
                        )
                    );
                    if ( ! empty($domains) ) :
                        foreach ( $domains as $domain ) :
                            $is_blocked = ( $domain->is_blocked_provider || $domain->is_blocked_government );
                            $redirect = (object) array(
                                'id'                    => $domain->redirect_id,
                                'domain_id'             => $domain->id,
                                'source_url'            => $domain->source_url,
                                'target_url'            => $domain->target_url,
                                'type'                  => $domain->type,
                                'redirect_type'         => $domain->redirect_type,
                                'preserve_query_string' => $domain->preserve_query_string,
                                'user_agent'            => $domain->user_agent,
                                'created_at'            => $domain->redirect_created_at,
                            );
                            $redirect_type  = $redirect->id ? $redirect->redirect_type : '';
                            $is_main_domain = ( $domain->domain === $site->main_domain );
                            $has_redirect_arrow = ( $redirect->id && ! $is_main_domain ) ? 'sdm-has-arrow' : '';
                            ?>
                            <tr id="redirect-row-<?php echo esc_attr($domain->id); ?>"
                                data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                data-update-nonce="<?php echo esc_attr( sdm_create_main_nonce() ); ?>"
                                data-redirect-type="<?php echo esc_attr( $redirect_type ?: 'none' ); ?>"
                                data-domain="<?php echo esc_attr($domain->domain); ?>"
                                data-site-id="<?php echo esc_attr($site->id); ?>"
                                data-source-url="<?php echo esc_attr($redirect->source_url ?: ''); ?>"
                                data-target-url="<?php echo esc_attr($redirect->target_url ?: ''); ?>"
                                data-type="<?php echo esc_attr($redirect->type ?: ''); ?>"
                                data-created-at="<?php echo esc_attr($redirect->created_at ?: ''); ?>">
                                <td class="sdm-domain <?php echo $is_blocked ? 'sdm-blocked-domain' : ''; ?> <?php echo esc_attr($has_redirect_arrow); ?>"
                                    data-redirect-type="<?php echo esc_attr($redirect_type ?: 'none'); ?>">
                                    <?php echo esc_html($domain->domain); ?>
                                </td>
                                <td class="sdm-redirect-type-cell" data-redirect-id="<?php echo esc_attr($redirect->id); ?>" data-current-type="<?php echo esc_attr($redirect_type ?: 'main'); ?>">
                                    <?php if ($redirect->id) : ?>
                                        <?php
                                            $svg_markup = sdm_get_inline_redirect_svg($redirect_type ?: 'main');
                                        ?>
                                        <?php if (!empty($svg_markup)) : ?>
                                            <span class="sdm-redirect-type-display sdm-redirect-type-<?php echo esc_attr($redirect_type ?: 'main'); ?>">
                                                <?php echo $svg_markup; ?>
                                            </span>
                                            <?php if (!empty($redirect->target_url)) : ?>
                                                <span class="sdm-target-domain" style="margin-left: 6px;">
                                                    <?php echo esc_html($redirect->target_url); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <em style="color:#999;"><?php esc_html_e('Unknown icon', 'spintax-domain-manager'); ?></em>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em style="color:#999;"><?php esc_html_e('No redirect', 'spintax-domain-manager'); ?></em>
                                    <?php endif; ?>
                                    <div class="sdm-redirect-type-selector" style="display: none;">
                                        <button type="button" class="sdm-type-option" data-value="main">
                                            <?php echo sdm_get_inline_redirect_svg('main'); ?>
                                        </button>
                                        <button type="button" class="sdm-type-option" data-value="glue">
                                            <?php echo sdm_get_inline_redirect_svg('glue'); ?>
                                        </button>
                                        <button type="button" class="sdm-type-option" data-value="hidden">
                                            <?php echo sdm_get_inline_redirect_svg('hidden'); ?>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?php if ( ! $is_main_domain ) : ?>
                                        <input type="checkbox" class="sdm-redirect-checkbox" value="<?php echo esc_attr($domain->id); ?>" data-site-id="<?php echo esc_attr($site->id); ?>">
                                        <?php if ($redirect->id) : ?>
                                            <button type="button" class="sdm-action-button sdm-delete sdm-mini-icon" data-redirect-id="<?php echo esc_attr($redirect->id); ?>" title="<?php esc_attr_e('Delete', 'spintax-domain-manager'); ?>">
                                                <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="<?php esc_attr_e('Delete', 'spintax-domain-manager'); ?>" />
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="sdm-action-button sdm-create-redirect sdm-mini-icon" data-domain-id="<?php echo esc_attr($domain->id); ?>" title="<?php esc_attr_e('Create Default Redirect', 'spintax-domain-manager'); ?>">
                                                <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/spintax-icon.svg'); ?>" alt="<?php esc_attr_e('Create Default', 'spintax-domain-manager'); ?>" />
                                            </button>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <?php esc_html_e('Main Domain (no redirect)', 'spintax-domain-manager'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    else : ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e('No domains found for this site.', 'spintax-domain-manager'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="sdm-mass-actions" style="margin: 20px 0;">
            <select class="sdm-mass-action-select-site" data-site-id="<?php echo esc_attr($site->id); ?>">
                <option value=""><?php esc_html_e('Select Mass Action', 'spintax-domain-manager'); ?></option>
                <option value="create_default"><?php esc_html_e('Create Default Redirects', 'spintax-domain-manager'); ?></option>
                <option value="mass_delete"><?php esc_html_e('Delete Selected', 'spintax-domain-manager'); ?></option>
                <option value="sync_cloudflare"><?php esc_html_e('Sync with CloudFlare', 'spintax-domain-manager'); ?></option>
            </select>
                <button class="button button-primary sdm-mass-action-apply-site" data-site-id="<?php echo esc_attr($site->id); ?>">
                    <?php esc_html_e('Apply', 'spintax-domain-manager'); ?>
                </button>
            </div>
        <?php endforeach;
    else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e('No sites found for this project.', 'spintax-domain-manager'); ?></p>
    <?php endif;
    $html = ob_get_clean();
    wp_send_json_success( array( 'html' => $html ) );
}
add_action( 'wp_ajax_sdm_fetch_redirects_list', 'sdm_ajax_fetch_redirects_list' );

/**
 * Returns inline SVG markup for the given redirect type.
 */
function sdm_get_inline_redirect_svg( $redirect_type ) {
    $filename = 'main.svg';
    if ( $redirect_type === 'glue' ) {
        $filename = 'glue.svg';
    } elseif ( $redirect_type === 'hidden' ) {
        $filename = 'hidden.svg';
    }
    $svg_file = SDM_PLUGIN_DIR . 'assets/icons/' . $filename;
    if ( ! file_exists( $svg_file ) ) {
        return ''; 
    }
    $svg_content = file_get_contents( $svg_file );
    $title = '<title>' . esc_html( $redirect_type ) . '</title>';
    $svg_content = preg_replace('/(<svg[^>]*>)/i', '$1' . $title, $svg_content, 1);
    return $svg_content;
}

/**
 * AJAX Handler: Mass Sync Redirects to CloudFlare for selected domains.
 */
function sdm_ajax_mass_sync_redirects_to_cloudflare() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $domain_ids = isset($_POST['domain_ids']) ? json_decode(stripslashes($_POST['domain_ids']), true) : [];
    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;

    if (empty($domain_ids) || $project_id <= 0) {
        wp_send_json_error(__('No domains selected or invalid project ID.', 'spintax-domain-manager'));
    }

    $manager = new SDM_Redirects_Manager();
    $result = $manager->sync_selected_redirects_to_cloudflare($project_id, $domain_ids);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(['message' => $result]);
}
add_action('wp_ajax_sdm_mass_sync_redirects_to_cloudflare', 'sdm_ajax_mass_sync_redirects_to_cloudflare');
?>
