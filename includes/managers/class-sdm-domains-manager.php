<?php

if ( ! defined('ABSPATH') ) {
    exit;
}

class SDM_Domains_Manager {
    public function count_domains() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sdm_domains';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    }
}
