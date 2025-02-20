<?php
/**
 * File: admin/pages/accounts-page.php
 * Description: Displays the Accounts interface with a list of accounts, inline editing, Ajax deletion, and a form to add new accounts.
 *              Теперь включает поля client_id_enc, client_secret_enc, refresh_token_enc с отображением «Encrypted».
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1) Get list of projects
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// 2) Get list of accounts (joined with project_name)
$accounts_manager = new SDM_Accounts_Manager();
$accounts = $accounts_manager->get_all_accounts();

// Unified nonce for inline editing
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Accounts', 'spintax-domain-manager' ); ?></h1>

    <!-- Notice container for accounts -->
    <div id="sdm-accounts-notice" class="sdm-notice"></div>

    <!-- Accounts Table -->
    <table id="sdm-accounts-table" class="wp-list-table widefat fixed striped sdm-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Project ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Project Name', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Service', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Account Name', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Email', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'API Key', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Client ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Client Secret', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Refresh Token', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Additional Data', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $accounts ) ) : ?>
                <?php foreach ( $accounts as $account ) : ?>
                    <tr id="account-row-<?php echo esc_attr( $account->id ); ?>"
                        data-account-id="<?php echo esc_attr( $account->id ); ?>"
                        data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">

                        <!-- Project ID (не редактируется) -->
                        <td class="column-project-id">
                            <?php echo esc_html( $account->project_id ); ?>
                        </td>

                        <!-- Project Name (не редактируется) -->
                        <td class="column-project-name">
                            <?php echo ! empty( $account->project_name )
                                ? esc_html( $account->project_name )
                                : esc_html__( '(No project)', 'spintax-domain-manager' ); ?>
                        </td>

                        <!-- Service (редактируется) -->
                        <td class="column-service">
                            <span class="sdm-display-value"><?php echo esc_html( $account->service ); ?></span>
                            <select class="sdm-edit-input sdm-hidden" name="service">
                                <option value="cloudflare" <?php selected( $account->service, 'cloudflare' ); ?>>Cloudflare</option>
                                <option value="namesilo" <?php selected( $account->service, 'namesilo' ); ?>>NameSilo</option>
                                <option value="namecheap" <?php selected( $account->service, 'namecheap' ); ?>>Namecheap</option>
                                <option value="google" <?php selected( $account->service, 'google' ); ?>>Google</option>
                                <option value="yandex" <?php selected( $account->service, 'yandex' ); ?>>Yandex</option>
                                <option value="xmlstock" <?php selected( $account->service, 'xmlstock' ); ?>>XML Stock</option>
                                <option value="other" <?php selected( $account->service, 'other' ); ?>>Other</option>
                            </select>
                        </td>

                        <!-- Account Name -->
                        <td class="column-account-name">
                            <span class="sdm-display-value"><?php echo esc_html( $account->account_name ); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="account_name" value="<?php echo esc_attr( $account->account_name ); ?>">
                        </td>

                        <!-- Email -->
                        <td class="column-email">
                            <span class="sdm-display-value"><?php echo esc_html( $account->email ); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="email" name="email" value="<?php echo esc_attr( $account->email ); ?>">
                        </td>

                        <!-- API Key -->
                        <td class="column-api-key">
                            <span class="sdm-display-value">
                                <?php echo ! empty( $account->api_key_enc ) 
                                    ? esc_html__( 'Encrypted', 'spintax-domain-manager' )
                                    : ''; ?>
                            </span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="api_key_enc"
                                   placeholder="<?php esc_attr_e('Leave empty to keep existing', 'spintax-domain-manager'); ?>">
                        </td>

                        <!-- Client ID -->
                        <td class="column-client-id">
                            <span class="sdm-display-value">
                                <?php echo ! empty( $account->client_id_enc )
                                    ? esc_html__( 'Encrypted', 'spintax-domain-manager' )
                                    : ''; ?>
                            </span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="client_id_enc"
                                   placeholder="<?php esc_attr_e('Leave empty to keep existing', 'spintax-domain-manager'); ?>">
                        </td>

                        <!-- Client Secret -->
                        <td class="column-client-secret">
                            <span class="sdm-display-value">
                                <?php echo ! empty( $account->client_secret_enc )
                                    ? esc_html__( 'Encrypted', 'spintax-domain-manager' )
                                    : ''; ?>
                            </span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="client_secret_enc"
                                   placeholder="<?php esc_attr_e('Leave empty to keep existing', 'spintax-domain-manager'); ?>">
                        </td>

                        <!-- Refresh Token -->
                        <td class="column-refresh-token">
                            <span class="sdm-display-value">
                                <?php echo ! empty( $account->refresh_token_enc )
                                    ? esc_html__( 'Encrypted', 'spintax-domain-manager' )
                                    : ''; ?>
                            </span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="refresh_token_enc"
                                   placeholder="<?php esc_attr_e('Leave empty to keep existing', 'spintax-domain-manager'); ?>">
                        </td>

                        <!-- Additional Data -->
                        <td class="column-additional-data">
                            <span class="sdm-display-value">
                                <?php echo ! empty( $account->additional_data_enc )
                                    ? esc_html__( 'Encrypted', 'spintax-domain-manager' )
                                    : ''; ?>
                            </span>
                            <textarea class="sdm-edit-input sdm-hidden" name="additional_data_enc" rows="2"
                                      placeholder="<?php esc_attr_e('Leave empty to keep existing', 'spintax-domain-manager'); ?>"></textarea>
                        </td>

                        <td class="column-created"><?php echo esc_html( $account->created_at ); ?></td>

                        <td class="column-actions">
                            <a href="#" class="sdm-action-button sdm-edit sdm-edit-account"><?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?></a>
                            <a href="#" class="sdm-action-button sdm-save sdm-save-account sdm-hidden"><?php esc_html_e( 'Save', 'spintax-domain-manager' ); ?></a> |
                            <a href="#" class="sdm-action-button sdm-delete sdm-delete-account"><?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-accounts">
                    <td colspan="12"><?php esc_html_e( 'No accounts found.', 'spintax-domain-manager' ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr>
    <!-- Add New Account Form -->
    <h2><?php esc_html_e( 'Add New Account', 'spintax-domain-manager' ); ?></h2>

    <?php if ( empty( $all_projects ) ) : ?>
        <!-- No projects -->
        <p style="color: #dc3232;">
            <?php esc_html_e( 'No projects found. Please create a project first.', 'spintax-domain-manager' ); ?>
        </p>
    <?php else : ?>
        <!-- Форма для добавления нового аккаунта -->
        <form id="sdm-add-account-form" class="sdm-form">
            <?php sdm_nonce_field(); ?>
            <table class="sdm-form-table">
                <tr>
                    <th><label for="project_id"><?php esc_html_e( 'Project', 'spintax-domain-manager' ); ?></label></th>
                    <td>
                        <select name="project_id" id="project_id" required>
                            <?php foreach ( $all_projects as $proj ) : ?>
                                <option value="<?php echo esc_attr( $proj->id ); ?>">
                                    <?php echo sprintf( '%d - %s', $proj->id, $proj->project_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="service"><?php esc_html_e( 'Service', 'spintax-domain-manager' ); ?></label></th>
                    <td>
                        <select name="service" id="service">
                            <option value="cloudflare">Cloudflare</option>
                            <option value="namesilo">NameSilo</option>
                            <option value="namecheap">Namecheap</option>
                            <option value="google">Google</option>
                            <option value="yandex">Yandex</option>
                            <option value="xmlstock">XML Stock</option>
                            <option value="other">Other</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="account_name"><?php esc_html_e( 'Account Name (optional)', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="text" name="account_name" id="account_name"></td>
                </tr>
                <tr>
                    <th><label for="email"><?php esc_html_e( 'Email (optional)', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="email" name="email" id="email"></td>
                </tr>
                <tr>
                    <th><label for="api_key_enc"><?php esc_html_e( 'API Key', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="text" name="api_key_enc" id="api_key_enc"></td>
                </tr>
                <tr>
                    <th><label for="client_id_enc"><?php esc_html_e( 'Client ID (optional)', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="text" name="client_id_enc" id="client_id_enc"></td>
                </tr>
                <tr>
                    <th><label for="client_secret_enc"><?php esc_html_e( 'Client Secret (optional)', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="text" name="client_secret_enc" id="client_secret_enc"></td>
                </tr>
                <tr>
                    <th><label for="refresh_token_enc"><?php esc_html_e( 'Refresh Token (optional)', 'spintax-domain-manager' ); ?></label></th>
                    <td><input type="text" name="refresh_token_enc" id="refresh_token_enc"></td>
                </tr>
                <tr>
                    <th><label for="additional_data_enc"><?php esc_html_e( 'Additional Data (JSON)', 'spintax-domain-manager' ); ?></label></th>
                    <td><textarea name="additional_data_enc" id="additional_data_enc" rows="3"></textarea></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Account', 'spintax-domain-manager' ); ?></button>
            </p>
        </form>
    <?php endif; ?>
</div>
