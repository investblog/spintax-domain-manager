<?php
/**
 * File: admin/pages/redirects-page.php
 * Description: Displays the Redirects interface for a selected project, allowing management of redirects for domains and sites with site icons or flags and mass actions for default redirects.
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

// Если выбран проект, фильтруем домены с их сайтами и редиректами
$domains = array();
$sites = array();
if ( $current_project_id > 0 ) {
    $domains = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT d.*, s.site_name, s.language, s.svg_icon, s.main_domain, r.id AS redirect_id, r.source_url, r.target_url, r.type, r.redirect_type, r.preserve_query_string, r.user_agent, r.created_at AS redirect_created_at
             FROM {$prefix}sdm_domains d
             LEFT JOIN {$prefix}sdm_sites s ON d.site_id = s.id
             LEFT JOIN {$prefix}sdm_redirects r ON d.id = r.domain_id
             WHERE d.project_id = %d
             ORDER BY d.created_at DESC",
            $current_project_id
        )
    );

    // Получаем список сайтов для текущего проекта для группировки
    $sites = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, site_name, language, svg_icon, main_domain FROM {$prefix}sdm_sites WHERE project_id = %d ORDER BY site_name ASC",
            $current_project_id
        )
    );
}

// Генерируем nonce
$main_nonce = sdm_create_main_nonce();
$redirects_manager = new SDM_Redirects_Manager();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Redirects', 'spintax-domain-manager' ); ?></h1>

    <!-- Hidden field for global nonce -->
    <input type="hidden" id="sdm-main-nonce" value="<?php echo esc_attr( $main_nonce ); ?>">

    <!-- Notice container -->
    <div id="sdm-redirects-notice" class="sdm-notice"></div>

    <!-- Project Selector -->
    <form method="get" action="" class="sdm-project-form">
        <input type="hidden" name="page" value="sdm-redirects">
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
    </form>

    <?php if ( $current_project_id === 0 ) : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e( 'Please select a project to view its redirects.', 'spintax-domain-manager' ); ?></p>
        <?php return; ?>
    <?php endif; ?>

    <!-- Project Indicator (additional context) -->
    <p class="sdm-project-indicator" style="margin: 10px 0 20px; font-size: 14px; color: #666;">
        <?php 
        $project_name = '';
        foreach ($all_projects as $proj) {
            if ($proj->id == $current_project_id) {
                $project_name = $proj->project_name;
                break;
            }
        }
        echo sprintf( __( 'Viewing redirects for project: %d - %s', 'spintax-domain-manager' ), 
            $current_project_id, 
            esc_html( $project_name ?: 'Unknown' ) ); 
        ?>
    </p>

    <!-- Action Buttons -->
    <div style="margin-bottom: 20px;">
        <button id="sdm-sync-cloudflare" class="button sdm-action-button" style="background-color: #0073aa; color: #fff;">
            <?php esc_html_e( 'Sync with CloudFlare', 'spintax-domain-manager' ); ?>
        </button>
    </div>

    <?php if ( ! empty( $sites ) ) : ?>
        <?php foreach ( $sites as $site ) : ?>
            <h3><?php echo esc_html( $site->site_name ); ?>
                <?php if ( ! empty( $site->svg_icon ) ) : ?>
                    <span class="sdm-site-icon" style="vertical-align: middle; margin-left: 5px;" dangerouslySetInnerHTML={{__html: <?php echo htmlspecialchars( $site->svg_icon ); ?> }}></span>
                <?php else : ?>
                    <span class="fi fi-<?php echo esc_attr( sdm_normalize_language_code( $site->language ?: 'en' ) ); ?>" style="vertical-align: middle; margin-left: 5px;"></span>
                <?php endif; ?>
            </h3>
            <p><?php echo esc_html__( 'Main Domain:', 'spintax-domain-manager' ) . ' ' . esc_html( $site->main_domain ); ?></p>
            <table class="wp-list-table widefat fixed striped sdm-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Domain', 'spintax-domain-manager' ); ?></th>
                        <th><?php esc_html_e( 'Redirect Status', 'spintax-domain-manager' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?>
                            <input type="checkbox" class="sdm-select-all-site-redirects" data-site-id="<?php echo esc_attr( $site->id ); ?>" style="margin-left: 5px; vertical-align: middle;">
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $domains = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT d.*, r.id AS redirect_id, r.source_url, r.target_url, r.type, r.redirect_type, r.preserve_query_string, r.user_agent, r.created_at AS redirect_created_at
                             FROM {$prefix}sdm_domains d
                             LEFT JOIN {$prefix}sdm_redirects r ON d.id = r.domain_id
                             WHERE d.project_id = %d AND d.site_id = %d
                             ORDER BY d.domain ASC",
                            $current_project_id,
                            $site->id
                        )
                    );
                    if ( ! empty( $domains ) ) : ?>
                        <?php foreach ( $domains as $domain ) : ?>
                            <?php
                            $is_blocked = ( $domain->is_blocked_provider || $domain->is_blocked_government );
                            $redirect = (object) array(
                                'id' => $domain->redirect_id,
                                'domain_id' => $domain->id,
                                'source_url' => $domain->source_url,
                                'target_url' => $domain->target_url,
                                'type' => $domain->type,
                                'redirect_type' => $domain->redirect_type,
                                'preserve_query_string' => $domain->preserve_query_string,
                                'user_agent' => $domain->user_agent,
                                'created_at' => $domain->redirect_created_at,
                            );
                            $redirect_type = $redirect->id ? $redirect->redirect_type : '';
                            $redirect_status = $redirect->id ? sprintf( __( 'Redirect exists (%s)', 'spintax-domain-manager' ), ucfirst( $redirect_type ) ) : __( 'No redirect', 'spintax-domain-manager' );
                            $is_main_domain = ($domain->domain === $site->main_domain);
                            ?>
                            <tr id="redirect-row-<?php echo esc_attr( $domain->id ); ?>"
                                data-domain-id="<?php echo esc_attr( $domain->id ); ?>"
                                data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>"
                                data-redirect-type="<?php echo esc_attr( $redirect_type ); ?>"
                                data-domain="<?php echo esc_attr( $domain->domain ); ?>"
                                data-site-id="<?php echo esc_attr( $site->id ); ?>"
                                data-source-url="<?php echo esc_attr( $redirect->source_url ?: '' ); ?>"
                                data-target-url="<?php echo esc_attr( $redirect->target_url ?: '' ); ?>"
                                data-type="<?php echo esc_attr( $redirect->type ?: '' ); ?>"
                                data-created-at="<?php echo esc_attr( $redirect->created_at ?: '' ); ?>">

                                <td class="<?php echo $is_blocked ? 'sdm-blocked-domain' : ''; ?>">
                                    <?php echo esc_html( $domain->domain ); ?>
                                    <?php if ( !$is_main_domain ) : ?>
                                        <span class="sdm-redirect-arrow" data-redirect-type="<?php echo esc_attr( $redirect_type ); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $redirect_status ); ?></td>
                                <td>
                                    <?php if ( !$is_main_domain ) : ?>
                                        <input type="checkbox" class="sdm-redirect-checkbox" value="<?php echo esc_attr( $domain->id ); ?>" data-site-id="<?php echo esc_attr( $site->id ); ?>">
                                        <?php if ( $redirect->id ) : ?>
                                            <a href="#" class="sdm-action-button sdm-delete-redirect" data-redirect-id="<?php echo esc_attr( $redirect->id ); ?>" style="background-color: #dc3232; color: #fff; margin-left: 5px;">
                                                <?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="#" class="sdm-action-button sdm-create-redirect" data-domain-id="<?php echo esc_attr( $domain->id ); ?>" style="background-color: #0073aa; color: #fff;">
                                                <?php esc_html_e( 'Create Default Redirect', 'spintax-domain-manager' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Main Domain (no redirect)', 'spintax-domain-manager' ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="3"><?php esc_html_e( 'No domains found for this site.', 'spintax-domain-manager' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="sdm-mass-actions" style="margin: 20px 0;">
                <select class="sdm-mass-action-select-site" data-site-id="<?php echo esc_attr( $site->id ); ?>">
                    <option value=""><?php esc_html_e( 'Select Mass Action', 'spintax-domain-manager' ); ?></option>
                    <option value="create_default"><?php esc_html_e( 'Create Default Redirects', 'spintax-domain-manager' ); ?></option>
                    <option value="sync_cloudflare"><?php esc_html_e( 'Sync with CloudFlare', 'spintax-domain-manager' ); ?></option>
                </select>
                <button class="button button-primary sdm-mass-action-apply-site" data-site-id="<?php echo esc_attr( $site->id ); ?>">Apply</button>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p style="margin: 20px 0; color: #666;"><?php esc_html_e( 'No sites found for this project.', 'spintax-domain-manager' ); ?></p>
    <?php endif; ?>
</div>