<?php
/**
 * File: includes/managers/class-sdm-accounts-manager.php
 * Description: Manager for external service accounts CRUD operations (new approach with service_id).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Accounts_Manager {

    /**
     * Retrieve all accounts from the database, 
     * including project_name and service_name via LEFT JOIN.
     */
    public function get_all_accounts() {
        global $wpdb;
        $acc_table  = $wpdb->prefix . 'sdm_accounts';
        $proj_table = $wpdb->prefix . 'sdm_projects';
        $serv_table = $wpdb->prefix . 'sdm_service_types';

        // LEFT JOIN to get project_name and service_name
        $sql = "
            SELECT 
                a.*, 
                p.project_name AS project_name,
                st.service_name AS service
            FROM {$acc_table} a
            LEFT JOIN {$proj_table} p ON p.id = a.project_id
            LEFT JOIN {$serv_table} st ON st.id = a.service_id
            ORDER BY a.created_at DESC
        ";
        return $wpdb->get_results($sql);
    }

    /**
     * Add a new account (with encryption).
     */
    public function add_account( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';

        $project_id = isset( $data['project_id'] ) ? absint( $data['project_id'] ) : 0;
        $site_id    = isset( $data['site_id'] ) ? absint( $data['site_id'] ) : null;

        // Get service_id by service_name from sdm_service_types
        $service_name = isset( $data['service'] ) ? sanitize_text_field( $data['service'] ) : '';
        $service_id   = $this->get_service_id_by_name( $service_name );
        if ( is_wp_error( $service_id ) ) {
            return $service_id; // e.g. "Service not found"
        }

        $account_name = sanitize_text_field( $data['account_name'] );
        $email        = sanitize_email( $data['email'] );

        // Encrypt fields if not empty
        $api_key_enc         = ! empty($data['api_key_enc'])         ? sdm_encrypt($data['api_key_enc'])         : '';
        $client_id_enc       = ! empty($data['client_id_enc'])       ? sdm_encrypt($data['client_id_enc'])       : '';
        $client_secret_enc   = ! empty($data['client_secret_enc'])   ? sdm_encrypt($data['client_secret_enc'])   : '';
        $refresh_token_enc   = ! empty($data['refresh_token_enc'])   ? sdm_encrypt($data['refresh_token_enc'])   : '';
        $additional_data_enc = ! empty($data['additional_data_enc']) ? sdm_encrypt($data['additional_data_enc']) : '';

        $res = $wpdb->insert(
            $table,
            array(
                'project_id'          => $project_id,
                'site_id'             => $site_id,
                'service_id'          => $service_id, // store ID, not name
                'account_name'        => $account_name,
                'email'               => $email,
                'api_key_enc'         => $api_key_enc,
                'client_id_enc'       => $client_id_enc,
                'client_secret_enc'   => $client_secret_enc,
                'refresh_token_enc'   => $refresh_token_enc,
                'additional_data_enc' => $additional_data_enc,
                'created_at'          => current_time( 'mysql' ),
                'updated_at'          => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $res ) {
            return new WP_Error( 'db_insert_error', __( 'Could not insert account.', 'spintax-domain-manager' ) );
        }
        return $wpdb->insert_id;
    }

    /**
     * Update an existing account by ID (with encryption).
     */
    public function update_account( $account_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';

        $account_id = absint( $account_id );
        if ( $account_id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid account ID.', 'spintax-domain-manager' ) );
        }

        // Get old record to preserve old encrypted fields if user leaves them empty
        $old_record = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table WHERE id=%d", $account_id) );
        if ( ! $old_record ) {
            return new WP_Error( 'not_found', __( 'Account not found.', 'spintax-domain-manager' ) );
        }

        // Convert service_name to service_id
        $service_name = isset( $data['service'] ) ? sanitize_text_field( $data['service'] ) : '';
        $service_id   = $this->get_service_id_by_name( $service_name );
        if ( is_wp_error( $service_id ) ) {
            // if service not found, fallback or return error
            return $service_id;
        }

        $account_name = sanitize_text_field( $data['account_name'] );
        $email        = sanitize_email( $data['email'] );

        // If user provided new values, encrypt them, else keep old
        if ( isset($data['api_key_enc']) && $data['api_key_enc'] !== '' ) {
            $api_key_enc = sdm_encrypt( $data['api_key_enc'] );
        } else {
            $api_key_enc = $old_record->api_key_enc;
        }

        if ( isset($data['client_id_enc']) && $data['client_id_enc'] !== '' ) {
            $client_id_enc = sdm_encrypt( $data['client_id_enc'] );
        } else {
            $client_id_enc = $old_record->client_id_enc;
        }

        if ( isset($data['client_secret_enc']) && $data['client_secret_enc'] !== '' ) {
            $client_secret_enc = sdm_encrypt( $data['client_secret_enc'] );
        } else {
            $client_secret_enc = $old_record->client_secret_enc;
        }

        if ( isset($data['refresh_token_enc']) && $data['refresh_token_enc'] !== '' ) {
            $refresh_token_enc = sdm_encrypt( $data['refresh_token_enc'] );
        } else {
            $refresh_token_enc = $old_record->refresh_token_enc;
        }

        if ( isset($data['additional_data_enc']) && $data['additional_data_enc'] !== '' ) {
            $additional_data_enc = sdm_encrypt( $data['additional_data_enc'] );
        } else {
            $additional_data_enc = $old_record->additional_data_enc;
        }

        $updated = $wpdb->update(
            $table,
            array(
                'service_id'          => $service_id,
                'account_name'        => $account_name,
                'email'               => $email,
                'api_key_enc'         => $api_key_enc,
                'client_id_enc'       => $client_id_enc,
                'client_secret_enc'   => $client_secret_enc,
                'refresh_token_enc'   => $refresh_token_enc,
                'additional_data_enc' => $additional_data_enc,
                'updated_at'          => current_time( 'mysql' )
            ),
            array( 'id' => $account_id ),
            array( '%d','%s','%s','%s','%s','%s','%s','%s','%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_error', __( 'Could not update account.', 'spintax-domain-manager' ) );
        }
        return true;
    }

    /**
     * Delete an account by ID.
     */
    public function delete_account( $account_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';
        $account_id = absint( $account_id );
        if ( $account_id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid account ID.', 'spintax-domain-manager' ) );
        }
        $deleted = $wpdb->delete( $table, array( 'id' => $account_id ), array( '%d' ) );
        if ( false === $deleted ) {
            return new WP_Error( 'db_delete_error', __( 'Could not delete account.', 'spintax-domain-manager' ) );
        }
        return true;
    }

    /**
     * Helper: Get service_id by service_name from sdm_service_types.
     */
    private function get_service_id_by_name( $service_name ) {
        global $wpdb;
        if ( empty( $service_name ) ) {
            return new WP_Error( 'invalid_service', __( 'Service name is empty.', 'spintax-domain-manager' ) );
        }

        $table = $wpdb->prefix . 'sdm_service_types';
        $service_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table WHERE service_name = %s", $service_name)
        );

        if ( empty( $service_id ) ) {
            return new WP_Error(
                'service_not_found',
                sprintf( __( 'Service "%s" not found in service types table.', 'spintax-domain-manager' ), $service_name )
            );
        }
        return intval( $service_id );
    }
}

/**
 * Ajax: Add Account
 * Action: wp_ajax_sdm_add_account
 */
function sdm_ajax_add_account() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $manager = new SDM_Accounts_Manager();
    $result  = $manager->add_account( $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'account_id' => $result ) );
}
add_action( 'wp_ajax_sdm_add_account', 'sdm_ajax_add_account' );

/**
 * Ajax: Update Account
 * Action: wp_ajax_sdm_update_account
 */
function sdm_ajax_update_account() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;

    $manager = new SDM_Accounts_Manager();
    $result  = $manager->update_account( $account_id, $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Account updated successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_update_account', 'sdm_ajax_update_account' );

/**
 * Ajax: Delete Account
 * Action: wp_ajax_sdm_delete_account
 */
function sdm_ajax_delete_account() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $account_id = isset( $_POST['account_id'] ) ? absint( $_POST['account_id'] ) : 0;
    $manager = new SDM_Accounts_Manager();
    $result = $manager->delete_account( $account_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Account deleted successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_delete_account', 'sdm_ajax_delete_account' );
