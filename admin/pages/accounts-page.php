<?php
/**
 * File: admin/pages/accounts-page.php
 * Description: Displays the Accounts interface with a list of accounts, form-based editing, Ajax deletion, and a form to add new accounts.
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1) Get list of projects
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// 2) Get list of accounts (joined with project_name and service_name)
$accounts_manager = new SDM_Accounts_Manager();
$accounts = $accounts_manager->get_all_accounts();

// 3) Get list of services dynamically
$service_manager = new SDM_Service_Types_Manager();
$services = $service_manager->get_all_services();

$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e('Accounts', 'spintax-domain-manager'); ?></h1>

    <!-- Notice container for accounts -->
    <div id="sdm-accounts-notice" class="sdm-notice"></div>

    <!-- Accounts Table -->
    <table id="sdm-accounts-table" class="wp-list-table widefat striped sdm-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Project ID', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Project Name', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Service', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Account Name', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Email', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Last Tested', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Status', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Created At', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Actions', 'spintax-domain-manager'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($accounts)) : ?>
                <?php foreach ($accounts as $account) : ?>
                    <tr id="account-row-<?php echo esc_attr($account->id); ?>"
                        data-account-id="<?php echo esc_attr($account->id); ?>"
                        data-update-nonce="<?php echo esc_attr($main_nonce); ?>"
                        data-service="<?php echo esc_attr($account->service); ?>">
                        <!-- Project ID (read-only) -->
                        <td class="column-project-id">
                            <?php echo esc_html($account->project_id); ?>
                        </td>

                        <!-- Project Name (read-only) -->
                        <td class="column-project-name">
                            <?php echo !empty($account->project_name)
                                ? esc_html($account->project_name)
                                : esc_html__('(No project)', 'spintax-domain-manager'); ?>
                        </td>

                        <!-- Service (read-only) -->
                        <td class="column-service">
                            <span class="sdm-display-value">
                                <?php echo esc_html($account->service); ?>
                            </span>
                        </td>

                        <!-- Account Name (read-only) -->
                        <td class="column-account-name">
                            <span class="sdm-display-value"><?php echo esc_html($account->account_name); ?></span>
                        </td>

                        <!-- Email (read-only) -->
                        <td class="column-email">
                            <span class="sdm-display-value"><?php echo esc_html($account->email); ?></span>
                        </td>

                        <!-- Last Tested -->
                        <td class="column-last-tested">
                            <?php echo esc_html($account->last_tested_at ? $account->last_tested_at : __('Not tested', 'spintax-domain-manager')); ?>
                        </td>

                        <!-- Status -->
                        <td class="column-status">
                            <?php echo esc_html($account->last_test_result ?: __('N/A', 'spintax-domain-manager')); ?>
                        </td>

                        <td class="column-created"><?php echo esc_html($account->created_at); ?></td>

                        <td class="column-actions">
                            <a href="#" class="sdm-action-button sdm-edit sdm-edit-account">Edit</a> |
                            <a href="#" class="sdm-action-button sdm-delete sdm-delete-account">Delete</a>
                            <a href="#" class="sdm-action-button sdm-test sdm-test-account" data-account-id="<?php echo esc_attr($account->id); ?>">Test</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-accounts">
                    <td colspan="9"><?php esc_html_e('No accounts found.', 'spintax-domain-manager'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr>
    <!-- Add New Account Form -->
    <h2><?php esc_html_e('Add New Account', 'spintax-domain-manager'); ?></h2>

    <?php if (empty($all_projects)) : ?>
        <p style="color: #dc3232;">
            <?php esc_html_e('No projects found. Please create a project first.', 'spintax-domain-manager'); ?>
        </p>
    <?php else : ?>
        <form id="sdm-add-account-form" class="sdm-form">
            <?php sdm_nonce_field(); ?>
            <div class="sdm-form-fields">
                <div class="sdm-form-field">
                    <label for="project_id" class="sdm-label"><?php esc_html_e('Project', 'spintax-domain-manager'); ?></label>
                    <select name="project_id" id="project_id" required class="sdm-select">
                        <?php foreach ($all_projects as $proj) : ?>
                            <option value="<?php echo esc_attr($proj->id); ?>">
                                <?php echo sprintf('%d - %s', $proj->id, $proj->project_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sdm-form-field">
                    <label for="service" class="sdm-label"><?php esc_html_e('Service', 'spintax-domain-manager'); ?></label>
                    <select name="service" id="service" class="sdm-select" data-nonce="<?php echo esc_attr($main_nonce); ?>">
                        <?php foreach ($services as $srv) : ?>
                            <option value="<?php echo esc_attr($srv->service_name); ?>" data-params="<?php echo htmlspecialchars(json_encode(json_decode($srv->additional_params, true), JSON_UNESCAPED_SLASHES)); ?>" data-debug="<?php echo esc_attr($srv->additional_params); ?>">
                                <?php echo esc_html(ucfirst($srv->service_name)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="dynamic-fields"></div>
                <div class="sdm-form-field">
                    <label for="account_name" class="sdm-label"><?php esc_html_e('Account Name (optional)', 'spintax-domain-manager'); ?></label>
                    <input type="text" name="account_name" id="account_name" class="sdm-input">
                </div>
                <div class="sdm-form-field">
                    <label for="email" class="sdm-label"><?php esc_html_e('Email (optional)', 'spintax-domain-manager'); ?></label>
                    <input type="email" name="email" id="email" class="sdm-input">
                </div>
            </div>
            <p class="submit">
                <button type="submit" class="button button-primary sdm-action-button">Add Account</button>
            </p>
        </form>
    <?php endif; ?>

    <!-- Модальное окно для редактирования (скрыто по умолчанию) -->
    <div id="sdm-edit-modal" class="sdm-modal sdm-hidden" data-debug="Modal for editing accounts">
        <div class="sdm-modal-content">
            <span class="sdm-modal-close">×</span>
            <h2>Edit Account</h2>
            <p class="sdm-edit-note">This form is for editing an existing account. Required fields are marked with a yellow border.</p>
            <form id="sdm-edit-account-form" class="sdm-form">
                <?php sdm_nonce_field(); ?>
                <div class="sdm-form-fields">
                    <!-- Скрытые поля для project_id и account_id -->
                    <input type="hidden" name="project_id" id="edit-project_id_hidden" value="">
                    <input type="hidden" name="account_id" id="edit-account_id_hidden" value="">

                    <div class="sdm-form-field">
                        <label for="edit-account_name">Account Name</label>
                        <input type="text" name="account_name" id="edit-account_name" class="sdm-input" value="" placeholder="Enter account name">
                    </div>

                    <div class="sdm-form-field">
                        <label for="edit-service_display">Service</label>
                        <input type="text" name="service_display" id="edit-service_display" class="sdm-input" readonly>
                    </div>
                    <div id="edit-account-fields"></div>
                    <div class="sdm-form-field">
                        <label for="edit-email">Email (optional)</label>
                        <input type="email" name="email" id="edit-email" class="sdm-input">
                    </div>
                </div>
                <p class="submit">
                    <button type="submit" class="button button-primary sdm-action-button">Save Changes</button>
                    <button type="button" class="button sdm-action-button sdm-modal-close">Cancel</button>
                </p>
            </form>
        </div>
    </div>