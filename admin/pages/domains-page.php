<?php
/**
 * File: admin/pages/domains-page.php
 * Description: Displays the Domains interface with a list of domains (including monitoring fields),
 *              a project selector to fetch CloudFlare domains, a mass actions panel, and a splash modal
 *              for mass adding domains to CloudFlare.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Get all projects to populate the project selector.
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Retrieve the list of domains with JOINs for project and site names.
$sql = "
    SELECT d.*, p.project_name, s.site_name
    FROM {$prefix}sdm_domains d
    LEFT JOIN {$prefix}sdm_projects p ON d.project_id = p.id
    LEFT JOIN {$prefix}sdm_sites s ON d.site_id = s.id
    ORDER BY d.created_at DESC
";
$domains = $wpdb->get_results( $sql );

// Generate a nonce for AJAX operations.
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Domains', 'spintax-domain-manager' ); ?></h1>

    <!-- Notice container for domains (persistent until closed) -->
    <div id="sdm-domains-notice" class="sdm-notice"></div>

    <!-- Project Selector and Fetch Domains Button -->
    <div id="sdm-fetch-domains-container" style="margin-bottom:20px;">
        <label for="sdm-project-selector"><?php esc_html_e( 'Select Project:', 'spintax-domain-manager' ); ?></label>
        <select id="sdm-project-selector" name="project_id">
            <?php if ( ! empty( $all_projects ) ) : ?>
                <?php foreach ( $all_projects as $proj ) : ?>
                    <option value="<?php echo esc_attr( $proj->id ); ?>">
                        <?php echo sprintf( '%d - %s', $proj->id, $proj->project_name ); ?>
                    </option>
                <?php endforeach; ?>
            <?php else : ?>
                <option value=""><?php esc_html_e( 'No projects available', 'spintax-domain-manager' ); ?></option>
            <?php endif; ?>
        </select>
        <button id="sdm-fetch-domains" class="button button-primary">
            <?php esc_html_e( 'Fetch Project Domains', 'spintax-domain-manager' ); ?>
        </button>
        <span id="sdm-fetch-domains-status"></span>
    </div>

    <!-- Domains Table -->
    <table id="sdm-domains-table" class="wp-list-table widefat fixed striped sdm-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="sdm-select-all-domains"></th>
                <th><?php esc_html_e( 'ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Project', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Site', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Domain', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'CF Zone ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Abuse Status', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Blocked (Provider)', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Blocked (Gov)', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Status', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Last Checked', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $domains ) ) : ?>
                <?php foreach ( $domains as $domain ) : ?>
                    <tr id="domain-row-<?php echo esc_attr( $domain->id ); ?>"
                        data-domain-id="<?php echo esc_attr( $domain->id ); ?>"
                        data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">
                        <td><input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr( $domain->id ); ?>"></td>
                        <td><?php echo esc_html( $domain->id ); ?></td>
                        <td><?php echo esc_html( ! empty( $domain->project_name ) ? $domain->project_name : '(No project)' ); ?></td>
                        <td><?php echo esc_html( ! empty( $domain->site_name ) ? $domain->site_name : '(Unassigned)' ); ?></td>
                        <td><?php echo esc_html( $domain->domain ); ?></td>
                        <td><?php echo esc_html( $domain->cf_zone_id ); ?></td>
                        <td><?php echo esc_html( $domain->abuse_status ); ?></td>
                        <td><?php echo $domain->is_blocked_provider ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?></td>
                        <td><?php echo $domain->is_blocked_government ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?></td>
                        <td><?php echo esc_html( $domain->status ); ?></td>
                        <td><?php echo esc_html( $domain->last_checked ); ?></td>
                        <td><?php echo esc_html( $domain->created_at ); ?></td>
                        <td>
                            <!-- Individual actions: e.g., editing redirect type -->
                            <a href="#" class="sdm-action-button sdm-edit-domain"><?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?></a> |
                            <a href="#" class="sdm-action-button sdm-delete-domain"><?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-domains">
                    <td colspan="13"><?php esc_html_e( 'No domains found.', 'spintax-domain-manager' ); ?></td>
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

<!-- Splash Modal for Mass Adding Domains to CloudFlare -->
<div id="sdm-mass-add-modal" class="sdm-hidden">
    <div class="sdm-modal-overlay"></div>
    <div class="sdm-modal-content" style="background:#fff; padding:20px; border-radius:4px; max-width:500px; margin:50px auto; position:relative;">
        <h2><?php esc_html_e( 'Mass Add Domains to CloudFlare', 'spintax-domain-manager' ); ?></h2>
        <p><?php esc_html_e( 'Enter the domains you want to add, one per line:', 'spintax-domain-manager' ); ?></p>
        <textarea id="sdm-mass-add-textarea" rows="6" style="width:100%;" placeholder="<?php esc_attr_e( 'example.com', 'spintax-domain-manager' ); ?>"></textarea>
        <div style="margin-top:20px;">
            <button id="sdm-modal-confirm" class="button button-primary"><?php esc_html_e( 'Confirm', 'spintax-domain-manager' ); ?></button>
            <button id="sdm-modal-close" class="button"><?php esc_html_e( 'Cancel', 'spintax-domain-manager' ); ?></button>
        </div>
    </div>
</div>



