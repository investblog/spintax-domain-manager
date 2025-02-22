<?php
/**
 * File: admin/pages/domains-page.php
 * Description: Displays the Domains interface with a centered modal for mass adding domains and assigning to sites,
 *              and merges "Blocked (Provider)" and "Blocked (Gov)" columns into a single "Blocked" column.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Получаем список проектов
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Текущий проект (через GET)
$current_project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;

// Если выбран проект, фильтруем домены
$domains = array();
if ( $current_project_id > 0 ) {
    $sql = $wpdb->prepare(
        "SELECT d.*, p.project_name, s.site_name
         FROM {$prefix}sdm_domains d
         LEFT JOIN {$prefix}sdm_projects p ON d.project_id = p.id
         LEFT JOIN {$prefix}sdm_sites s ON d.site_id = s.id
         WHERE d.project_id = %d
         ORDER BY d.created_at DESC",
        $current_project_id
    );
    $domains = $wpdb->get_results( $sql );
}

// Получаем список сайтов для текущего проекта для модального окна
$sites = array();
if ( $current_project_id > 0 ) {
    $sites = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, site_name FROM {$prefix}sdm_sites WHERE project_id = %d ORDER BY site_name ASC",
            $current_project_id
        )
    );
}

// Генерируем nonce
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Domains', 'spintax-domain-manager' ); ?></h1>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr( $main_nonce ); ?>">

    <!-- Notice container -->
    <div id="sdm-domains-notice" class="sdm-notice"></div>

    <!-- Project Selector and Fetch Button -->
    <form method="get" action="">
        <input type="hidden" name="page" value="sdm-domains">

        <label for="sdm-project-selector"><?php esc_html_e( 'Select Project:', 'spintax-domain-manager' ); ?></label>
        <select id="sdm-project-selector" name="project_id" onchange="this.form.submit()">
            <option value="0"><?php esc_html_e( '— Select —', 'spintax-domain-manager' ); ?></option>
            <?php if ( ! empty( $all_projects ) ) : ?>
                <?php foreach ( $all_projects as $proj ) : ?>
                    <option value="<?php echo esc_attr( $proj->id ); ?>"
                        <?php selected( $proj->id, $current_project_id ); ?>>
                        <?php echo sprintf( '%d - %s', $proj->id, $proj->project_name ); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>

        <?php if ( $current_project_id > 0 ) : ?>
            <button type="button" id="sdm-fetch-domains" class="button button-primary" style="margin-left:10px;">
                <?php esc_html_e( 'Fetch Project Domains', 'spintax-domain-manager' ); ?>
            </button>
            <span id="sdm-fetch-domains-status"></span>
        <?php endif; ?>
    </form>

    <?php if ( $current_project_id === 0 ) : ?>
        <p style="margin-top:20px;"><?php esc_html_e( 'Please select a project to view its domains.', 'spintax-domain-manager' ); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <!-- Domains Table -->
    <table id="sdm-domains-table" class="wp-list-table widefat fixed striped sdm-table" style="margin-top:20px;">
        <thead>
            <tr>
                <!-- Domain -->
                <th><?php esc_html_e( 'Domain', 'spintax-domain-manager' ); ?></th>
                <!-- Project -->
                <th><?php esc_html_e( 'Project', 'spintax-domain-manager' ); ?></th>
                <!-- Site -->
                <th><?php esc_html_e( 'Site', 'spintax-domain-manager' ); ?></th>
                <!-- Abuse Status -->
                <th><?php esc_html_e( 'Abuse Status', 'spintax-domain-manager' ); ?></th>
                <!-- Blocked (merged) -->
                <th><?php esc_html_e( 'Blocked', 'spintax-domain-manager' ); ?></th>
                <!-- Status -->
                <th><?php esc_html_e( 'Status', 'spintax-domain-manager' ); ?></th>
                <!-- Last Checked -->
                <th><?php esc_html_e( 'Last Checked', 'spintax-domain-manager' ); ?></th>
                <!-- Created At -->
                <th><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <!-- Actions -->
                <th>
                    <?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?>
                    <input type="checkbox" id="sdm-select-all-domains" style="margin-left:5px;">
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $domains ) ) : ?>
                <?php foreach ( $domains as $domain ) : ?>
                    <?php
                    $is_active  = ( $domain->status === 'active' );
                    // Если хотя бы одно поле блокировки true => "Yes", иначе "No"
                    $is_blocked = ( $domain->is_blocked_provider || $domain->is_blocked_government );
                    $is_assigned = ! empty( $domain->site_id ); // Проверяем, назначен ли домен сайту
                    ?>
                    <tr id="domain-row-<?php echo esc_attr( $domain->id ); ?>"
                        data-domain-id="<?php echo esc_attr( $domain->id ); ?>"
                        data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">

                        <!-- Domain -->
                        <td><?php echo esc_html( $domain->domain ); ?></td>

                        <!-- Project -->
                        <td><?php echo esc_html( $domain->project_name ?: '(No project)' ); ?></td>

                        <!-- Site -->
                        <td><?php echo esc_html( $domain->site_name ?: '(Unassigned)' ); ?></td>

                        <!-- Abuse Status -->
                        <td><?php echo esc_html( $domain->abuse_status ); ?></td>

                        <!-- Blocked (merged) -->
                        <td><?php echo $is_blocked ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?></td>

                        <!-- Status -->
                        <td><?php echo esc_html( $domain->status ); ?></td>

                        <!-- Last Checked -->
                        <td><?php echo esc_html( $domain->last_checked ); ?></td>

                        <!-- Created At -->
                        <td><?php echo esc_html( $domain->created_at ); ?></td>

                        <!-- Actions / Checkboxes -->
                        <td>
                            <?php if ( $is_active && ! $is_assigned ) : ?>
                                <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr( $domain->id ); ?>">
                            <?php elseif ( $is_active && $is_assigned ) : ?>
                                <span class="sdm-assigned-note"><?php esc_html_e( 'Assigned', 'spintax-domain-manager' ); ?></span>
                            <?php else : ?>
                                <a href="#" class="sdm-action-button sdm-delete-domain sdm-delete">
                                    <?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-domains">
                    <td colspan="9"><?php esc_html_e( 'No domains found for this project.', 'spintax-domain-manager' ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Mass Actions Panel -->
    <div id="sdm-mass-actions" style="margin:20px 0;">
        <select id="sdm-mass-action-select">
            <option value=""><?php esc_html_e( 'Select Mass Action', 'spintax-domain-manager' ); ?></option>
            <option value="sync_ns"><?php esc_html_e( 'Sync NS-Servers', 'spintax-domain-manager' ); ?></option>
            <option value="assign_site"><?php esc_html_e( 'Assign to Site', 'spintax-domain-manager' ); ?></option>
            <option value="sync_status"><?php esc_html_e( 'Sync Statuses', 'spintax-domain-manager' ); ?></option>
            <option value="mass_add"><?php esc_html_e( 'Add Domains', 'spintax-domain-manager' ); ?></option>
        </select>
        <button id="sdm-mass-action-apply" class="button"><?php esc_html_e( 'Apply', 'spintax-domain-manager' ); ?></button>
    </div>
</div>

<!-- Modal for Mass Adding Domains to CloudFlare -->
<div id="sdm-mass-add-modal">
    <div id="sdm-mass-add-overlay" class="sdm-modal-overlay"></div>
    <div id="sdm-mass-add-content" class="sdm-modal-content">
        <h2><?php esc_html_e( 'Mass Add Domains to CloudFlare', 'spintax-domain-manager' ); ?></h2>
        <p><?php esc_html_e( 'Enter the domains you want to add, one per line:', 'spintax-domain-manager' ); ?></p>
        <textarea id="sdm-mass-add-textarea" rows="6" style="width:100%;" placeholder="<?php esc_attr_e( 'example.com', 'spintax-domain-manager' ); ?>"></textarea>
        <div style="margin-top:20px;">
            <button id="sdm-modal-confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'spintax-domain-manager' ); ?></button>
            <button id="sdm-modal-close" class="button"><?php esc_html_e( 'Cancel', 'spintax-domain-manager' ); ?></button>
        </div>
    </div>
</div>

<!-- Modal for Assigning Domains to Site -->
<div id="sdm-assign-to-site-modal" style="display:none;">
    <div class="sdm-modal-overlay"></div>
    <div class="sdm-modal-content">
        <span id="sdm-close-assign-modal" style="position:absolute; top:10px; right:15px; font-size:20px; cursor:pointer;">×</span>
        <h2><?php esc_html_e( 'Assign Domains to Site', 'spintax-domain-manager' ); ?></h2>
        <p><?php esc_html_e( 'Selected domains:', 'spintax-domain-manager' ); ?></p>
        <ul id="sdm-selected-domains-list" style="list-style-type: none; padding: 0; margin-bottom: 15px;"></ul>
        <p><?php esc_html_e( 'Select a site to assign the domains:', 'spintax-domain-manager' ); ?></p>
        <select id="sdm-assign-site-select" name="site_id" required>
            <option value=""><?php esc_html_e( 'Select a site', 'spintax-domain-manager' ); ?></option>
            <?php if ( ! empty( $sites ) ) : ?>
                <?php foreach ( $sites as $site ) : ?>
                    <option value="<?php echo esc_attr( $site->id ); ?>">
                        <?php echo esc_html( $site->site_name ); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <div style="margin-top:20px;">
            <button id="sdm-assign-confirm" class="button button-primary"><?php esc_html_e( 'Assign', 'spintax-domain-manager' ); ?></button>
            <button id="sdm-assign-cancel" class="button"><?php esc_html_e( 'Cancel', 'spintax-domain-manager' ); ?></button>
        </div>
    </div>
</div>