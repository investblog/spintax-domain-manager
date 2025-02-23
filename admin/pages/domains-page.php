<?php
/**
 * File: admin/pages/domains-page.php
 * Description: Displays the Domains interface for a selected project, with mass actions, individual domain actions, and column sorting.
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
$main_domains = array(); // Список главных доменов для сайтов
if ( $current_project_id > 0 ) {
    $sql = $wpdb->prepare(
        "SELECT d.*, s.site_name, s.main_domain
         FROM {$prefix}sdm_domains d
         LEFT JOIN {$prefix}sdm_sites s ON d.site_id = s.id
         WHERE d.project_id = %d
         ORDER BY d.created_at DESC",
        $current_project_id
    );
    $domains = $wpdb->get_results( $sql );

    // Получаем список главных доменов для этого проекта
    $main_domains = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT main_domain FROM {$prefix}sdm_sites WHERE project_id = %d",
            $current_project_id
        )
    );
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
    <form method="get" action="" class="sdm-project-form">
        <input type="hidden" name="page" value="sdm-domains">
        <label for="sdm-project-selector" class="sdm-label"><?php esc_html_e( 'Select Project:', 'spintax-domain-manager' ); ?></label>
        <select id="sdm-project-selector" name="project_id" onchange="this.form.submit()" class="sdm-select">
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
            <button type="button" id="sdm-fetch-domains" class="button button-primary sdm-fetch-button" style="margin-left: 10px;">
                <?php esc_html_e( 'Fetch Project Domains', 'spintax-domain-manager' ); ?>
            </button>
            <span id="sdm-fetch-domains-status" class="sdm-status"></span>
        <?php endif; ?>
    </form>

    <!-- Project Indicator (additional context) -->
    <?php if ( $current_project_id > 0 ) : ?>
        <h2 class="sdm-project-indicator" style="margin: 10px 0 20px; font-size: 14px; color: #666;">
            <?php 
            $project_name = '';
            foreach ($all_projects as $proj) {
                if ($proj->id == $current_project_id) {
                    $project_name = $proj->project_name;
                    break;
                }
            }
            echo sprintf( __( 'Viewing domains for project: %d - %s', 'spintax-domain-manager' ), 
                $current_project_id, 
                esc_html( $project_name ?: 'Unknown' ) ); 
            ?>
        </h2>
    <?php else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e( 'Please select a project to view its domains.', 'spintax-domain-manager' ); ?></p>
    <?php endif; ?>

    <!-- Domains Table -->
    <table id="sdm-domains-table" class="wp-list-table widefat fixed striped sdm-table">
        <thead>
            <tr>
                <!-- Domain (sortable) -->
                <th class="sdm-sortable" data-column="domain"><?php esc_html_e( 'Domain', 'spintax-domain-manager' ); ?></th>
                <!-- Site (sortable) -->
                <th class="sdm-sortable" data-column="site_name"><?php esc_html_e( 'Site', 'spintax-domain-manager' ); ?></th>
                <!-- Abuse Status (sortable) -->
                <th class="sdm-sortable" data-column="abuse_status"><?php esc_html_e( 'Abuse Status', 'spintax-domain-manager' ); ?></th>
                <!-- Blocked (sortable) -->
                <th class="sdm-sortable" data-column="blocked"><?php esc_html_e( 'Blocked', 'spintax-domain-manager' ); ?></th>
                <!-- Status (sortable) -->
                <th class="sdm-sortable" data-column="status"><?php esc_html_e( 'Status', 'spintax-domain-manager' ); ?></th>
                <!-- Last Checked (sortable) -->
                <th class="sdm-sortable" data-column="last_checked"><?php esc_html_e( 'Last Checked', 'spintax-domain-manager' ); ?></th>
                <!-- Created At (sortable) -->
                <th class="sdm-sortable" data-column="created_at"><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <!-- Actions (non-sortable, with checkbox) -->
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?>
                    <input type="checkbox" id="sdm-select-all-domains" style="margin-left: 5px; vertical-align: middle;">
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $domains ) ) : ?>
                <?php foreach ( $domains as $domain ) : ?>
                    <?php
                    $is_active  = ( $domain->status === 'active' );
                    $is_blocked = ( $domain->is_blocked_provider || $domain->is_blocked_government );
                    $is_assigned = ! empty( $domain->site_id ); // Проверяем, назначен ли домен сайту
                    $is_main_domain = in_array( $domain->domain, $main_domains ); // Проверяем, является ли домен главным для сайта
                    ?>
                    <tr id="domain-row-<?php echo esc_attr( $domain->id ); ?>"
                        data-domain-id="<?php echo esc_attr( $domain->id ); ?>"
                        data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>"
                        data-site-id="<?php echo esc_attr( $domain->site_id ); ?>"
                        data-domain="<?php echo esc_attr( $domain->domain ); ?>"
                        data-site-name="<?php echo esc_attr( $domain->site_name ?: '' ); ?>"
                        data-abuse-status="<?php echo esc_attr( $domain->abuse_status ); ?>"
                        data-blocked="<?php echo esc_attr( $is_blocked ? 'Yes' : 'No' ); ?>"
                        data-status="<?php echo esc_attr( $domain->status ); ?>"
                        data-last-checked="<?php echo esc_attr( $domain->last_checked ); ?>"
                        data-created-at="<?php echo esc_attr( $domain->created_at ); ?>">

                        <!-- Domain -->
                        <td><?php echo esc_html( $domain->domain ); ?></td>

                        <!-- Site -->
                        <td>
                            <?php if ( $is_assigned ) : ?>
                                <a href="?page=sdm-sites&project_id=<?php echo esc_attr( $current_project_id ); ?>&site_id=<?php echo esc_attr( $domain->site_id ); ?>"
                                   class="sdm-site-link">
                                    <?php echo esc_html( $domain->site_name ); ?>
                                </a>
                                <?php if ( $is_main_domain ) : ?>
                                    <span class="sdm-main-domain-note">(Main)</span>
                                <?php endif; ?>
                            <?php else : ?>
                                (Unassigned)
                            <?php endif; ?>
                        </td>

                        <!-- Abuse Status -->
                        <td><?php echo esc_html( $domain->abuse_status ); ?></td>

                        <!-- Blocked -->
                        <td><?php echo $is_blocked ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?></td>

                        <!-- Status -->
                        <td><?php echo esc_html( $domain->status ); ?></td>

                        <!-- Last Checked -->
                        <td><?php echo esc_html( $domain->last_checked ); ?></td>

                        <!-- Created At -->
                        <td><?php echo esc_html( $domain->created_at ); ?></td>

                        <!-- Actions -->
                        <td>
                            <?php if ( $is_active ) : ?>
                                <?php if ( $is_assigned && ! $is_main_domain ) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr( $domain->id ); ?>">
                                    <a href="#" class="sdm-action-button sdm-unassign" style="background-color: #f7b500; color: #fff; margin-left: 5px;">
                                        Unassign
                                    </a>
                                <?php elseif ( $is_assigned && $is_main_domain ) : ?>
                                    <span class="sdm-assigned-note">Assigned (Main)</span>
                                <?php else : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr( $domain->id ); ?>">
                                <?php endif; ?>
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
                    <td colspan="8"><?php esc_html_e( 'No domains found for this project.', 'spintax-domain-manager' ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Mass Actions Panel -->
    <div class="sdm-mass-actions" style="margin: 20px 0;">
        <select id="sdm-mass-action-select" class="sdm-select">
            <option value="">Select Mass Action</option>
            <option value="sync_ns">Sync NS-Servers</option>
            <option value="assign_site">Assign to Site</option>
            <option value="sync_status">Sync Statuses</option>
            <option value="mass_add">Add Domains</option>
        </select>
        <button id="sdm-mass-action-apply" class="button button-primary sdm-action-button">Apply</button>
    </div>
</div>

<!-- Modal for Mass Adding Domains to CloudFlare -->
<div id="sdm-mass-add-modal" class="sdm-modal">
    <div class="sdm-modal-overlay"></div>
    <div class="sdm-modal-content">
        <h2>Mass Add Domains to CloudFlare</h2>
        <p>Enter the domains you want to add, one per line:</p>
        <textarea id="sdm-mass-add-textarea" rows="6" class="sdm-textarea" placeholder="example.com"></textarea>
        <div class="sdm-modal-actions" style="margin-top: 20px;">
            <button id="sdm-modal-confirm" class="button button-primary sdm-action-button">Confirm</button>
            <button id="sdm-modal-close" class="button sdm-action-button">Cancel</button>
        </div>
    </div>
</div>

<!-- Modal for Assigning Domains to Site -->
<div id="sdm-assign-to-site-modal" class="sdm-modal" style="display:none;">
    <div class="sdm-modal-overlay"></div>
    <div class="sdm-modal-content">
        <span id="sdm-close-assign-modal" class="sdm-modal-close">×</span>
        <h2 id="sdm-modal-action-title">Assign Domains to Site</h2>
        <p id="sdm-modal-instruction">Select a site to assign the domains:</p>
        <ul id="sdm-selected-domains-list" class="sdm-selected-domains"></ul>
        <select id="sdm-assign-site-select" name="site_id" class="sdm-select" required>
            <option value="">Select a site</option>
            <?php if ( ! empty( $sites ) ) : ?>
                <?php foreach ( $sites as $site ) : ?>
                    <option value="<?php echo esc_attr( $site->id ); ?>">
                        <?php echo esc_html( $site->site_name ); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <div class="sdm-modal-actions" style="margin-top: 20px;">
            <button id="sdm-assign-confirm" class="button button-primary sdm-action-button">Assign</button>
            <button id="sdm-assign-cancel" class="button sdm-action-button">Cancel</button>
        </div>
    </div>
</div>