<?php
/** 
 * Plugin Name: Plugin Update Logger With Webhook Options & Daily Outdated Report
 * Version: 1
 */

/**
 * Register settings to store webhook URLs.
 */
function plugin_update_logger_register_settings() {
    register_setting( 'plugin_update_logger_group', 'plugin_update_logger_webhook_url', array(
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ) );
    register_setting( 'plugin_update_logger_group', 'plugin_update_logger_daily_webhook_url', array(
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ) );
}
add_action( 'admin_init', 'plugin_update_logger_register_settings' );

/**
 * Add a top-level admin menu for the plugin.
 */
function plugin_update_logger_add_admin_menu() {
    add_menu_page(
        'AC - Plugin Webhook Logger',           // Page title
        'AC - Plugin Webhook Logger',           // Menu title
        'manage_options',                       // Capability
        'plugin-update-logger-settings',        // Menu slug
        'plugin_update_logger_settings_page',   // Callback
        'dashicons-admin-tools',                // Icon
        25                                      // Position
    );
}
add_action( 'admin_menu', 'plugin_update_logger_add_admin_menu' );

/**
 * Render the settings page form with a "Send Now" button.
 */
function plugin_update_logger_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1>Plugin Update Logger Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'plugin_update_logger_group' ); ?>
            <?php do_settings_sections( 'plugin_update_logger_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Primary Webhook URL:</th>
                    <td>
                        <input type="text" name="plugin_update_logger_webhook_url" value="<?php echo esc_attr( get_option( 'plugin_update_logger_webhook_url', '' ) ); ?>" style="width: 500px;" />
                        <p class="description">Used to send individual plugin update logs.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Daily Outdated Plugins Webhook URL:</th>
                    <td>
                        <input type="text" name="plugin_update_logger_daily_webhook_url" value="<?php echo esc_attr( get_option( 'plugin_update_logger_daily_webhook_url', '' ) ); ?>" style="width: 500px;" />
                        <p class="description">Used to send a once-daily list of outdated plugins.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2>Send Daily Report Now</h2>
        <button id="send-daily-report-now" class="button button-primary">Send Now</button>
        <p id="send-now-status" style="margin-top: 10px;"></p>
    </div>
    <script>
        document.getElementById('send-daily-report-now').addEventListener('click', function() {
            const status = document.getElementById('send-now-status');
            console.log("Verbose log: Initiating 'Send Daily Report Now' process with AJAX params:");
            console.log({
                action: 'plugin_update_logger_send_now',
                nonce: '<?php echo wp_create_nonce( 'send_now_nonce' ); ?>'
            });
            status.innerText = 'Sending...';
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'plugin_update_logger_send_now',
                    _ajax_nonce: '<?php echo wp_create_nonce( 'send_now_nonce' ); ?>'
                }),
            })
            .then(response => response.json())
            .then(data => {
                console.log("Verbose log: Received server response:", data);
                status.innerText = data.success ? 'Report sent successfully!' : `Error: ${data.data}`;
            })
            .catch(err => {
                console.log("Verbose log: AJAX request encountered an error:", err);
                status.innerText = `Error: ${err.message}`;
            });
        });
    </script>
    <?php
}

/**
 * Log plugin updates and send them to the primary webhook.
 */
function plugin_update_logger( $upgrader_object, $options ) {
    if ( isset( $options['type'] ) && 'plugin' === $options['type']
         && isset( $options['action'] ) && 'update' === $options['action']
         && ! empty( $options['plugins'] ) ) {

        $versions = get_option( 'plugin_update_logger_versions', array() );
        $current_time = current_time( 'mysql' );
        $webhook_url = get_option( 'plugin_update_logger_webhook_url', '' );
        $site_url = get_site_url();

        foreach ( $options['plugins'] as $plugin_file ) {
            $old_version = $versions[ $plugin_file ] ?? 'Unknown';
            $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            $new_version = $plugin_data['Version'];
            $plugin_name = $plugin_data['Name'];

            if ( ! empty( $webhook_url ) ) {
                $body = array(
                    'site_url'     => $site_url,
                    'plugin_name'  => $plugin_name,
                    'old_version'  => $old_version,
                    'new_version'  => $new_version,
                    'update_time'  => $current_time,
                );
                wp_remote_post( $webhook_url, array(
                    'method'  => 'POST',
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( $body ),
                    'timeout' => 20,
                ));
            }

            $versions[ $plugin_file ] = $new_version;
        }

        update_option( 'plugin_update_logger_versions', $versions );
    }
}
add_action( 'upgrader_process_complete', 'plugin_update_logger', 10, 2 );

/**
 * Send daily outdated plugin report to the webhook (now includes all plugins).
 */
function plugin_update_logger_daily_check() {
    $daily_webhook_url = get_option( 'plugin_update_logger_daily_webhook_url', '' );
    if ( empty( $daily_webhook_url ) ) return;

    $site_url = get_site_url();
    $update_plugins = get_site_transient( 'update_plugins' );
    $all_plugins = get_plugins();
    $plugins_report = array();

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        $current_version = $plugin_data['Version'];
        $new_version     = $current_version;
        $is_outdated     = false;

        if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
            $new_version = $update_plugins->response[ $plugin_file ]->new_version;
            $is_outdated = true;
        }

        $plugins_report[] = array(
            'plugin_file'     => $plugin_file,
            'plugin_name'     => $plugin_data['Name'],
            'current_version' => $current_version,
            'new_version'     => $new_version,
            'outdated'        => $is_outdated,
        );
    }

    $body = array(
        'site_url'  => $site_url,
        'date'      => current_time( 'mysql' ),
        'plugins'   => $plugins_report,
    );

    wp_remote_post( $daily_webhook_url, array(
        'method'  => 'POST',
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 20,
    ));
}
add_action( 'plugin_update_logger_daily_event', 'plugin_update_logger_daily_check' );

/**
 * Handle "Send Now" AJAX request.
 */
function plugin_update_logger_ajax_send_now() {
    check_ajax_referer( 'send_now_nonce', '_ajax_nonce' );
    plugin_update_logger_daily_check();
    wp_send_json_success( 'Daily report sent successfully.' );
}
add_action( 'wp_ajax_plugin_update_logger_send_now', 'plugin_update_logger_ajax_send_now' );

/**
 * Schedule daily cron event.
 */
function plugin_update_logger_activate() {
    if ( ! wp_next_scheduled( 'plugin_update_logger_daily_event' ) ) {
        wp_schedule_event( time(), 'daily', 'plugin_update_logger_daily_event' );
    }
}
register_activation_hook( __FILE__, 'plugin_update_logger_activate' );

/**
 * Clear scheduled events on deactivation.
 */
function plugin_update_logger_deactivate() {
    wp_clear_scheduled_hook( 'plugin_update_logger_daily_event' );
}
register_deactivation_hook( __FILE__, 'plugin_update_logger_deactivate' );


?>