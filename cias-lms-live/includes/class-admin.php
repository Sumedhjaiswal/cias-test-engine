<?php
namespace CIAS_LIVE;

defined( 'ABSPATH' ) || exit;

class Admin {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_notices', [ __CLASS__, 'show_notices' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'cias-lms-live' ) === false ) return;
        wp_enqueue_style(
            'cias-live-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/live-admin.css',
            [], '1.0.0'
        );
        wp_enqueue_script(
            'cias-live-admin',
            plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/live-admin.js',
            [ 'jquery' ], '1.0.0', true
        );
        $api_base = rest_url( CIAS_LIVE_API_NS . '/' . CIAS_LIVE_API_BASE );
        wp_localize_script( 'cias-live-admin', 'CIAS_LIVE', [
            'api_url' => $api_base,
            'apiBase' => $api_base,
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public static function register_menu(): void {
        add_menu_page(
            'CIAS LMS Live', 'LMS Live', 'manage_options',
            'cias-lms-live', [ __CLASS__, 'render_page' ],
            'dashicons-video-alt2', 30
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
        if ( ! defined( 'CIAS_ZOOM_CLIENT_ID' ) || ! defined( 'CIAS_ZOOM_CLIENT_SECRET' ) ) {
            echo '<div class="notice notice-warning"><p>⚠️ <strong>CIAS LMS Live:</strong> Add <code>CIAS_ZOOM_CLIENT_ID</code> and <code>CIAS_ZOOM_CLIENT_SECRET</code> to wp-config.php.</p></div>';
        }
    }

    public static function render_page(): void {
        $tab = sanitize_key( $_GET['tab'] ?? 'classes' );
        $connect_url = wp_nonce_url( admin_url( '?cias_zoom_connect=1' ), 'cias_zoom_connect' );
        $hosts = Services\ZoomHostPool::get_all_hosts();
        ?>
        <div class="wrap cias-live-admin">
            <h1>CIAS LMS Live</h1>

            <nav class="cias-tabs">
                <a href="?page=cias-lms-live&tab=classes"  class="cias-tab <?php echo $tab === 'classes'  ? 'active' : ''; ?>">📅 Live Classes</a>
                <a href="?page=cias-lms-live&tab=hosts"    class="cias-tab <?php echo $tab === 'hosts'    ? 'active' : ''; ?>">🎥 Zoom Accounts</a>
                <a href="?page=cias-lms-live&tab=settings" class="cias-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">⚙️ Settings</a>
            </nav>

            <?php if ( $tab === 'classes' ) : ?>
                <?php self::render_classes_tab(); ?>
            <?php elseif ( $tab === 'hosts' ) : ?>
                <?php self::render_hosts_tab( $hosts, $connect_url ); ?>
            <?php elseif ( $tab === 'settings' ) : ?>
                <?php self::render_settings_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Classes Tab ────────────────────────────────────────────────────────

    private static function render_classes_tab(): void {
        global $wpdb;
        $batches  = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}cias_batches WHERE status = 'active' ORDER BY name ASC" );
        $teachers = get_users( [ 'role__in' => [ 'administrator', 'cias_teacher', 'cias_content_manager' ], 'orderby' => 'display_name' ] );
        ?>
        <div class="cias-card">
            <div class="cias-card-header">
                <h2>Live Classes</h2>
                <button class="button button-primary" id="cias-add-class-btn">+ Schedule Class</button>
            </div>

            <!-- Filter bar -->
            <div class="cias-filter-bar">
                <select id="cias-filter-status">
                    <option value="">All Status</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="live">Live Now</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select id="cias-filter-batch">
                    <option value="">All Batches</option>
                    <?php foreach ( $batches as $b ) : ?>
                        <option value="<?php echo (int) $b->id; ?>"><?php echo esc_html( $b->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="cias-filter-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                <button class="button" id="cias-filter-btn">Filter</button>
                <button class="button" id="cias-filter-clear">Clear</button>
            </div>

            <!-- Classes grid -->
            <div id="cias-classes-grid" class="cias-classes-grid">
                <div class="cias-loading">Loading classes…</div>
            </div>
        </div>

        <!-- Schedule / Edit Modal -->
        <div id="cias-class-modal" class="cias-modal" style="display:none;">
            <div class="cias-modal-overlay"></div>
            <div class="cias-modal-box">
                <div class="cias-modal-header">
                    <h3 id="cias-modal-title">Schedule New Class</h3>
                    <button class="cias-modal-close">&times;</button>
                </div>
                <div class="cias-modal-body">
                    <input type="hidden" id="cias-class-id" value="">

                    <div class="cias-form-row">
                        <label>Topic Name <span class="required">*</span></label>
                        <input type="text" id="cias-field-title" placeholder="e.g. Polity: Fundamental Rights" required>
                    </div>

                    <div class="cias-form-row cias-form-row-2">
                        <div>
                            <label>Date <span class="required">*</span></label>
                            <input type="date" id="cias-field-date" required>
                        </div>
                        <div>
                            <label>From <span class="required">*</span></label>
                            <input type="time" id="cias-field-from" required>
                        </div>
                    </div>

                    <div class="cias-form-row cias-form-row-2">
                        <div>
                            <label>To <span class="required">*</span></label>
                            <input type="time" id="cias-field-to" required>
                        </div>
                        <div>
                            <label>Duration</label>
                            <input type="text" id="cias-field-duration" readonly placeholder="Auto-calculated">
                        </div>
                    </div>

                    <div class="cias-form-row">
                        <label>Teacher <span class="required">*</span></label>
                        <select id="cias-field-teacher">
                            <option value="">Select Teacher</option>
                            <?php foreach ( $teachers as $t ) : ?>
                                <option value="<?php echo (int) $t->ID; ?>"><?php echo esc_html( $t->display_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cias-form-row">
                        <label>Batch <span class="required">*</span></label>
                        <select id="cias-field-batch">
                            <option value="">Select Batch</option>
                            <?php foreach ( $batches as $b ) : ?>
                                <option value="<?php echo (int) $b->id; ?>"><?php echo esc_html( $b->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cias-form-row cias-checkboxes">
                        <label class="cias-checkbox-label">
                            <input type="checkbox" id="cias-field-recording" checked>
                            Auto Recording (Cloud)
                        </label>
                        <label class="cias-checkbox-label">
                            <input type="checkbox" id="cias-field-hostvideo" checked>
                            Host Video On
                        </label>
                        <label class="cias-checkbox-label">
                            <input type="checkbox" id="cias-field-mute" checked>
                            Mute Participants on Entry
                        </label>
                    </div>

                    <div id="cias-form-error" class="cias-form-error" style="display:none;"></div>
                </div>
                <div class="cias-modal-footer">
                    <button class="button" id="cias-modal-cancel-btn">Cancel</button>
                    <button class="button button-primary" id="cias-modal-submit-btn">
                        <span id="cias-submit-label">Schedule Class</span>
                        <span id="cias-submit-spinner" class="spinner" style="display:none;"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- View Details Modal -->
        <div id="cias-detail-modal" class="cias-modal" style="display:none;">
            <div class="cias-modal-overlay"></div>
            <div class="cias-modal-box cias-modal-sm">
                <div class="cias-modal-header">
                    <h3>Class Details</h3>
                    <button class="cias-modal-close">&times;</button>
                </div>
                <div class="cias-modal-body" id="cias-detail-content"></div>
            </div>
        </div>
        <?php
    }

    // ── Hosts Tab ──────────────────────────────────────────────────────────

    private static function render_hosts_tab( array $hosts, string $connect_url ): void {
        ?>
        <div class="cias-card">
            <div class="cias-card-header">
                <h2>Zoom Host Accounts</h2>
                <a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary">+ Connect Zoom Account</a>
            </div>
            <?php if ( empty( $hosts ) ) : ?>
                <div class="cias-empty"><p>No Zoom accounts connected yet.</p></div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Status</th><th>Token Expires</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $hosts as $host ) : ?>
                        <tr>
                            <td><?php echo esc_html( $host['display_name'] ); ?></td>
                            <td><?php echo esc_html( $host['email'] ); ?></td>
                            <td>
                                <?php
                                $badge = match( $host['status'] ) {
                                    'active'       => '<span class="cias-badge green">Active</span>',
                                    'locked'       => '<span class="cias-badge amber">In Use</span>',
                                    'disconnected' => '<span class="cias-badge red">Disconnected</span>',
                                    default        => $host['status'],
                                };
                                echo $badge;
                                ?>
                            </td>
                            <td><?php echo esc_html( $host['token_expires_at'] ); ?></td>
                            <td>
                                <?php if ( $host['status'] === 'active' ) : ?>
                                    <button class="button cias-lock-host" data-id="<?php echo (int) $host['id']; ?>">Lock</button>
                                <?php elseif ( $host['status'] === 'locked' ) : ?>
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
        <?php
    }

    // ── Settings Tab ───────────────────────────────────────────────────────

    private static function render_settings_tab(): void {
        ?>
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
                    foreach ( $checks as $const => $label ) :
                        $ok = defined( $const );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td><?php echo $ok ? '<span class="cias-badge green">✓ Configured</span>' : '<span class="cias-badge red">✗ Missing</span>'; ?></td>
                        <td><code><?php echo esc_html( $const ); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

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
        <?php
    }
}
