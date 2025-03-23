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
?>