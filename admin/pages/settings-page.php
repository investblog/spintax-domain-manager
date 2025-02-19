<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
  <h1><?php esc_html_e( 'Spintax Domain Manager Settings', 'spintax-domain-manager' ); ?></h1>

  <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : ?>
    <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible">
      <p><strong><?php esc_html_e( 'Settings saved.', 'spintax-domain-manager' ); ?></strong></p>
    </div>
  <?php endif; ?>

  <form method="post" action="options.php">
    <?php
      settings_fields( 'sdm_settings_group' );
      do_settings_sections( 'sdm_settings' );
      submit_button();
    ?>
  </form>
</div>
