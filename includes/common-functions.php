<?php
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
        'pl' => 'pl',
    );
    return isset( $mappings[$normalized] ) ? $mappings[$normalized] : $normalized;
}

/**
 * Get active project ID for current user.
 */
function sdm_get_active_project_id() {
    $id = get_user_meta( get_current_user_id(), 'sdm_active_project_id', true );
    return $id ? absint( $id ) : 0;
}

/**
 * Set active project ID for current user.
 *
 * @param int $project_id Project ID.
 */
function sdm_set_active_project_id( $project_id ) {
    update_user_meta( get_current_user_id(), 'sdm_active_project_id', absint( $project_id ) );
}

/**
 * Render navigation links for current project pages.
 *
 * @param int $project_id Current project ID.
 */
function sdm_render_project_nav( $project_id, $current_page = '' ) {
    $params = $project_id > 0 ? '&project_id=' . absint( $project_id ) : '';

    $pages = array(
        'sdm-sites'     => __( 'Sites', 'spintax-domain-manager' ),
        'sdm-domains'   => __( 'Domains', 'spintax-domain-manager' ),
        'sdm-redirects' => __( 'Redirects', 'spintax-domain-manager' ),
    );

    echo '<h1 class="sdm-project-nav" style="margin:10px 0;">';
    $links = array();
    foreach ( $pages as $slug => $label ) {
        if ( $slug === $current_page ) {
            $links[] = esc_html( $label );
        } else {
            $links[] = '<a href="admin.php?page=' . esc_attr( $slug ) . esc_attr( $params ) . '">' . esc_html( $label ) . '</a>';
        }
    }
    echo implode( ' | ', $links );
    echo '</h1>';
}

if ( ! function_exists( 'sdm_get_server_ip' ) ) {
    /**
     * Возвращает IP-адрес текущего сервера.
     *
     * Функция сначала проверяет $_SERVER['SERVER_ADDR'], затем пытается получить IP через gethostname()
     * и, если необходимо, через php_uname('n'). Если ни один метод не дал корректного IP,
     * возвращается '127.0.0.1' как значение по умолчанию.
     *
     * @return string IP-адрес сервера.
     */
    function sdm_get_server_ip() {
        // 1. Пытаемся получить IP из $_SERVER
        if ( ! empty( $_SERVER['SERVER_ADDR'] ) && filter_var( $_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP ) ) {
            return $_SERVER['SERVER_ADDR'];
        }
        
        // 2. Попытка через gethostname()
        $host = gethostname();
        if ( $host ) {
            $ip = gethostbyname( $host );
            if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) && $ip !== '127.0.0.1' ) {
                return $ip;
            }
        }
        
        // 3. Ещё один способ через php_uname('n')
        $hostname = php_uname('n');
        if ( $hostname ) {
            $ip = gethostbyname( $hostname );
            if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) && $ip !== '127.0.0.1' ) {
                return $ip;
            }
        }
        
        // 4. Если ничего не удалось, возвращаем fallback
        return '127.0.0.1';
    }
}

/**
 * Determine if HostTracker task state indicates a block.
 *
 * @param mixed $state Value of the `lastState` field from HostTracker.
 * @return bool True if considered blocked, false otherwise.
 */
function sdm_hosttracker_state_is_blocked( $state ) {
    if ( is_bool( $state ) ) {
        return ! $state; // true => up, false => down
    }

    if ( is_numeric( $state ) ) {
        return (int) $state === 0; // 0 => down
    }

    if ( is_string( $state ) ) {
        $state_normal = strtolower( trim( $state ) );
        $up_values   = array( 'up', 'ok', 'true', 'success', 'available' );
        $down_values = array( 'down', 'blocked', 'false', 'fail', 'failed', 'error' );

        if ( in_array( $state_normal, $up_values, true ) ) {
            return false;
        }

        if ( in_array( $state_normal, $down_values, true ) ) {
            return true;
        }
    }

    // Unknown state - treat as not blocked
    return false;
}
?>
