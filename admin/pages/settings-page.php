<?php
/**
 * File: admin/pages/settings-page.php
 * Description: Settings page for Spintax Domain Manager.
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap">
    <h1><?php _e('Spintax Domain Manager Settings', 'spintax-domain-manager'); ?></h1>
    
    <?php 
    // Выводим уведомления WordPress
    settings_errors('sdm_settings_group');
    ?>

    <form method="post" action="options.php" id="sdm-settings-form">
        <?php
        settings_fields('sdm_settings_group');
        do_settings_sections('sdm_settings');
        submit_button();
        ?>
    </form>

    <style>
        .notice {
            padding: 12px 15px;
            margin: 15px 0;
            border-left: 4px solid var(--notice-blue);
            background-color: var(--color-light-bg);
            box-shadow: 0 1px 1px rgba(0,0,0,0.05);
        }
        .notice-error {
            border-left-color: var(--color-red);
            background-color: var(--notice-error-bg);
        }
        .notice-success {
            border-left-color: var(--color-green);
            background-color: var(--notice-success-bg);
        }
        .notice-dismiss {
            padding: 5px 10px;
            font-size: 16px;
            line-height: 1;
            background: transparent;
            border: none;
            cursor: pointer;
        }
        .is-dismissible .notice-dismiss:before {
            content: '\f153';
            font: 400 16px/1 dashicons;
            speak: never;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
    </style>
</div>

<?php
// Callback для поля GraphQL
function sdm_enable_graphql_field_callback() {
    $value = get_option('sdm_enable_graphql', 0);
    echo '<input type="checkbox" name="sdm_enable_graphql" value="1" ' . checked(1, $value, false) . ' />';
    echo '<p class="description">' . __('Enable GraphQL API for retrieving project data.', 'spintax-domain-manager') . '</p>';
    echo '<p>' . __('Use the following GraphQL query to retrieve main domains and languages for a project:', 'spintax-domain-manager') . '</p>';
    echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius);">';
    echo esc_html(
        "query {\n" .
        "  projectLanguageDomains(projectId: 1) {\n" .
        "    mainDomain\n" .
        "    language\n" .
        "  }\n" .
        "}"
    );
    echo '</pre>';
    echo '<p>' . __('Example response:', 'spintax-domain-manager') . '</p>';
    echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius);">';
    echo esc_html(
        "{\n" .
        "  \"data\": {\n" .
        "    \"projectLanguageDomains\": [\n" .
        "      {\"mainDomain\": \"example.com\", \"language\": \"en\"},\n" .
        "      {\"mainDomain\": \"example.ru\", \"language\": \"ru-RU\"}\n" .
        "    ]\n" .
        "  }\n" .
        "}"
    );
    echo '</pre>';
}