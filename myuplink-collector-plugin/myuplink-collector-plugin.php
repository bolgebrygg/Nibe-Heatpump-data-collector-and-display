<?php
/**
 * Plugin Name: MyUplink Data Collector
 * Plugin URI: https://example.com
 * Description: Clean data collector for MyUplink sensor points. Collects data every 30 minutes and stores in simple database table.
 * Version: 2.0.0
 * Author: peter
 * License: GPL v2 or later
 * Text Domain: myuplink-collector
 * 
 * FEATURES:
 * - Collects sensor data every 30 minutes via cron
 * - Stores values with 2 decimal precision in clean table structure
 * - Simple API authentication and data fetching
 * - No aggregation or display functionality (handled by separate display plugin)
 * - Easy configuration via WordPress admin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

// Plugin constants
define('MYUPLINK_COLLECTOR_VERSION', '2.0.0');
define('MYUPLINK_COLLECTOR_TABLE', 'myuplink_points'); // Use existing table name

/**
 * Main Plugin Installation and Activation Handler
 * Handles database table creation and plugin activation/deactivation
 */
class MyUplink_Collector_Install {
    // WordPress options keys for storing configuration
    const OPTION_CLIENT_ID = 'myuplink_client_id';
    const OPTION_CLIENT_SECRET = 'myuplink_client_secret';
    const OPTION_DEVICE_ID = 'myuplink_device_id';
    const OPTION_PARAMETERS = 'myuplink_parameter_ids';
    const OPTION_SECRET = 'myuplink_collector_secret'; // Secret key for direct REST trigger

    /**
     * Plugin activation - creates clean data table and sets defaults
     */
    public static function activate() {
        try {
            // Create simple data storage table
            self::create_table();
            
            // Set default parameter IDs if not configured
            if (!get_option(self::OPTION_PARAMETERS)) {
                update_option(self::OPTION_PARAMETERS, '4,8,10,11,12,13,14,54,781,1708,27335,28392,28393');
            }
            if (!get_option(self::OPTION_SECRET)) {
                update_option(self::OPTION_SECRET, wp_generate_password(32, false, false));
            }
            
            // Schedule data collection cron job
            MyUplink_Collector_Cron::schedule_events();
            
        } catch (Exception $e) {
            error_log('MyUplink Collector activation failed: ' . $e->getMessage());
        }
    }

    /**
     * Plugin deactivation - cleans up cron jobs
     */
    public static function deactivate() {
        MyUplink_Collector_Cron::unschedule_events();
    }

    /**
     * Create simple data table with clean structure
     * No aggregation tables - just raw data storage
     */
    public static function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . MYUPLINK_COLLECTOR_TABLE;
        
        // Use existing table structure to maintain compatibility
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ts` DATETIME NOT NULL,
            `device_id` VARCHAR(64) NOT NULL,
            `parameter_id` BIGINT NOT NULL,
            `parameter_name` VARCHAR(191) DEFAULT '' NOT NULL,
            `parameter_unit` VARCHAR(64) DEFAULT '' NOT NULL,
            `value` DECIMAL(20,2) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_idx` (`device_id`, `parameter_id`, `ts`),
            KEY `ts_idx` (`ts`),
            KEY `param_idx` (`parameter_id`)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Ensure decimal precision is correct
        $wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN `value` DECIMAL(20,2) NULL");
    }
}

/**
 * MyUplink API Communication Handler
 * Handles all communication with MyUplink API
 */
class MyUplink_Collector_API {
    private $client_id;
    private $client_secret;
    private $base_url = 'https://api.myuplink.com';
    private $token = null;
    private $token_expires_at = 0;

    /**
     * Initialize API handler with credentials
     */
    public function __construct($client_id = null, $client_secret = null) {
        $this->client_id = $client_id ?: trim(get_option(MyUplink_Collector_Install::OPTION_CLIENT_ID, ''));
        $this->client_secret = $client_secret ?: trim(get_option(MyUplink_Collector_Install::OPTION_CLIENT_SECRET, ''));
    }

    /**
     * Check if API credentials are properly configured
     */
    public function has_credentials() {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    /**
     * Get OAuth2 access token with caching to avoid excessive API calls
     */
    private function get_token() {
        // Return cached token if still valid (with 60 second buffer)
        if ($this->token && time() < $this->token_expires_at - 60) {
            return $this->token;
        }

        $url = $this->base_url . '/oauth/token';
        $body = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $body,
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Token request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400 || empty($data['access_token'])) {
            throw new Exception('Token request failed: HTTP ' . $code);
        }

        $this->token = $data['access_token'];
        $this->token_expires_at = time() + (int)($data['expires_in'] ?? 3600);
        
        return $this->token;
    }

    /**
     * Make authenticated API GET request
     */
    private function api_get($path, $query = []) {
        $token = $this->get_token();
        $url = add_query_arg($query, $this->base_url . $path);
        
        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            throw new Exception('API request failed: HTTP ' . $code . ' - ' . $body);
        }

        return json_decode($body, true);
    }

    /**
     * Resolve device ID from systems/me endpoint and cache it
     */
    public function resolve_device_id() {
        $cached = get_option(MyUplink_Collector_Install::OPTION_DEVICE_ID);
        if (!empty($cached)) {
            return $cached;
        }

        $data = $this->api_get('/v2/systems/me');
        
        if (!isset($data['systems'][0]['devices'][0]['id'])) {
            throw new Exception('No device found in systems response');
        }

        $device_id = $data['systems'][0]['devices'][0]['id'];
        update_option(MyUplink_Collector_Install::OPTION_DEVICE_ID, $device_id);
        
        return $device_id;
    }

    /**
     * Fetch current sensor points with metadata for specified parameters
     */
    public function fetch_current_points($device_id, $parameter_ids = []) {
        $query = ['include' => 'metadata'];
        
        // Try v3 API first, fallback to v2 if needed
        $data = null;
        try {
            $data = $this->api_get('/v3/devices/' . rawurlencode($device_id) . '/points', $query);
        } catch (Exception $e) {
            $data = $this->api_get('/v2/devices/' . rawurlencode($device_id) . '/points', $query);
        }

        if (!is_array($data)) {
            return [];
        }

        $points = $data['data'] ?? $data;
        if (!is_array($points)) {
            return [];
        }

        // Filter and clean the data for storage
        $filter_set = [];
        foreach ($parameter_ids as $pid) {
            $filter_set[(string)intval($pid)] = true;
        }

        $result = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $pid = $point['parameterId'] ?? $point['id'] ?? null;
            if (!$pid) {
                continue;
            }

            $pid_str = (string)intval($pid);
            if (!empty($filter_set) && !isset($filter_set[$pid_str])) {
                continue;
            }

            // Extract metadata and values
            $metadata = $point['metadata'] ?? [];
            $name = $metadata['name'] ?? $point['parameterName'] ?? $point['name'] ?? '';
            $unit = $metadata['unit'] ?? $point['parameterUnit'] ?? $point['unit'] ?? '';
            $value = $point['value'] ?? $point['rawValue'] ?? null;
            $timestamp = $point['timestamp'] ?? $point['sampleTime'] ?? null;

            $result[] = [
                'parameter_id' => (int)$pid,
                'parameter_name' => (string)$name,
                'parameter_unit' => (string)$unit,
                'value' => is_numeric($value) ? (float)$value : null,
                'timestamp' => $timestamp ? gmdate('Y-m-d H:i:s', strtotime($timestamp)) : current_time('mysql', 1)
            ];
        }

        return $result;
    }
}

/**
 * Cron Job Handler for Data Collection
 * Handles scheduled data collection every 30 minutes
 */
class MyUplink_Collector_Cron {
    const CRON_HOOK = 'myuplink_collector_data_fetch';
    const INTERVAL_KEY = 'every_30_minutes';

    // Acquire transient lock to avoid overlapping runs
    private static function acquire_lock() {
        if (get_transient('myuplink_collector_running')) {
            return false;
        }
        set_transient('myuplink_collector_running', 1, 10 * MINUTE_IN_SECONDS);
        return true;
    }

    private static function release_lock() {
        delete_transient('myuplink_collector_running');
    }

    // Core logic separated for reuse (returns meta array)
    private static function run_collection_logic() {
        $api = new MyUplink_Collector_API();
        if (!$api->has_credentials()) {
            error_log('MyUplink Collector: No API credentials configured');
            return ['success' => false, 'reason' => 'no_credentials'];
        }
        $device_id = $api->resolve_device_id();
        error_log('MyUplink Collector: Using device ID: ' . $device_id);
        $param_csv = get_option(MyUplink_Collector_Install::OPTION_PARAMETERS, '');
        $parameter_ids = array_filter(array_map('intval', explode(',', $param_csv)));
        if (empty($parameter_ids)) {
            error_log('MyUplink Collector: No parameters configured for collection');
            return ['success' => false, 'reason' => 'no_parameters'];
        }
        error_log('MyUplink Collector: Collecting parameters: ' . implode(',', $parameter_ids));
        $points = $api->fetch_current_points($device_id, $parameter_ids);
        error_log('MyUplink Collector: Received ' . count($points) . ' data points from API');
        self::store_raw_data($device_id, $points);
        return [ 'success' => true, 'points' => count($points), 'device_id' => $device_id ];
    }

    /**
     * Initialize cron functionality
     */
    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'add_intervals']);
        add_action(self::CRON_HOOK, [__CLASS__, 'collect_data']);
        
        // Add debug logging hook
        add_action('init', [__CLASS__, 'debug_cron_status']);
    }

    /**
     * Add custom 30-minute cron interval
     */
    public static function add_intervals($schedules) {
        $schedules[self::INTERVAL_KEY] = [
            'interval' => 30 * 60, // 30 minutes in seconds
            'display' => __('Every 30 Minutes (MyUplink)', 'myuplink-collector')
        ];
        return $schedules;
    }

    /**
     * Debug cron status - logs cron status periodically
     */
    public static function debug_cron_status() {
        // Only run this debug check occasionally (not on every page load)
        if (rand(1, 100) === 1) { // 1% chance per page load
            $next_run = wp_next_scheduled(self::CRON_HOOK);
            if ($next_run) {
                $time_until = $next_run - time();
                error_log('MyUplink Collector DEBUG: Next cron run in ' . $time_until . ' seconds (' . date('Y-m-d H:i:s', $next_run) . ')');
            } else {
                error_log('MyUplink Collector DEBUG: No cron job scheduled!');
            }
        }
    }

    /**
     * Schedule the data collection cron job
     */
    public static function schedule_events() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Start in 60 seconds to avoid immediate execution on activation
            $scheduled = wp_schedule_event(time() + 60, self::INTERVAL_KEY, self::CRON_HOOK);
            
            if ($scheduled === false) {
                error_log('MyUplink Collector: Failed to schedule cron event');
            } else {
                error_log('MyUplink Collector: Cron event scheduled successfully for ' . date('Y-m-d H:i:s', time() + 60));
            }
        } else {
            $next_run = wp_next_scheduled(self::CRON_HOOK);
            error_log('MyUplink Collector: Cron already scheduled for ' . date('Y-m-d H:i:s', $next_run));
        }
    }

    /**
     * Remove scheduled cron events
     */
    public static function unschedule_events() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Main data collection function - runs every 30 minutes
     */
    public static function collect_data() {
        if (!self::acquire_lock()) {
            error_log('MyUplink Collector: Skipping run (already in progress)');
            return ['success' => false, 'reason' => 'locked'];
        }
        $start_time = time();
        error_log('MyUplink Collector: Starting data collection at ' . date('Y-m-d H:i:s', $start_time));
        $meta = null;
        try {
            $meta = self::run_collection_logic();
            $end_time = time();
            $duration = $end_time - $start_time;
            if (!empty($meta['success'])) {
                update_option('myuplink_collector_last_run', time());
                error_log('MyUplink Collector: Successfully collected ' . ($meta['points'] ?? 0) . ' data points in ' . $duration . ' seconds');
            } else {
                error_log('MyUplink Collector: Collection finished without success (reason: ' . ($meta['reason'] ?? 'unknown') . ')');
            }
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                error_log('MyUplink Collector: WARNING - No next run scheduled, rescheduling...');
                wp_schedule_event(time() + (30 * 60), self::INTERVAL_KEY, self::CRON_HOOK);
            }
        } catch (Exception $e) {
            $end_time = time();
            $duration = $end_time - $start_time;
            error_log('MyUplink Collector cron failed after ' . $duration . ' seconds: ' . $e->getMessage());
            error_log('MyUplink Collector cron stack trace: ' . $e->getTraceAsString());
            $meta = ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        } finally {
            self::release_lock();
        }
        return $meta;
    }

    /**
     * Store test data (public method for admin testing)
     */
    public static function store_test_data($device_id, $points) {
        self::store_raw_data($device_id, $points);
    }

    /**
     * Store raw sensor data in the database table
     */
    private static function store_raw_data($device_id, $points) {
        global $wpdb;
        
        $table = $wpdb->prefix . MYUPLINK_COLLECTOR_TABLE;
        
        foreach ($points as $point) {
            try {
                $data = [
                    'ts' => $point['timestamp'], // Use existing column name 'ts'
                    'device_id' => (string)$device_id,
                    'parameter_id' => (int)$point['parameter_id'],
                    'parameter_name' => (string)$point['parameter_name'],
                    'parameter_unit' => (string)$point['parameter_unit'],
                    'value' => $point['value'] !== null ? round((float)$point['value'], 2) : null
                ];
                
                $format = ['%s', '%s', '%d', '%s', '%s'];
                $format[] = ($data['value'] !== null) ? '%f' : null;
                
                // Use REPLACE to handle duplicate timestamps gracefully
                $wpdb->replace($table, $data, $format);
                
            } catch (Exception $e) {
                error_log('MyUplink Collector: Failed to store parameter ' . $point['parameter_id'] . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * Admin Interface for Configuration
 * Simple admin interface for configuring API credentials and parameters
 */
class MyUplink_Collector_Admin {
    
    /**
     * Initialize admin functionality
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'handle_test_connection']);
    }

    /**
     * Register WordPress settings for the plugin options
     */
    public static function register_settings() {
        $settings = [
            MyUplink_Collector_Install::OPTION_CLIENT_ID => 'sanitize_text_field',
            MyUplink_Collector_Install::OPTION_CLIENT_SECRET => 'sanitize_text_field',
            MyUplink_Collector_Install::OPTION_PARAMETERS => [__CLASS__, 'sanitize_param_ids']
        ];

        foreach ($settings as $option => $sanitize_callback) {
            register_setting('myuplink_collector', $option, [
                'sanitize_callback' => $sanitize_callback
            ]);
        }

        // API Configuration section
        add_settings_section(
            'myuplink_api_section',
            __('API Configuration', 'myuplink-collector'),
            [__CLASS__, 'render_api_section'],
            'myuplink_collector'
        );

        // Individual setting fields
        add_settings_field('client_id', __('Client ID', 'myuplink-collector'), 
            [__CLASS__, 'render_client_id'], 'myuplink_collector', 'myuplink_api_section');
        add_settings_field('client_secret', __('Client Secret', 'myuplink-collector'), 
            [__CLASS__, 'render_client_secret'], 'myuplink_collector', 'myuplink_api_section');
        add_settings_field('parameter_ids', __('Parameter IDs (comma-separated)', 'myuplink-collector'), 
            [__CLASS__, 'render_parameter_ids'], 'myuplink_collector', 'myuplink_api_section');
    }

    /**
     * Handle test connection and manual data fetch requests
     */
    public static function handle_test_connection() {
        // Secret regeneration
        if (isset($_POST['myuplink_regen_secret']) && current_user_can('manage_options')) {
            if (isset($_POST['myuplink_regen_nonce']) && wp_verify_nonce($_POST['myuplink_regen_nonce'], 'myuplink_regen_secret')) {
                update_option(MyUplink_Collector_Install::OPTION_SECRET, wp_generate_password(32, false, false));
                add_settings_error('myuplink_collector', 'secret_regenerated', 'Secret regenerated.', 'updated');
            } else {
                add_settings_error('myuplink_collector', 'secret_regen_fail', 'Secret regeneration security check failed.', 'error');
            }
        }

        // Check if test connection was requested
        if (!isset($_POST['myuplink_test_connection']) || !current_user_can('manage_options')) {
            return;
        }

        // Verify nonce for security
        if (!isset($_POST['myuplink_test_nonce']) || !wp_verify_nonce($_POST['myuplink_test_nonce'], 'myuplink_test_connection')) {
            add_settings_error('myuplink_collector', 'invalid_nonce', 'Security check failed.', 'error');
            return;
        }

        try {
            // Test API connection and fetch data
            $api = new MyUplink_Collector_API();
            
            if (!$api->has_credentials()) {
                add_settings_error('myuplink_collector', 'no_credentials', 'Please configure API credentials first.', 'error');
                return;
            }

            // Test connection by getting device ID
            $device_id = $api->resolve_device_id();
            
            // Get configured parameters
            $param_csv = get_option(MyUplink_Collector_Install::OPTION_PARAMETERS, '');
            $parameter_ids = array_filter(array_map('intval', explode(',', $param_csv)));

            if (empty($parameter_ids)) {
                add_settings_error('myuplink_collector', 'no_parameters', 'Please configure parameter IDs first.', 'error');
                return;
            }

            // Fetch current data points
            $points = $api->fetch_current_points($device_id, $parameter_ids);
            
            if (empty($points)) {
                add_settings_error('myuplink_collector', 'no_data', 'Connection successful but no data returned. Check your parameter IDs.', 'updated');
                return;
            }

            // Store the fetched data
            MyUplink_Collector_Cron::store_test_data($device_id, $points);
            
            $message = sprintf(
                'Connection successful! Retrieved and stored %d data points from device %s.',
                count($points),
                $device_id
            );
            add_settings_error('myuplink_collector', 'test_success', $message, 'updated');
            
        } catch (Exception $e) {
            add_settings_error('myuplink_collector', 'connection_failed', 'Connection failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Add admin menu page
     */
    public static function add_menu() {
        add_options_page(
            __('MyUplink Collector', 'myuplink-collector'),
            __('MyUplink Collector', 'myuplink-collector'),
            'manage_options',
            'myuplink_collector',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Render the main admin configuration page
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MyUplink Data Collector Settings', 'myuplink-collector'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('myuplink_collector');
                do_settings_sections('myuplink_collector');
                submit_button();
                ?>
            </form>

            <div class="myuplink-test-section">
                <h2><?php esc_html_e('Connection Test & Manual Data Fetch', 'myuplink-collector'); ?></h2>
                <p><?php esc_html_e('Test your API connection and manually fetch the latest data from MyUplink.', 'myuplink-collector'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('myuplink_test_connection', 'myuplink_test_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('API Connection Test', 'myuplink-collector'); ?></th>
                            <td>
                                <input type="submit" name="myuplink_test_connection" class="button button-secondary" 
                                       value="<?php esc_attr_e('Test Connection & Fetch Data', 'myuplink-collector'); ?>" />
                                <p class="description">
                                    <?php esc_html_e('This will test your API credentials, fetch the latest sensor data, and store it in the database.', 'myuplink-collector'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </form>
                
                <div class="test-results">
                    <?php settings_errors('myuplink_collector'); ?>
                </div>
            </div>

            <div class="myuplink-info-section">
                <h2><?php esc_html_e('Data Collection Status', 'myuplink-collector'); ?></h2>
                <?php self::show_collection_status(); ?>
                <h2><?php esc_html_e('Direct REST Trigger', 'myuplink-collector'); ?></h2>
                <?php $secret = get_option(MyUplink_Collector_Install::OPTION_SECRET); $rest_url = esc_url( get_rest_url(null, 'myuplink-collector/v1/collect') ); ?>
                <p><?php esc_html_e('Trigger immediate data collection via POST request with the secret key. For security keep this secret private.', 'myuplink-collector'); ?></p>
                <table class="widefat striped">
                    <tr>
                        <th><?php esc_html_e('REST Endpoint', 'myuplink-collector'); ?></th>
                        <td><code><?php echo $rest_url; ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Secret Key', 'myuplink-collector'); ?></th>
                        <td><code><?php echo esc_html($secret); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('cURL Example (Header)', 'myuplink-collector'); ?></th>
                        <td><code>curl -X POST -H "X-MyUplink-Secret: <?php echo esc_html($secret); ?>" <?php echo $rest_url; ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('cURL Example (Query Param)', 'myuplink-collector'); ?></th>
                        <td><code>curl -X POST "<?php echo $rest_url; ?>?key=<?php echo esc_html($secret); ?>"</code></td>
                    </tr>
                </table>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('myuplink_regen_secret','myuplink_regen_nonce'); ?>
                    <input type="submit" class="button button-secondary" name="myuplink_regen_secret" value="<?php esc_attr_e('Regenerate Secret', 'myuplink-collector'); ?>" onclick="return confirm('<?php esc_attr_e('Regenerate secret? Update external cron immediately.', 'myuplink-collector'); ?>');" />
                </form>
                
                <h2><?php esc_html_e('Database Information', 'myuplink-collector'); ?></h2>
                <?php self::show_database_info(); ?>
                
                <h2><?php esc_html_e('Table Structure', 'myuplink-collector'); ?></h2>
                <?php self::show_table_structure(); ?>
                
                <h2><?php esc_html_e('Recent Data (Last 20 Records)', 'myuplink-collector'); ?></h2>
                <?php self::show_recent_data(); ?>
            </div>
        </div>
        
        <style>
        .myuplink-test-section {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .myuplink-test-section h2 {
            margin-top: 0;
            color: #23282d;
        }
        .test-results .notice {
            margin: 10px 0;
        }
        .myuplink-info-section {
            margin-top: 20px;
        }
        .table-structure-container, .recent-data-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            margin: 10px 0;
        }
        .table-structure-container table, .recent-data-container table {
            margin: 0;
        }
        .table-structure-container th, .recent-data-container th {
            position: sticky;
            top: 0;
            background: #f9f9f9;
            z-index: 1;
        }
        .recent-data-container td, .recent-data-container th {
            white-space: nowrap;
            padding: 8px 12px;
        }
        </style>
        <?php
    }

    /**
     * Show current data collection status
     */
    private static function show_collection_status() {
        $next_run = wp_next_scheduled(MyUplink_Collector_Cron::CRON_HOOK);
        $last_run_ts = get_option('myuplink_collector_last_run');
        ?>
        <table class="widefat striped">
            <tr>
                <th><?php esc_html_e('Collection Status', 'myuplink-collector'); ?></th>
                <td><?php echo $next_run ? esc_html__('Active', 'myuplink-collector') : esc_html__('Not Scheduled', 'myuplink-collector'); ?></td>
            </tr>
            <?php if ($next_run): ?>
            <tr>
                <th><?php esc_html_e('Next Collection', 'myuplink-collector'); ?></th>
                <td><?php echo esc_html(date('Y-m-d H:i:s', $next_run)); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e('Last Successful Run', 'myuplink-collector'); ?></th>
                <td><?php echo $last_run_ts ? esc_html(date('Y-m-d H:i:s', (int)$last_run_ts)) : esc_html__('Never', 'myuplink-collector'); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Show database statistics
     */
    private static function show_database_info() {
        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_COLLECTOR_TABLE;
        
        $total_records = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $latest_record = $wpdb->get_var("SELECT MAX(ts) FROM `{$table}`");
        $parameter_count = $wpdb->get_var("SELECT COUNT(DISTINCT parameter_id) FROM `{$table}`");
        
        ?>
        <table class="widefat striped">
            <tr>
                <th><?php esc_html_e('Total Records', 'myuplink-collector'); ?></th>
                <td><?php echo esc_html(number_format($total_records)); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Latest Data', 'myuplink-collector'); ?></th>
                <td><?php echo esc_html($latest_record ?: 'No data yet'); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Parameters Tracked', 'myuplink-collector'); ?></th>
                <td><?php echo esc_html($parameter_count); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Show complete table structure with column details
     */
    private static function show_table_structure() {
        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_COLLECTOR_TABLE;
        
        // Get table structure using DESCRIBE
        $columns = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        
        if (empty($columns)) {
            echo '<p>Table does not exist yet. Activate the plugin to create it.</p>';
            return;
        }
        
        ?>
        <div class="table-structure-container" style="overflow-x: auto;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Column Name', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Data Type', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Null', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Key', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Default', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Extra', 'myuplink-collector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($columns as $column): ?>
                    <tr>
                        <td><strong><?php echo esc_html($column['Field']); ?></strong></td>
                        <td><?php echo esc_html($column['Type']); ?></td>
                        <td><?php echo esc_html($column['Null']); ?></td>
                        <td><?php echo esc_html($column['Key']); ?></td>
                        <td><?php echo esc_html($column['Default'] ?: 'NULL'); ?></td>
                        <td><?php echo esc_html($column['Extra']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php
        // Show indexes
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (!empty($indexes)) {
            ?>
            <h3><?php esc_html_e('Table Indexes', 'myuplink-collector'); ?></h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Key Name', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Column', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Unique', 'myuplink-collector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($indexes as $index): ?>
                    <tr>
                        <td><?php echo esc_html($index['Key_name']); ?></td>
                        <td><?php echo esc_html($index['Column_name']); ?></td>
                        <td><?php echo $index['Non_unique'] == 0 ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
    }

    /**
     * Show recent data from the table
     */
    private static function show_recent_data() {
        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_COLLECTOR_TABLE;
        
        // Get the 20 most recent records
        $recent_data = $wpdb->get_results("
            SELECT * FROM `{$table}` 
            ORDER BY ts DESC, id DESC 
            LIMIT 20
        ", ARRAY_A);
        
        if (empty($recent_data)) {
            echo '<p>No data in table yet. Use the "Test Connection & Fetch Data" button above to add some data.</p>';
            return;
        }
        
        ?>
        <div class="recent-data-container" style="overflow-x: auto;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Timestamp', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Device ID', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Parameter ID', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Parameter Name', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Value', 'myuplink-collector'); ?></th>
                        <th><?php esc_html_e('Unit', 'myuplink-collector'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_data as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['id']); ?></td>
                        <td><?php echo esc_html($row['ts']); ?></td>
                        <td><?php echo esc_html($row['device_id']); ?></td>
                        <td><?php echo esc_html($row['parameter_id']); ?></td>
                        <td><?php echo esc_html($row['parameter_name'] ?: '—'); ?></td>
                        <td><?php echo esc_html($row['value'] !== null ? $row['value'] : '—'); ?></td>
                        <td><?php echo esc_html($row['parameter_unit'] ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <p><em><?php esc_html_e('Showing most recent records. Total records: ', 'myuplink-collector'); ?><?php echo esc_html(number_format($wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"))); ?></em></p>
        <?php
    }

    // Form field renderers
    public static function render_api_section() {
        echo '<p>' . esc_html__('Configure your MyUplink API credentials for data collection.', 'myuplink-collector') . '</p>';
    }

    public static function render_client_id() {
        $value = get_option(MyUplink_Collector_Install::OPTION_CLIENT_ID, '');
        echo '<input type="text" class="regular-text" name="' . esc_attr(MyUplink_Collector_Install::OPTION_CLIENT_ID) . '" value="' . esc_attr($value) . '" />';
        echo '<p class="description">' . esc_html__('Your MyUplink API Client ID', 'myuplink-collector') . '</p>';
    }

    public static function render_client_secret() {
        $value = get_option(MyUplink_Collector_Install::OPTION_CLIENT_SECRET, '');
        echo '<input type="password" class="regular-text" name="' . esc_attr(MyUplink_Collector_Install::OPTION_CLIENT_SECRET) . '" value="' . esc_attr($value) . '" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__('Your MyUplink API Client Secret', 'myuplink-collector') . '</p>';
    }

    public static function render_parameter_ids() {
        $value = get_option(MyUplink_Collector_Install::OPTION_PARAMETERS, '');
        echo '<input type="text" class="regular-text" name="' . esc_attr(MyUplink_Collector_Install::OPTION_PARAMETERS) . '" value="' . esc_attr($value) . '" />';
        echo '<p class="description">' . esc_html__('Comma-separated list of parameter IDs to collect (e.g., 13,14,781)', 'myuplink-collector') . '</p>';
    }

    /**
     * Sanitize parameter IDs - ensure only valid integers
     */
    public static function sanitize_param_ids($value) {
        $parts = explode(',', (string)$value);
        $clean_parts = [];
        
        foreach ($parts as $part) {
            $clean = trim($part);
            if ($clean !== '' && is_numeric($clean)) {
                $clean_parts[] = intval($clean);
            }
        }
        
        return implode(',', array_unique($clean_parts));
    }
}

// Register cron schedules early - before WordPress tries to use them
add_filter('cron_schedules', function($schedules) {
    $schedules['every_30_minutes'] = [
        'interval' => 30 * 60, // 30 minutes in seconds
        'display' => __('Every 30 Minutes (MyUplink)', 'myuplink-collector')
    ];
    return $schedules;
});

// REST API endpoint registration for direct trigger (POST)
add_action('rest_api_init', function() {
    register_rest_route('myuplink-collector/v1', '/collect', [
        'methods' => 'POST',
        'callback' => function($request) {
            $provided = $request->get_param('key');
            if (!$provided) {
                $provided = $request->get_header('x-myuplink-secret');
            }
            $secret = get_option(MyUplink_Collector_Install::OPTION_SECRET);
            if (!$secret || !$provided || !hash_equals($secret, $provided)) {
                return new WP_REST_Response(['error' => 'Unauthorized'], 401);
            }
            $meta = MyUplink_Collector_Cron::collect_data();
            $status = (isset($meta['success']) && $meta['success']) ? 200 : 500;
            return new WP_REST_Response([
                'ok' => !empty($meta['success']),
                'reason' => $meta['reason'] ?? null,
                'points' => $meta['points'] ?? 0,
                'device_id' => $meta['device_id'] ?? null,
                'timestamp' => current_time('mysql'),
            ], $status);
        },
        'permission_callback' => '__return_true',
    ]);
});

// Initialize plugin when WordPress loads
add_action('plugins_loaded', function() {
    // Ensure database table exists
    MyUplink_Collector_Install::create_table();
    
    // Initialize all components
    MyUplink_Collector_Cron::init();
    MyUplink_Collector_Admin::init();
    
    // Load translations
    load_plugin_textdomain('myuplink-collector', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Plugin activation and deactivation hooks
register_activation_hook(__FILE__, ['MyUplink_Collector_Install', 'activate']);
register_deactivation_hook(__FILE__, ['MyUplink_Collector_Install', 'deactivate']);