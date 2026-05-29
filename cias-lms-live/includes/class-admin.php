<?php
namespace CIAS_LIVE;

defined( 'ABSPATH' ) || exit;

class Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_notices', [ __CLASS__, 'show_notices' ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'CIAS LMS Live',
            'LMS Live',
            'manage_options',
            'cias-lms-live',
            [ __CLASS__, 'render_page' ],
            'dashicons-video-alt2',
            30
        );
    }

    public static function show_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'cias-lms-live' ) ) return;

        if ( isset( $_GET['zoom_connected'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Zoom account connected successfully!</p></div>';
        }
        if ( isset( $_GET['zoom_error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Zoom connection failed: ' . esc_html( $_GET['zoom_error'] ) . '</p></div>';
        }

        // Check if Zoom credentials are configured
        if ( ! defined( 'CIAS_ZOOM_CLIENT_ID' ) || ! defined( 'CIAS_ZOOM_CLIENT_SECRET' ) ) {
            echo '<div class="notice notice-warning"><p>⚠️ <strong>CIAS LMS Live:</strong> Add <code>CIAS_ZOOM_CLIENT_ID</code> and <code>CIAS_ZOOM_CLIENT_SECRET</code> to your wp-config.php to enable Zoom integration.</p></div>';
        }
    }

    public static function render_page(): void {
        $hosts        = \CIAS_LIVE\Services\ZoomHostPool::get_all_hosts();
        $connect_url  = defined( 'CIAS_ZOOM_CLIENT_ID' )
            ? rest_url( CIAS_LIVE_API_NS . '/' . CIAS_LIVE_API_BASE . '/zoom-connect' )
            : '#';
        ?>
        <div class="wrap cias-live-admin">
            <h1>CIAS LMS Live</h1>

            <!-- Zoom Host Pool -->
            <div class="cias-card">
                <div class="cias-card-header">
                    <h2>Zoom Host Accounts</h2>
                    <a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary">
                        + Connect Zoom Account
                    </a>
                </div>

                <?php if ( empty( $hosts ) ): ?>
                    <div class="cias-empty">
                        <p>No Zoom accounts connected yet. Click "Connect Zoom Account" to add one.</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Token Expires</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $hosts as $host ): ?>
                            <tr>
                                <td><?php echo esc_html( $host['display_name'] ); ?></td>
                                <td><?php echo esc_html( $host['email'] ); ?></td>
                                <td>
                                    <?php
                                    $badge = match( $host['status'] ) {
                                        'active'       => '<span class="cias-badge green">Active</span>',
                                        'locked'       => '<span class="cias-badge amber">Locked</span>',
                                        'disconnected' => '<span class="cias-badge red">Disconnected</span>',
                                        default        => $host['status'],
                                    };
                                    echo $badge;
                                    ?>
                                </td>
                                <td><?php echo esc_html( $host['token_expires_at'] ); ?></td>
                                <td>
                                    <?php if ( $host['status'] === 'active' ): ?>
                                        <button class="button cias-lock-host" data-id="<?php echo (int) $host['id']; ?>">Lock</button>
                                    <?php elseif ( $host['status'] === 'locked' ): ?>
                                        <button class="button button-primary cias-unlock-host" data-id="<?php echo (int) $host['id']; ?>">Unlock</button>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( $connect_url ); ?>" class="button">Reconnect</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Config Checklist -->
            <div class="cias-card">
                <h2>Configuration Checklist</h2>
                <table class="wp-list-table widefat">
                    <tbody>
                        <?php
                        $checks = [
                            'CIAS_ZOOM_CLIENT_ID'      => 'Zoom OAuth Client ID',
                            'CIAS_ZOOM_CLIENT_SECRET'  => 'Zoom OAuth Client Secret',
                            'CIAS_ZOOM_WEBHOOK_SECRET' => 'Zoom Webhook Secret Token',
                            'CIAS_VIMEO_ACCESS_TOKEN'  => 'Vimeo Access Token',
                            'CIAS_VIMEO_DOMAIN_LOCK'   => 'Vimeo Domain Lock',
                            'CIAS_AISENSY_API_KEY'     => 'AiSensy API Key',
                            'CIAS_LIVE_ENCRYPTION_KEY' => 'Encryption Key for tokens',
                        ];
                        foreach ( $checks as $const => $label ):
                            $ok = defined( $const );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><?php echo $ok ? '<span class="cias-badge green">✓ Configured</span>' : '<span class="cias-badge red">✗ Missing — add to wp-config.php</span>'; ?></td>
                            <td><code><?php echo esc_html( $const ); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Webhook URL -->
            <div class="cias-card">
                <h2>Zoom Webhook URL</h2>
                <p>Add this URL to your Zoom App → Feature → Event Subscriptions:</p>
                <code style="display:block;padding:10px;background:#f0f4f8;border-radius:4px;font-size:13px;">
                    <?php echo esc_url( rest_url( CIAS_LIVE_API_NS . '/' . CIAS_LIVE_API_BASE . '/zoom-webhook' ) ); ?>
                </code>
                <p style="margin-top:8px;color:#666;font-size:13px;">
                    Required events: <strong>recording.completed</strong>, <strong>meeting.participant_joined</strong>, <strong>meeting.participant_left</strong>
                </p>
            </div>
        </div>
        <?php
    }
}
