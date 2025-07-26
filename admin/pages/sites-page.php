<?php
/**
 * File: admin/pages/sites-page.php
 * Description: Displays the Sites interface with a list of sites for a selected project and a form to add a new site.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Определяем, включён ли мониторинг по дефолту для существующих сайтов (это значение используется при редактировании).
// Для новых сайтов управление мониторингом убрано из формы, и мониторинг будет создан в выключенном состоянии.
$site_monitoring_enabled = true;

// Получаем список проектов для селектора
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Определяем текущий выбранный проект
$current_project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : sdm_get_active_project_id();
if ($current_project_id > 0) {
    sdm_set_active_project_id($current_project_id);
}

// Если проект выбран, получаем сайты этого проекта
$sites = array();
if ($current_project_id > 0) {
    $sites = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$prefix}sdm_sites WHERE project_id = %d ORDER BY created_at DESC",
            $current_project_id
        )
    );
}

// Получаем список свободных доменов проекта для автодополнения (неназначенные, активные, незаблокированные)
$non_blocked_domains = array();
if ($current_project_id > 0) {
    $non_blocked_domains = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT domain FROM {$prefix}sdm_domains 
             WHERE project_id = %d 
               AND site_id IS NULL 
               AND status = 'active' 
               AND is_blocked_provider = 0 
               AND is_blocked_government = 0",
            $current_project_id
        ),
        ARRAY_A
    );
}

// Генерируем nonce
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e('Sites', 'spintax-domain-manager'); ?></h1>
    <?php sdm_render_project_nav($current_project_id); ?>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr($main_nonce); ?>">

    <!-- Notice container for sites -->
    <div id="sdm-sites-notice" class="sdm-notice"></div>

    <!-- Project Selector -->
    <form method="get" action="">
        <input type="hidden" name="page" value="sdm-sites">
        <label for="sdm-project-selector"><?php esc_html_e('Select Project:', 'spintax-domain-manager'); ?></label>
        <select id="sdm-project-selector" name="project_id" onchange="this.form.submit()">
            <option value="0"><?php esc_html_e('— Select —', 'spintax-domain-manager'); ?></option>
            <?php if (!empty($all_projects)) : ?>
                <?php foreach ($all_projects as $proj) : ?>
                    <option value="<?php echo esc_attr($proj->id); ?>"
                        <?php selected($proj->id, $current_project_id); ?>>
                        <?php echo sprintf('%d - %s', $proj->id, $proj->project_name); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </form>

    <?php if ($current_project_id === 0) : ?>
        <p style="margin-top:20px;"><?php esc_html_e('Please select a project to view its sites.', 'spintax-domain-manager'); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <!-- Sites Table -->
    <table id="sdm-sites-table" class="wp-list-table widefat fixed striped sdm-table" style="margin-top:20px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Icon', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Site Name', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Main Domain', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Server IP', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Language', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Monitoring', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Created At', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Updated At', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Actions', 'spintax-domain-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($sites)) : ?>
                <?php foreach ($sites as $site) : ?>
                    <?php
                    $monitoring_settings = json_decode($site->monitoring_settings, true);
                    $rusregbl_enabled = $monitoring_settings && isset($monitoring_settings['types']['RusRegBL']) ? $monitoring_settings['types']['RusRegBL'] : false;
                    $http_enabled = $monitoring_settings && isset($monitoring_settings['types']['Http']) ? $monitoring_settings['types']['Http'] : false;
                    ?>
                    <tr id="site-row-<?php echo esc_attr($site->id); ?>" data-site-id="<?php echo esc_attr($site->id); ?>" data-update-nonce="<?php echo esc_attr($main_nonce); ?>">
                        <td class="column-icon">
                            <span class="fi fi-<?php echo esc_attr(sdm_normalize_language_code($site->language ?: 'en')); ?>" style="vertical-align: middle;"></span>
                        </td>
                        <td class="column-site-name">
                            <span class="sdm-display-value"><?php echo esc_html($site->site_name); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="site_name" value="<?php echo esc_attr($site->site_name); ?>">
                        </td>
                        <td class="column-main-domain">
                            <span class="sdm-display-value"><?php echo esc_html($site->main_domain); ?></span>
                            <select class="sdm-edit-input sdm-hidden" name="main_domain" data-current="<?php echo esc_attr($site->main_domain); ?>">
                                <option value=""><?php esc_html_e('Select a domain', 'spintax-domain-manager'); ?></option>
                                <?php if (!empty($non_blocked_domains)) : ?>
                                    <?php foreach ($non_blocked_domains as $row) : ?>
                                        <option value="<?php echo esc_attr($row['domain']); ?>"
                                            <?php selected($row['domain'], $site->main_domain); ?>>
                                            <?php echo esc_html($row['domain']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td class="column-server-ip">
                            <span class="sdm-display-value"><?php echo esc_html($site->server_ip ?: '(Not set)'); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="server_ip" value="<?php echo esc_attr($site->server_ip ?: ''); ?>">
                        </td>
                        <td class="column-language">
                            <span class="sdm-display-value"><?php echo esc_html($site->language ?: '(Not set)'); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="language" value="<?php echo esc_attr($site->language ?: ''); ?>" placeholder="EN_en">
                        </td>
                        <td class="column-monitoring">
                            <span class="sdm-display-value">
                                <?php echo $rusregbl_enabled ? 'RusRegBL ' : ''; ?>
                                <?php echo $http_enabled ? 'Http ' : ''; ?>
                                <?php echo (!$rusregbl_enabled && !$http_enabled) ? 'None' : ''; ?>
                            </span>
                            <div class="sdm-edit-input sdm-hidden">
                                <label>
                                    <input type="checkbox" name="monitoring[enabled]" value="1" <?php checked($site_monitoring_enabled); ?>>
                                    <?php esc_html_e('Enable Monitoring', 'spintax-domain-manager'); ?>
                                </label><br>
                                <label><input type="checkbox" name="monitoring[types][RusRegBL]" value="1" <?php checked($rusregbl_enabled); ?>> RusRegBL</label><br>
                                <label><input type="checkbox" name="monitoring[types][Http]" value="1" <?php checked($http_enabled); ?>> Http</label>
                            </div>
                        </td>
                        <td><?php echo esc_html($site->created_at); ?></td>
                        <td><?php echo esc_html($site->updated_at); ?></td>

                        <td class="column-actions">
                            <!-- Обёртка для выпадающего меню -->
                            <div class="sdm-actions-menu">
                                <!-- Кнопка-триггер (иконка «троеточие» или любая другая) -->
                                <button type="button" class="sdm-actions-trigger button">
                                    <span class="dashicons dashicons-ellipsis"></span>
                                </button>

                                <!-- Скрытое выпадающее меню -->
                                <div class="sdm-actions-dropdown" style="display: none;">
                                    <!-- Здесь те же ссылки, что раньше были в одной строке, 
                                         но со старыми классами, чтобы ваш JS продолжал работать -->
                                    <a href="#" class="sdm-edit-site sdm-edit"><?php esc_html_e('Edit', 'spintax-domain-manager'); ?></a>
                                    <a href="#" class="sdm-save-site sdm-save sdm-hidden"><?php esc_html_e('Save', 'spintax-domain-manager'); ?></a>
                                    <hr>
                                    <a href="#" class="sdm-delete-site sdm-delete"><?php esc_html_e('Delete', 'spintax-domain-manager'); ?></a>
                                    <hr>
                                    <!-- Если у вас была отдельная кнопка для Яндекса, перенесите её сюда -->
                                    <a href="#" class="sdm-yandex-webmaster"><?php esc_html_e('Add to Yandex', 'spintax-domain-manager'); ?></a>
                                </div>
                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-sites">
                    <td colspan="9"><?php esc_html_e('No sites found for this project.', 'spintax-domain-manager'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>


    <!-- Form for Adding a New Site -->
    <h2><?php esc_html_e('Add New Site', 'spintax-domain-manager'); ?></h2>
    <form id="sdm-add-site-form" class="sdm-form" method="post" action="">
        <?php sdm_nonce_field(); ?>
        <input type="hidden" name="project_id" value="<?php echo esc_attr($current_project_id); ?>">
        <table class="sdm-form-table">
            <tr>
                <th><label for="site_name"><?php esc_html_e('Site Name', 'spintax-domain-manager'); ?></label></th>
                <td><input type="text" name="site_name" id="site_name" required></td>
            </tr>
            <tr>
                <th><label for="server_ip"><?php esc_html_e('Server IP (optional)', 'spintax-domain-manager'); ?></label></th>
                <td><input type="text" name="server_ip" id="server_ip" value="<?php echo esc_attr( sdm_get_server_ip() ); ?>"></td>
            </tr>

            <tr>
                <th><label for="main_domain"><?php esc_html_e('Main Domain', 'spintax-domain-manager'); ?></label></th>
                <td>
                    <select name="main_domain" id="main_domain" required>
                        <option value=""><?php esc_html_e('Select a domain', 'spintax-domain-manager'); ?></option>
                        <?php if (!empty($non_blocked_domains)) : ?>
                            <?php foreach ($non_blocked_domains as $row) : ?>
                                <option value="<?php echo esc_attr($row['domain']); ?>">
                                    <?php echo esc_html($row['domain']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="language"><?php esc_html_e('Language', 'spintax-domain-manager'); ?></label></th>
                <td><input type="text" name="language" id="language" placeholder="EN_en" required></td>
            </tr>
            <!-- Поля мониторинга удалены. При добавлении нового сайта мониторинг будет создан в выключенном состоянии.
                 Если пользователь захочет включить мониторинг, он сможет настроить его при редактировании сайта. -->
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Add Site', 'spintax-domain-manager'); ?></button>
        </p>
    </form>
</div>
