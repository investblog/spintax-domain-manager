<?php
/**
 * File: includes/graphql-support.php
 * Description: Registers GraphQL fields for Spintax Domain Manager.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Функция для нормализации кода языка в формат BCP 47
function sdm_normalize_language_code_bcp47($language) {
    if (empty($language)) {
        return 'en'; // Значение по умолчанию
    }

    // Удаляем лишние пробелы и приводим к нижнему регистру для обработки
    $language = trim(strtolower($language));

    // Заменяем подчёркивание на дефис
    $language = str_replace('_', '-', $language);

    // Разделяем на язык и регион (если есть)
    $parts = explode('-', $language);

    // Обрабатываем код языка (первые 2 символа)
    $lang_code = isset($parts[0]) ? substr($parts[0], 0, 2) : 'en';
    $lang_code = strtolower($lang_code); // Язык в нижнем регистре

    // Обрабатываем регион (если указан)
    if (isset($parts[1])) {
        $region_code = strtoupper(substr($parts[1], 0, 2)); // Регион в верхнем регистре
        return "$lang_code-$region_code"; // Формат: ru-RU
    }

    return $lang_code; // Формат: ru
}

// Register GraphQL support for project language domains
function sdm_register_graphql_fields() {
    // Проверяем наличие WPGraphQL
    if (!class_exists('WPGraphQL')) {
        return; // Выходим, если WPGraphQL не установлен
    }

    // Проверяем, включена ли поддержка GraphQL в настройках
    if (!get_option('sdm_enable_graphql', 0)) {
        return; // Выходим, если поддержка не включена
    }

    // Регистрируем новый тип для возвращаемых данных
    register_graphql_object_type('ProjectSiteLanguage', [
        'description' => __('A site with its main domain and language for a project.', 'spintax-domain-manager'),
        'fields' => [
            'mainDomain' => [
                'type' => 'String',
                'description' => __('Main domain of the site.', 'spintax-domain-manager'),
            ],
            'language' => [
                'type' => 'String',
                'description' => __('Language code of the site in BCP 47 format (e.g., "en", "ru-RU").', 'spintax-domain-manager'),
            ],
        ],
    ]);

    // Регистрируем поле в RootQuery
    register_graphql_field('RootQuery', 'projectLanguageDomains', [
        'type' => ['list_of' => 'ProjectSiteLanguage'],
        'description' => __('List of sites with their main domains and languages for a project.', 'spintax-domain-manager'),
        'args' => [
            'projectId' => [
                'type' => 'Int',
                'description' => __('ID of the project.', 'spintax-domain-manager'),
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;

            if (!isset($args['projectId']) || $args['projectId'] <= 0) {
                return null;
            }

            $project_id = absint($args['projectId']);

            // Получаем все сайты проекта с их main_domain и language
            $sites = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT main_domain, language 
                     FROM {$wpdb->prefix}sdm_sites 
                     WHERE project_id = %d",
                    $project_id
                ),
                ARRAY_A
            );

            if (empty($sites)) {
                return [];
            }

            // Форматируем результат для GraphQL
            $result = [];
            foreach ($sites as $site) {
                $normalized_language = sdm_normalize_language_code_bcp47($site['language']);
                $result[] = [
                    'mainDomain' => $site['main_domain'],
                    'language' => $normalized_language,
                ];
            }

            return $result;
        },
    ]);
}
add_action('graphql_register_types', 'sdm_register_graphql_fields');