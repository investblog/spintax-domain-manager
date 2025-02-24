<?php
/**
 * File: admin/pages/redirects-page.php
 * Description: Displays the Redirects interface for a selected project, with mass actions, grouping by sites, and CloudFlare sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Получаем список проектов
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Текущий проект (через GET)
$current_project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;

// Генерируем nonce
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e('Redirects', 'spintax-domain-manager'); ?></h1>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr($main_nonce); ?>">

    <!-- Notice container -->
    <div id="sdm-redirects-notice" class="sdm-notice"></div>

    <!-- Project Selector, Sync Button, and Message Area -->
    <form method="get" action="" class="sdm-project-form">
        <input type="hidden" name="page" value="sdm-redirects">
        <label for="sdm-project-selector" class="sdm-label"><?php esc_html_e('Select Project:', 'spintax-domain-manager'); ?></label>
        <select id="sdm-project-selector" name="project_id" onchange="this.form.submit()" class="sdm-select">
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
        <?php if ($current_project_id > 0) : ?>
            <button type="button" id="sdm-sync-cloudflare" class="button button-primary sdm-action-button" style="margin-left: 10px;">
                <?php esc_html_e('Sync with CloudFlare', 'spintax-domain-manager'); ?>
            </button>
            <span id="sdm-cloudflare-message" class="sdm-status"></span>
        <?php endif; ?>
    </form>

    <!-- Project Indicator -->
    <?php if ($current_project_id > 0) : ?>
        <p class="sdm-project-indicator" style="margin: 10px 0 20px; font-size: 14px; color: #666;">
            <?php 
            $project_name = '';
            foreach ($all_projects as $proj) {
                if ($proj->id == $current_project_id) {
                    $project_name = $proj->project_name;
                    break;
                }
            }
            echo sprintf(__('Viewing redirects for project: %d - %s', 'spintax-domain-manager'), 
                $current_project_id, 
                esc_html($project_name ?: 'Unknown')); 
            ?>
        </p>
    <?php else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e('Please select a project to view its redirects.', 'spintax-domain-manager'); ?></p>
    <?php endif; ?>

    <!-- Container for AJAX-loaded content -->
    <div id="sdm-redirects-container">
        <?php if ($current_project_id > 0) : ?>
            <!-- Скрипт удалён, инициализация теперь в JavaScript -->
        <?php endif; ?>
    </div>
</div>