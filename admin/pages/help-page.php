<?php
/**
 * File: admin/pages/help-page.php
 * Description: Renders a help page with tabs for Yandex & Google setup instructions (example).
 */

if (!defined('ABSPATH')) {
    exit;
}

function sdm_render_help_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Help & Instructions', 'spintax-domain-manager'); ?></h1>

        <!-- Nav tabs -->
        <nav class="nav-tab-wrapper">
            <a href="#sdm-help-tab-yandex" class="nav-tab nav-tab-active">
                <?php esc_html_e('Yandex', 'spintax-domain-manager'); ?>
            </a>
            <a href="#sdm-help-tab-google" class="nav-tab">
                <?php esc_html_e('Google', 'spintax-domain-manager'); ?>
            </a>
        </nav>

        <!-- Tab content -->
        <div id="sdm-help-tab-yandex" class="sdm-help-tab-content" style="display: block;">
            <?php sdm_help_tab_yandex_content(); ?>
        </div>

        <div id="sdm-help-tab-google" class="sdm-help-tab-content" style="display: none;">
            <?php sdm_help_tab_google_content(); ?>
        </div>
    </div><!-- .wrap -->

    <!-- Simple tab switch JS + AJAX for Yandex user_id -->
    <script>
    jQuery(document).ready(function($) {
        // Tab switch
        $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
            e.preventDefault();
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            var target = $(this).attr('href');
            $('.sdm-help-tab-content').hide();
            $(target).show();
        });

        // AJAX "Get Yandex User ID"
        $('#sdm-get-yandex-user-id-btn').on('click', function(e) {
            e.preventDefault();
            var token = $('#sdm_yandex_oauth_token').val().trim();
            if (!token) {
                alert('<?php echo esc_js(__('Please enter your OAuth token first.', 'spintax-domain-manager')); ?>');
                return;
            }
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'sdm_get_yandex_user_id',
                    nonce: '<?php echo wp_create_nonce('sdm_help_page_nonce'); ?>',
                    oauth_token: token
                },
                success: function(response) {
                    if (response.success) {
                        $('#sdm-yandex-user-id-result').text(
                            '<?php echo esc_js(__('Your Yandex User ID:', 'spintax-domain-manager')); ?> ' + response.data.user_id
                        );
                    } else {
                        $('#sdm-yandex-user-id-result').text(
                            'Error: ' + (response.data.message || 'Unknown error.')
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('#sdm-yandex-user-id-result').text('AJAX error: ' + error);
                }
            });
        });
    });
    </script>
    <?php
}

function sdm_help_tab_yandex_content() {
    ?>
    <h2><?php esc_html_e('Yandex Webmaster API Setup', 'spintax-domain-manager'); ?></h2>
    <ol>
        <li>
            <?php esc_html_e('Go to the Yandex OAuth client creation page:', 'spintax-domain-manager'); ?>
            <a href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Yandex OAuth Client Creation', 'spintax-domain-manager'); ?>
            </a>
        </li>
        <li>
            <?php esc_html_e('Sign in to your Yandex account or create a new one if you do not have one yet.', 'spintax-domain-manager'); ?>
        </li>
        <li>
            <?php esc_html_e('Create a new application:', 'spintax-domain-manager'); ?>
            <ol style="list-style-type: lower-alpha;">
                <li><?php esc_html_e('Click the "Create Application" button.', 'spintax-domain-manager'); ?></li>
                <li><?php esc_html_e('Enter an application name and description. Choose "Web service".', 'spintax-domain-manager'); ?></li>
                <li>
                    <?php esc_html_e('In Redirect URI, you can specify something like', 'spintax-domain-manager'); ?> 
                    <code>https://oauth.yandex.ru/verification_code</code>
                </li>
                <li>
                    <?php esc_html_e('In "Suggest Hostname" you can use your site URL or http://localhost.', 'spintax-domain-manager'); ?>
                </li>
                <li>
                    <?php esc_html_e('Scroll to "Yandex.Webmaster" and select the following scopes:', 'spintax-domain-manager'); ?>
                    <ul style="list-style-type: disc; margin-left: 20px;">
                        <li><strong>webmaster:verify</strong> (<?php esc_html_e('to add sites and verify them in Yandex.Webmaster', 'spintax-domain-manager'); ?>)</li>
                        <li><strong>webmaster:hostinfo</strong> (<?php esc_html_e('to retrieve indexing status and backlink info', 'spintax-domain-manager'); ?>)</li>
                    </ul>
                </li>
                <li><?php esc_html_e('Click "Save".', 'spintax-domain-manager'); ?></li>
            </ol>
        </li>
        <li>
            <?php esc_html_e('After creation, you will see "Client ID" and "Client Secret". Keep these safe.', 'spintax-domain-manager'); ?>
        </li>
        <li>
            <?php esc_html_e('To get an OAuth token, you can use your Client ID in a link like:', 'spintax-domain-manager'); ?><br />
            <code>https://oauth.yandex.ru/authorize?response_type=token&client_id=YOUR_CLIENT_ID</code><br />
            <?php esc_html_e('Then, after authorizing, you will get an Access Token in the URL fragment.', 'spintax-domain-manager'); ?>
        </li>
    </ol>

    <h3><?php esc_html_e('Get Your Yandex User ID', 'spintax-domain-manager'); ?></h3>
    <p>
        <?php esc_html_e('If you already know your Yandex user_id, skip this step. Otherwise, enter your OAuth token below and click "Get Yandex User ID".', 'spintax-domain-manager'); ?>
    </p>
    <form id="sdm-get-yandex-user-id-form" method="post">
        <label for="sdm_yandex_oauth_token">
            <?php esc_html_e('Enter your Yandex OAuth Token:', 'spintax-domain-manager'); ?>
        </label>
        <input type="text" id="sdm_yandex_oauth_token" name="sdm_yandex_oauth_token" class="regular-text" />
        <button type="button" id="sdm-get-yandex-user-id-btn" class="button">
            <?php esc_html_e('Get Yandex User ID', 'spintax-domain-manager'); ?>
        </button>
        <p id="sdm-yandex-user-id-result" style="margin-top:10px;"></p>
    </form>
    <?php
}

function sdm_help_tab_google_content() {
    ?>
    <h2><?php esc_html_e('Google Webmaster API Setup', 'spintax-domain-manager'); ?></h2>
    <ol>
        <li>
            <?php esc_html_e('Go to the Google Cloud Console:', 'spintax-domain-manager'); ?>
            <a href="https://console.developers.google.com/" target="_blank">
                <?php esc_html_e('Google Cloud Console', 'spintax-domain-manager'); ?>
            </a>
        </li>
        <li>
            <?php esc_html_e('Create or select an existing project.', 'spintax-domain-manager'); ?>
        </li>
        <li>
            <?php esc_html_e('Enable the following APIs:', 'spintax-domain-manager'); ?>
            <ul style="list-style-type: disc; margin-left:20px;">
                <li><?php esc_html_e('Google Search Console API', 'spintax-domain-manager'); ?></li>
                <li><?php esc_html_e('Google Site Verification API', 'spintax-domain-manager'); ?></li>
            </ul>
        </li>
        <li>
            <?php esc_html_e('Create OAuth 2.0 Client ID and API Key.', 'spintax-domain-manager'); ?>
        </li>
    </ol>

    <h3><?php esc_html_e('Use PowerShell to Generate Tokens (Example)', 'spintax-domain-manager'); ?></h3>
    <p>
        <?php esc_html_e('If you prefer, you can use a PowerShell script to get a Refresh Token. Replace the placeholders with your actual Client ID & Secret:', 'spintax-domain-manager'); ?>
    </p>
    <textarea rows="14" cols="80" readonly style="width:100%; max-width:800px;">
# Parameters
$client_id = "YOUR_GOOGLE_CLIENT_ID"
$client_secret = "YOUR_GOOGLE_CLIENT_SECRET"
$scope = "https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/siteverification"
$redirect_uri = "http://localhost"

# 1. Generate auth URL
$auth_url = "https://accounts.google.com/o/oauth2/auth?response_type=code&client_id=$client_id&redirect_uri=$redirect_uri&scope=$scope&prompt=consent&access_type=offline"
Write-Host "Open this link in your browser:"
Write-Host $auth_url
Start-Process $auth_url

# 2. Input authorization code
$auth_code = Read-Host "Enter the authorization code from the redirect"

# 3. Exchange authorization code for tokens
$body = @{
    code          = $auth_code
    client_id     = $client_id
    client_secret = $client_secret
    redirect_uri  = $redirect_uri
    grant_type    = "authorization_code"
}

$response = Invoke-RestMethod -Uri "https://oauth2.googleapis.com/token" -Method Post `
    -ContentType "application/x-www-form-urlencoded" -Body $body

$response | ConvertTo-Json -Depth 3
Write-Host "Access Token: " $response.access_token
Write-Host "Refresh Token: " $response.refresh_token
    </textarea>
    <p>
        <?php esc_html_e('Once you obtain these tokens, go to the "Accounts" page in this plugin, add a new account for "Google", and fill in the required fields.', 'spintax-domain-manager'); ?>
    </p>
    <?php
}

 // end of sdm_render_help_page()

add_action('wp_ajax_sdm_get_yandex_user_id', 'sdm_get_yandex_user_id');
function sdm_get_yandex_user_id() {
    // 1. Проверим nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sdm_help_page_nonce')) {
        wp_send_json_error(array('message' => __('Nonce verification failed.', 'spintax-domain-manager')));
    }

    // 2. Получаем OAuth токен из запроса
    $oauth_token = isset($_POST['oauth_token']) ? sanitize_text_field($_POST['oauth_token']) : '';

    if (empty($oauth_token)) {
        wp_send_json_error(array('message' => __('OAuth token is required.', 'spintax-domain-manager')));
    }

    // 3. Вызываем Yandex API для получения информации о пользователе
    $url = 'https://login.yandex.ru/info?format=json&oauth_token=' . urlencode($oauth_token);
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => $response->get_error_message()));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // 4. Проверяем, вернулся ли user_id
    if (isset($data['id'])) {
        // Удачно
        wp_send_json_success(array('user_id' => $data['id']));
    } else {
        // Ошибка
        wp_send_json_error(array(
            'message'  => __('Failed to retrieve User ID. Please check your OAuth Token.', 'spintax-domain-manager'),
            'response' => $data,
        ));
    }
}
