<?php
/**
 * File: admin/pages/sites-page.php
 * Description: Displays the Sites interface with a list of sites for a selected project and a form to add a new site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix;

// Получаем список проектов для селектора
$projects_manager = new SDM_Projects_Manager();
$all_projects = $projects_manager->get_all_projects();

// Определяем текущий выбранный проект (через GET)
$current_project_id = isset($_GET['project_id']) ? absint($_GET['project_id']) : 0;

// Если проект выбран, получаем сайты этого проекта
$sites = array();
if ( $current_project_id > 0 ) {
    $sites = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$prefix}sdm_sites WHERE project_id = %d ORDER BY created_at DESC",
            $current_project_id
        )
    );
}

// Получаем список свободных доменов проекта
$non_blocked_domains = array();
if ( $current_project_id > 0 ) {
    $non_blocked_domains = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT domain FROM {$prefix}sdm_domains 
             WHERE project_id = %d 
               AND site_id IS NULL 
               AND is_blocked_provider = 0 
               AND is_blocked_government = 0",
            $current_project_id
        ),
        ARRAY_A
    );
}

$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Sites', 'spintax-domain-manager' ); ?></h1>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr( $main_nonce ); ?>">

    <!-- Notice container for sites -->
    <div id="sdm-sites-notice" class="sdm-notice"></div>

    <!-- Project Selector -->
    <form method="get" action="">
        <input type="hidden" name="page" value="sdm-sites">
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
    </form>

    <?php if ( $current_project_id === 0 ) : ?>
        <p style="margin-top:20px;"><?php esc_html_e( 'Please select a project to view its sites.', 'spintax-domain-manager' ); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <!-- Sites Table -->
    <table id="sdm-sites-table" class="wp-list-table widefat fixed striped sdm-table" style="margin-top:20px;">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Icon', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Site Name', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Main Domain', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Language', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Updated At', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $sites ) ) : ?>
                <?php foreach ( $sites as $site ) : ?>
                    <tr id="site-row-<?php echo esc_attr( $site->id ); ?>" data-site-id="<?php echo esc_attr( $site->id ); ?>" data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">
                        <td class="column-icon">
                            <span class="sdm-site-icon" data-site-id="<?php echo esc_attr( $site->id ); ?>">
                                <?php 
                                if ( ! empty( $site->svg_icon ) ) {
                                    echo $site->svg_icon; // Выводим пользовательский SVG
                                } else {
                                    echo file_get_contents( SDM_PLUGIN_DIR . 'assets/icons/spintax-icon.svg' );
                                }
                                ?>
                            </span>
                        </td>
                        <td class="column-site-name">
                            <span class="sdm-display-value"><?php echo esc_html( $site->site_name ); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="site_name" value="<?php echo esc_attr( $site->site_name ); ?>">
                        </td>
                        <td class="column-main-domain">
                            <span class="sdm-display-value"><?php echo esc_html( $site->main_domain ); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="main_domain" value="<?php echo esc_attr( $site->main_domain ); ?>">
                        </td>
                        <td><?php echo esc_html( $site->language ); ?></td>
                        <td><?php echo esc_html( $site->created_at ); ?></td>
                        <td><?php echo esc_html( $site->updated_at ); ?></td>
                        <td class="column-actions">
                            <a href="#" class="sdm-action-button sdm-edit-site sdm-edit"><?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?></a>
                            <a href="#" class="sdm-action-button sdm-save-site sdm-save sdm-hidden"><?php esc_html_e( 'Save', 'spintax-domain-manager' ); ?></a> |
                            <a href="#" class="sdm-action-button sdm-delete-site sdm-delete"><?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-sites">
                    <td colspan="7"><?php esc_html_e( 'No sites found for this project.', 'spintax-domain-manager' ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Modal for Editing SVG Icon -->
    <div id="sdm-edit-icon-modal" style="display:none;">
        <div class="sdm-modal-overlay"></div>
        <div class="sdm-modal-content">
            <span id="sdm-close-icon-modal" style="position:absolute; top:10px; right:15px; font-size:20px; cursor:pointer;">×</span>
            <h2><?php esc_html_e( 'Edit Site Icon', 'spintax-domain-manager' ); ?></h2>
            <form id="sdm-edit-icon-form" class="sdm-form" method="post" action="">
                <input type="hidden" name="site_id" id="sdm-icon-site-id">
                <?php sdm_nonce_field(); ?>
                <table class="sdm-form-table">
                    <tr>
                        <th><label for="svg_icon"><?php esc_html_e( 'SVG Icon', 'spintax-domain-manager' ); ?></label></th>
                        <td>
                            <textarea name="svg_icon" id="svg_icon" rows="5" placeholder='<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/></svg>'></textarea>
                            <p class="description"><?php esc_html_e( 'Paste your inline SVG code here. Recommended size: 24x24px.', 'spintax-domain-manager' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Icon', 'spintax-domain-manager' ); ?></button>
                </p>
            </form>
        </div>
    </div>

    <!-- Form for Adding a New Site (оставляем без изменений) -->
    <h2><?php esc_html_e( 'Add New Site', 'spintax-domain-manager' ); ?></h2>
    <form id="sdm-add-site-form" class="sdm-form" method="post" action="">
        <?php sdm_nonce_field(); ?>
        <input type="hidden" name="project_id" value="<?php echo esc_attr( $current_project_id ); ?>">
        <table class="sdm-form-table">
            <tr>
                <th><label for="site_name"><?php esc_html_e( 'Site Name', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="site_name" id="site_name" required></td>
            </tr>
            <tr>
                <th><label for="server_ip"><?php esc_html_e( 'Server IP (optional)', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="server_ip" id="server_ip"></td>
            </tr>
            <tr>
                <th><label for="main_domain"><?php esc_html_e( 'Main Domain', 'spintax-domain-manager' ); ?></label></th>
                <td>
                    <input type="text" name="main_domain" id="main_domain" list="non_blocked_domains_list" placeholder="e.g. example.com" required>
                    <datalist id="non_blocked_domains_list">
                        <?php if ( ! empty( $non_blocked_domains ) ) : ?>
                            <?php foreach ( $non_blocked_domains as $row ) : ?>
                                <option value="<?php echo esc_attr( $row['domain'] ); ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </datalist>
                </td>
            </tr>
            <tr>
                <th><label for="language"><?php esc_html_e( 'Language', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="language" id="language" placeholder="EN_en" required></td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Site', 'spintax-domain-manager' ); ?></button>
        </p>
    </form>
</div>