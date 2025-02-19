<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
  <h1><?php esc_html_e('Spintax Domain Manager Settings', 'spintax-domain-manager'); ?></h1>
  <form method="post" action="options.php">
    <?php
      settings_fields('sdm_settings_group');
      do_settings_sections('sdm_settings');
      submit_button();
    ?>
  </form>
</div>
