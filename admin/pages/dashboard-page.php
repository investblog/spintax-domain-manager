<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap sdm-dashboard-wrap">
    <h1><?php esc_html_e('Spintax Domain Manager - Dashboard', 'spintax-domain-manager'); ?></h1>
    
    <p><?php esc_html_e('Welcome to the Spintax Manager Dashboard. Here you can see an overview of your projects, domains, and important notifications.', 'spintax-domain-manager'); ?></p>
    
    <!-- Example: Display some basic stats or links -->
    <div class="sdm-dashboard-cards">
        <div class="sdm-dashboard-card">
            <h2><?php esc_html_e('Projects', 'spintax-domain-manager'); ?></h2>
            <p><?php esc_html_e('Total Projects:', 'spintax-domain-manager'); ?>
                <strong>
                <?php
                // Example usage of ProjectsManager to count projects
                $projects_manager = new SDM_Projects_Manager();
                echo $projects_manager->count_projects(); // Just an example method
                ?>
                </strong>
            </p>
            <a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=sdm-projects') ); ?>">
                <?php esc_html_e('Manage Projects', 'spintax-domain-manager'); ?>
            </a>
        </div>
        
        <div class="sdm-dashboard-card">
            <h2><?php esc_html_e('Domains', 'spintax-domain-manager'); ?></h2>
            <p><?php esc_html_e('Total Domains:', 'spintax-domain-manager'); ?>
                <strong>
                <?php
                // Similarly for domains
                $domains_manager = new SDM_Domains_Manager();
                echo $domains_manager->count_domains(); 
                ?>
                </strong>
            </p>
            <a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=sdm-domains') ); ?>">
                <?php esc_html_e('Manage Domains', 'spintax-domain-manager'); ?>
            </a>
        </div>
    </div>
    
    <!-- You can also display notifications, logs, or graphs here -->
    <hr>
    <h2><?php esc_html_e('Notifications & Logs', 'spintax-domain-manager'); ?></h2>
    <p><?php esc_html_e('No new notifications at this time.', 'spintax-domain-manager'); ?></p>
</div>
