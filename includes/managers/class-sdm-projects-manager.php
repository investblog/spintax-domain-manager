<?php
// File: includes/managers/class-sdm-projects-manager.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Projects_Manager {
    public function count_projects() {
        // Here you do a simple count of rows in sdm_projects table, for example:
        global $wpdb;
        $table_name = $wpdb->prefix . 'sdm_projects';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    }
}
