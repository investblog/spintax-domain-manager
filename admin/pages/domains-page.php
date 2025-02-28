<?php
/**
 * File: admin/pages/domains-page.php
 * Description: Displays the Domains interface for a selected project, with mass actions, individual domain actions, and column sorting, styled like redirects.
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
    <h1><?php esc_html_e('Domains', 'spintax-domain-manager'); ?></h1>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr($main_nonce); ?>">

    <!-- Notice container -->
    <div id="sdm-domains-notice" class="sdm-notice"></div>

    <!-- Project Selector and Fetch Button -->
    <form method="get" action="" class="sdm-project-form">
        <input type="hidden" name="page" value="sdm-domains">
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
            <button type="button" id="sdm-fetch-domains" class="button button-primary sdm-fetch-button" style="margin-left: 10px;">
                <?php esc_html_e('Fetch Project Domains', 'spintax-domain-manager'); ?>
            </button>
            <span id="sdm-fetch-domains-status" class="sdm-status"></span>
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
            echo sprintf(
                __('Viewing domains for project: %d - %s', 'spintax-domain-manager'), 
                $current_project_id, 
                esc_html($project_name ?: 'Unknown')
            ); 
            ?>
        </p>
    <?php else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e('Please select a project to view its domains.', 'spintax-domain-manager'); ?></p>
    <?php endif; ?>

    <!-- Full-width domains table (no left column) -->
    <div style="width: 100%;">
        <!-- Domains Table (loaded via AJAX for dynamic updates) -->
        <div id="sdm-domains-container">
            <?php if ($current_project_id > 0) : ?>
                <!-- Таблица инициализируется через JS (fetchDomains) -->
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal for Mass Adding Domains to CloudFlare -->
    <div id="sdm-mass-add-modal" class="sdm-modal">
        <div class="sdm-modal-overlay"></div>
        <div class="sdm-modal-content">
            <h2><?php esc_html_e('Mass Add Domains to CloudFlare', 'spintax-domain-manager'); ?></h2>
            <p><?php esc_html_e('Enter the domains you want to add, one per line:', 'spintax-domain-manager'); ?></p>
            <textarea id="sdm-mass-add-textarea" rows="6" class="sdm-textarea" placeholder="<?php esc_attr_e('example.com', 'spintax-domain-manager'); ?>"></textarea>
            <div class="sdm-modal-actions" style="margin-top: 20px;">
                <button id="sdm-modal-confirm" class="button button-primary sdm-action-button"><?php esc_html_e('Confirm', 'spintax-domain-manager'); ?></button>
                <button id="sdm-modal-close" class="button sdm-action-button"><?php esc_html_e('Cancel', 'spintax-domain-manager'); ?></button>
            </div>
        </div>
    </div>

    <!-- Modal for Assigning Domains to Site -->
    <div id="sdm-assign-to-site-modal" class="sdm-modal" style="display:none;">
        <div class="sdm-modal-overlay"></div>
        <div class="sdm-modal-content">
            <span id="sdm-close-assign-modal" class="sdm-modal-close">×</span>
            <h2 id="sdm-modal-action-title"><?php esc_html_e('Assign Domains to Site', 'spintax-domain-manager'); ?></h2>
            <p id="sdm-modal-instruction"><?php esc_html_e('Select a site to assign the domains:', 'spintax-domain-manager'); ?></p>
            <ul id="sdm-selected-domains-list" class="sdm-selected-domains"></ul>
            <select id="sdm-assign-site-select" name="site_id" class="sdm-select" required>
                <option value=""><?php esc_html_e('Select a site', 'spintax-domain-manager'); ?></option>
                <?php
                $sites = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, site_name FROM {$prefix}sdm_sites WHERE project_id = %d ORDER BY site_name ASC",
                        $current_project_id
                    )
                );
                if (!empty($sites)) : ?>
                    <?php foreach ($sites as $site) : ?>
                        <option value="<?php echo esc_attr($site->id); ?>">
                            <?php echo esc_html($site->site_name); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <div class="sdm-modal-actions" style="margin-top: 20px;">
                <button id="sdm-assign-confirm" class="button button-primary sdm-action-button"><?php esc_html_e('Assign', 'spintax-domain-manager'); ?></button>
                <button id="sdm-assign-cancel" class="button sdm-action-button"><?php esc_html_e('Cancel', 'spintax-domain-manager'); ?></button>
            </div>
        </div>
    </div>
</div>