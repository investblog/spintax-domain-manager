<?php
/**
 * File: admin/pages/domains-page.php
 * Description: Displays the Domains interface for a selected project, with mass actions, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Получаем список проектов
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Получаем список сервисов
$service_manager = new SDM_Service_Types_Manager();
$services = $service_manager->get_all_services();

// Получаем список сайтов для текущего проекта
$current_project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;
$sites = [];
if ($current_project_id > 0) {
    $sites = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_sites WHERE project_id = %d", $current_project_id)
    );
}

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
        <div class="sdm-project-indicator" style="margin: 10px 0 20px; font-size: 14px; color: #666;">
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
            <div id="sdm-batch-progress" class="sdm-progress" style="display:none; margin-top:5px;">
                <div class="sdm-progress-bar"></div>
            </div>
        </div>
    <?php else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e('Please select a project to view its domains.', 'spintax-domain-manager'); ?></p>
    <?php endif; ?>

    <!-- Full-width domains table -->
    <div style="width: 100%;">
        <!-- Domains Table (loaded via AJAX for dynamic updates) -->
        <div id="sdm-domains-container">
            <?php if ($current_project_id > 0) : ?>
                <!-- Table is initialized via JS (fetchDomains) -->
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

    <!-- Modal for Assigning Domains to Site or Setting Abuse/Block Status -->
    <div id="sdm-assign-to-site-modal" class="sdm-modal">
        <div class="sdm-modal-content">
            <span class="sdm-modal-close" id="sdm-close-assign-modal">×</span>
            <h2 id="sdm-modal-action-title"><?php esc_html_e('Mass Action', 'spintax-domain-manager'); ?></h2>
            <p id="sdm-modal-instruction"><?php esc_html_e('Configure the mass action:', 'spintax-domain-manager'); ?></p>
            <div class="sdm-form-field">
                <label for="sdm-assign-site-select"><?php esc_html_e('Site:', 'spintax-domain-manager'); ?></label>
                <select id="sdm-assign-site-select" class="sdm-select">
                    <option value=""><?php esc_html_e('— Select a site —', 'spintax-domain-manager'); ?></option>
                    <?php foreach ($sites as $site) : ?>
                        <option value="<?php echo esc_attr($site->id); ?>">
                            <?php echo esc_html($site->site_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sdm-form-field" id="sdm-mass-action-options" style="display: none;"></div>
            <h4><?php esc_html_e('Selected Domains:', 'spintax-domain-manager'); ?></h4>
            <ul id="sdm-selected-domains-list"></ul>
            <p class="submit">
                <button id="sdm-assign-confirm" class="button button-primary sdm-action-button" data-default-text="<?php esc_attr_e('Confirm', 'spintax-domain-manager'); ?>">
                    <?php esc_html_e('Confirm', 'spintax-domain-manager'); ?>
                </button>
                <button id="sdm-assign-cancel" class="button sdm-action-button"><?php esc_html_e('Cancel', 'spintax-domain-manager'); ?></button>
            </p>
        </div>
    </div>

    <!-- Modal for Email Forwarding Setup -->
    <div id="sdm-email-forwarding-modal" class="sdm-modal">
        <div class="sdm-modal-content">
            <span class="sdm-modal-close" id="sdm-close-email-modal">×</span>
            <h2 id="sdm-email-modal-title"><?php esc_html_e('Set Up Email Forwarding', 'spintax-domain-manager'); ?></h2>

            <!-- Step 1: Create Mail-in-a-Box email -->
            <button id="sdm-email-confirm"
                    class="button button-primary sdm-action-button"
                    style="margin-top:10px;">
                <?php esc_html_e('Create Email (Mail-in-a-Box)', 'spintax-domain-manager'); ?>
            </button>

            <!-- Step 2: Create CF custom address -->
            <button id="sdm-create-cf-address"
                    class="button button-primary sdm-action-button"
                    style="margin-top:10px; display:none;">
                <?php esc_html_e('Create CF Address', 'spintax-domain-manager'); ?>
            </button>

            <!-- Сокращённая инструкция (Instead of Steps 3 & 4) -->
            <div id="sdm-email-instructions" style="margin-top: 20px; display:none;">
                <h3><?php esc_html_e('Almost done!', 'spintax-domain-manager'); ?></h3>
                <p><?php esc_html_e('Please confirm your new email using the verification email from Cloudflare. Then:', 'spintax-domain-manager'); ?></p>
                <ol>
                    <li><?php esc_html_e('Go to your Cloudflare Dashboard.', 'spintax-domain-manager'); ?></li>
                    <li><?php esc_html_e('Select your domain and click "Email Routing".', 'spintax-domain-manager'); ?></li>
                    <li><?php esc_html_e('Click "Enable Email Routing" if prompted and follow the steps to add MX/TXT records.', 'spintax-domain-manager'); ?></li>
                    <li><?php esc_html_e('Toggle Catch-All to "Send" if you wish all@yourdomain to be forwarded.', 'spintax-domain-manager'); ?></li>
                </ol>
                <p style="margin-top: 10px;">
                    <a href="#" target="_blank" id="sdm-cf-routing-link" class="button">
                        <?php esc_html_e('Open Cloudflare Routing Page', 'spintax-domain-manager'); ?>
                    </a>
                </p>
            </div>

            <!-- Блок статуса (ошибки/успех) -->
            <div id="sdm-email-status"
                 class="sdm-notice"
                 style="display:none; margin-top:10px;">
            </div>

            <!-- После создания ящика (Step 1) показываем Email Settings -->
            <div id="sdm-email-settings" style="display: none; margin-top: 20px;">
                <h4><?php esc_html_e('Email Settings:', 'spintax-domain-manager'); ?></h4>
                <table class="sdm-settings-table">
                    <tr>
                        <td><?php esc_html_e('Forwarding Email:', 'spintax-domain-manager'); ?></td>
                        <td>
                            <input type="text"
                                   id="sdm-forwarding-email"
                                   class="sdm-input"
                                   readonly
                                   placeholder="<?php esc_attr_e('Email will appear here', 'spintax-domain-manager'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Mail Server:', 'spintax-domain-manager'); ?></td>
                        <td id="sdm-email-server">[Dynamic]</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Username:', 'spintax-domain-manager'); ?></td>
                        <td id="sdm-email-username">[Generated]</td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Password:', 'spintax-domain-manager'); ?></td>
                        <td id="sdm-email-password">[Generated]</td>
                    </tr>
                </table>
                <!-- Ссылка на webmail -->
                <p id="sdm-webmail-link" style="margin-top: 10px;"></p>
            </div>
        </div>
    </div>

    <!-- Modal for Editing Accounts -->
    <div id="sdm-edit-modal" class="sdm-modal" data-debug="Modal for editing accounts">
        <div class="sdm-modal-content">
            <span class="sdm-modal-close">×</span>
            <h2><?php esc_html_e('Edit Account', 'spintax-domain-manager'); ?></h2>
            <p class="sdm-edit-note"><?php esc_html_e('This form is for editing an existing account. Required fields are marked with a yellow border.', 'spintax-domain-manager'); ?></p>
            <form id="sdm-edit-account-form" class="sdm-form">
                <?php sdm_nonce_field(); ?>
                <div class="sdm-form-fields">
                    <div class="sdm-form-field">
                        <label for="edit-project_id"><?php esc_html_e('Project', 'spintax-domain-manager'); ?></label>
                        <input type="text" name="project_id" id="edit-project_id" class="sdm-input" readonly>
                    </div>
                    <div class="sdm-form-field">
                        <label for="edit-service"><?php esc_html_e('Service', 'spintax-domain-manager'); ?></label>
                        <select name="service" id="edit-service" class="sdm-select" data-nonce="<?php echo esc_attr($main_nonce); ?>">
                            <?php foreach ($services as $srv) : ?>
                                <option value="<?php echo esc_attr($srv->service_name); ?>" data-params="<?php echo htmlspecialchars(json_encode(json_decode($srv->additional_params, true), JSON_UNESCAPED_SLASHES)); ?>" data-debug="<?php echo esc_attr($srv->additional_params); ?>">
                                    <?php echo esc_html(ucfirst($srv->service_name)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="edit-account-fields"></div>
                    <div class="sdm-form-field">
                        <label for="edit-account_name"><?php esc_html_e('Account Name (optional)', 'spintax-domain-manager'); ?></label>
                        <input type="text" name="account_name" id="edit-account_name" class="sdm-input">
                    </div>
                    <div class="sdm-form-field">
                        <label for="edit-email"><?php esc_html_e('Email (optional)', 'spintax-domain-manager'); ?></label>
                        <input type="email" name="email" id="edit-email" class="sdm-input">
                    </div>
                </div>
                <p class="submit">
                    <button type="submit" class="button button-primary sdm-action-button"><?php esc_html_e('Save Changes', 'spintax-domain-manager'); ?></button>
                    <button type="button" class="button sdm-action-button sdm-modal-close"><?php esc_html_e('Cancel', 'spintax-domain-manager'); ?></button>
                </p>
            </form>
        </div>
    </div>
</div>
