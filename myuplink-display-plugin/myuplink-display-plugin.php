<?php
/**
 * Plugin Name: MyUplink Data Display
 * Plugin URI: https://example.com
 * Description: Advanced data visualization for MyUplink sensor data with flexible shortcodes, multiple time periods, and customizable charts.
 * Version: 2.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: myuplink-display
 * 
 * FEATURES:
 * - Multiple time period views: 1 day, 7 days, 1 month, yearly
 * - Customizable chart colors via shortcode parameters
 * - Parameter selection through shortcodes
 * - Lightweight SVG pivot chart shortcode (no external chart libraries)
 * - Data aggregation for different time periods
 * - REST API for chart data with optional security
 * - Responsive charts with hover tooltips
 * 
 * SHORTCODE EXAMPLES:
 * [myuplink_chart parameter_ids="13,14" period="1day" colors="#ff0000,#00ff00"]
 * [myuplink_chart parameter_ids="781" period="7days" height="400"]
 * [myuplink_chart parameter_ids="4,8,10" period="1month"]
 * [myuplink_chart parameter_ids="13" period="yearly" colors="#1f77b4"]
 *
 * NEW PIVOT CHART ENERGY EXAMPLES:
 * [myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy & Temps" energy_pair="28392,28393"]
 * [myuplink_pivot_chart parameter_ids="28392,28393" interval="day" limit="14" height="320" padding_bottom="110" title="14 Day Energy Ratio" energy_pair="28392,28393"]
 * [myuplink_pivot_chart parameter_ids="4,13,14" interval="day" limit="30" height="380" padding_bottom="120" title="30 Day Energy Deltas Only" energy_pair="28392,28393"]
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not allowed.');
}

// Plugin constants
define('MYUPLINK_DISPLAY_VERSION', '2.0.0');
define('MYUPLINK_RAW_DATA_TABLE', 'myuplink_points'); // Use existing table from original plugin

/**
 * Plugin Installation and Setup Handler
 */
class MyUplink_Display_Install {
    // WordPress options for display settings
    const OPTION_REST_KEY = 'myuplink_display_rest_key';
    const OPTION_DEFAULT_COLORS = 'myuplink_display_default_colors';
    const OPTION_DEFAULT_HEIGHT = 'myuplink_display_default_height';

    /**
     * Plugin activation - set default display options
     */
    public static function activate() {
        try {
            // Set default display options
            $defaults = [
                self::OPTION_REST_KEY => '',
                self::OPTION_DEFAULT_COLORS => '#1f77b4,#ff7f0e,#2ca02c,#d62728,#9467bd,#8c564b,#17becf,#bcbd22',
                self::OPTION_DEFAULT_HEIGHT => 350
            ];
            
            foreach ($defaults as $option => $default_value) {
                if (get_option($option) === false) {
                    update_option($option, $default_value);
                }
            }
            
        } catch (Exception $e) {
            error_log('MyUplink Display activation failed: ' . $e->getMessage());
        }
    }

    /**
     * Plugin deactivation cleanup
     */
    public static function deactivate() {
        // No cleanup needed for display plugin
    }
}

/**
 * Data Query Handler
 * Handles querying and aggregating data from the collector plugin's table
 */
class MyUplink_Display_Data {
    
    /**
     * Get aggregated data for specified parameters and time period
     */
    public static function get_chart_data($parameter_ids, $period, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;
        
        // Validate parameter IDs
        $parameter_ids = array_filter(array_map('absint', $parameter_ids));
        if (empty($parameter_ids)) {
            return [];
        }
        
        // Calculate time range and aggregation interval
        $time_config = self::get_time_configuration($period, $start_date, $end_date);
        
        $placeholders = implode(',', array_fill(0, count($parameter_ids), '%d'));
        
        // Build aggregation query based on time period
        $sql = $wpdb->prepare("
            SELECT 
                parameter_id,
                parameter_name,
                parameter_unit,
                {$time_config['time_grouping']} as time_bucket,
                AVG(value) as avg_value,
                MIN(value) as min_value,
                MAX(value) as max_value,
                COUNT(value) as data_points
            FROM `{$table}`
            WHERE parameter_id IN ({$placeholders})
            AND ts >= %s 
            AND ts <= %s
            AND value IS NOT NULL
            GROUP BY parameter_id, time_bucket
            ORDER BY parameter_id, time_bucket
        ", array_merge($parameter_ids, [$time_config['start'], $time_config['end']]));

        $rows = $wpdb->get_results($sql, ARRAY_A);
        
        return self::format_chart_data($rows, $time_config);
    }

    /**
     * Get raw (unaggregated) points for quick debugging / test output
     * Returns array of [ 'parameter_id'=>, 'ts'=>, 'value'=> ] ordered ascending by ts.
     */
    public static function get_raw_points($parameter_ids, $hours = 24) {
        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;
        $parameter_ids = array_filter(array_map('absint', (array)$parameter_ids));
        if (empty($parameter_ids)) return [];
        $placeholders = implode(',', array_fill(0, count($parameter_ids), '%d'));
        $start = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $sql = $wpdb->prepare(
            "SELECT parameter_id, ts, value FROM `{$table}`
             WHERE parameter_id IN ($placeholders)
               AND ts >= %s AND value IS NOT NULL
             ORDER BY ts ASC",
            array_merge($parameter_ids, [$start])
        );
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Configure time ranges and aggregation intervals for different periods
     */
    private static function get_time_configuration($period, $start_date = null, $end_date = null) {
        $now = current_time('mysql', 1); // GMT time
        
        // Handle custom date ranges
        if ($start_date && $end_date) {
            return [
                'start' => $start_date,
                'end' => $end_date,
                'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')", // Hourly
                'interval_minutes' => 60
            ];
        }
        
        // Predefined periods with appropriate aggregation
        switch ($period) {
            case '1day':
            case 'day':
            case '24h':
                return [
                    'start' => gmdate('Y-m-d H:i:s', strtotime('-24 hours')),
                    'end' => $now,
                    'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-%d %H:%i:00')", // Every 5 minutes
                    'interval_minutes' => 5
                ];
                
            case '7days':
            case 'week':
            case '7d':
                return [
                    'start' => gmdate('Y-m-d H:i:s', strtotime('-7 days')),
                    'end' => $now,
                    'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')", // Hourly
                    'interval_minutes' => 60
                ];
                
            case '1month':
            case 'month':
            case '30d':
                return [
                    'start' => gmdate('Y-m-d H:i:s', strtotime('-30 days')),
                    'end' => $now,
                    'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-%d 00:00:00')", // Daily
                    'interval_minutes' => 1440 // 24 hours
                ];
                
            case 'yearly':
            case 'year':
            case '365d':
                return [
                    'start' => gmdate('Y-m-d H:i:s', strtotime('-365 days')),
                    'end' => $now,
                    'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-01 00:00:00')", // Monthly
                    'interval_minutes' => 43200 // 30 days
                ];
                
            default:
                // Default to 1 day
                return [
                    'start' => gmdate('Y-m-d H:i:s', strtotime('-24 hours')),
                    'end' => $now,
                    'time_grouping' => "DATE_FORMAT(ts, '%Y-%m-%d %H:%i:00')",
                    'interval_minutes' => 5
                ];
        }
    }

    /**
     * Convert interval string to bucket seconds, aligned now bucket, and time format.
     * Supports: 'minute', 'hour', 'day', '30', '30min', etc.
     */
    public static function interval_to_bucket($interval, $now_ts = null) {
        if ($now_ts === null) $now_ts = time();
        $i = strtolower(trim($interval));

        // If numeric minutes provided (e.g., '30') or ending with 'min'
        if (preg_match('/^(\d+)(?:min)?$/', $i, $m)) {
            $mins = max(1, intval($m[1]));
            $bucket_seconds = $mins * 60;
            $now_bucket = $now_ts - ($now_ts % $bucket_seconds);
            $time_format = ($mins >= 60 && $mins % 60 === 0) ? 'Y-m-d H:00:00' : 'Y-m-d H:i:00';
            return ['bucket_seconds' => $bucket_seconds, 'now_bucket' => $now_bucket, 'time_format' => $time_format];
        }

        switch ($i) {
            case 'minute':
                return ['bucket_seconds' => 60, 'now_bucket' => $now_ts - ($now_ts % 60), 'time_format' => 'Y-m-d H:i:00'];
            case 'hour':
                return ['bucket_seconds' => 3600, 'now_bucket' => $now_ts - ($now_ts % 3600), 'time_format' => 'Y-m-d H:00:00'];
            case 'day':
                return ['bucket_seconds' => 86400, 'now_bucket' => strtotime(gmdate('Y-m-d 00:00:00', $now_ts)), 'time_format' => 'Y-m-d 00:00:00'];
            default:
                // Fallback to hour
                return ['bucket_seconds' => 3600, 'now_bucket' => $now_ts - ($now_ts % 3600), 'time_format' => 'Y-m-d H:00:00'];
        }
    }
    
    /**
     * Format raw query results into Chart.js format
     */
    private static function format_chart_data($rows, $time_config) {
        $result = [];
        $parameter_info = [];
        
        foreach ($rows as $row) {
            $pid = (int)$row['parameter_id'];
            $timestamp = strtotime($row['time_bucket']) * 1000; // Convert to JavaScript milliseconds
            $value = round((float)$row['avg_value'], 2);
            
            // Store parameter metadata
            if (!isset($parameter_info[$pid])) {
                $parameter_info[$pid] = [
                    'name' => $row['parameter_name'] ?: "Parameter {$pid}",
                    'unit' => $row['parameter_unit'] ?: ''
                ];
            }
            
            // Initialize parameter array if needed
            if (!isset($result[$pid])) {
                $result[$pid] = [];
            }
            
            // Add data point [timestamp, value]
            $result[$pid][] = [$timestamp, $value];
        }
        
        return [
            'data' => $result,
            'metadata' => $parameter_info
        ];
    }
    
    /**
     * Get latest values for parameters (for table display)
     */
    public static function get_latest_values($parameter_ids = []) {
        global $wpdb;
        
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;
        
        if (empty($parameter_ids)) {
            // Get all parameters if none specified
            $parameter_ids = $wpdb->get_col("SELECT DISTINCT parameter_id FROM `{$table}` ORDER BY parameter_id");
        }
        
        $parameter_ids = array_filter(array_map('absint', $parameter_ids));
        if (empty($parameter_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($parameter_ids), '%d'));
        
        $sql = $wpdb->prepare("
            SELECT t1.parameter_id, t1.parameter_name, t1.parameter_unit, t1.value, t1.ts as timestamp
            FROM `{$table}` t1
            INNER JOIN (
                SELECT parameter_id, MAX(ts) AS max_timestamp
                FROM `{$table}`
                WHERE parameter_id IN ({$placeholders})
                GROUP BY parameter_id
            ) t2 ON t1.parameter_id = t2.parameter_id AND t1.ts = t2.max_timestamp
            ORDER BY t1.parameter_id
        ", $parameter_ids);

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Build a pivot table array for given parameters, interval and limit.
     * Returns ['time_order' => [...], 'parameter_ids' => [...], 'pivot' => [time => [pid => value]], 'labels' => [pid=>label]]
     */
    public static function get_pivot($parameter_ids = [], $interval = 'hour', $limit = 200) {
        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        // Normalize pids
        $pids = $parameter_ids ? array_filter(array_map('absint', $parameter_ids)) : [];
        if (empty($pids)) {
            $pids = $wpdb->get_col("SELECT DISTINCT parameter_id FROM `{$table}` ORDER BY parameter_id LIMIT 50");
        }

        if (empty($pids)) return ['time_order' => [], 'parameter_ids' => [], 'pivot' => [], 'labels' => []];

        // Resolve time buckets (supports numeric minutes like '30' or '30min')
        $now_ts = strtotime(current_time('mysql', 1));
        $interval_info = self::interval_to_bucket($interval, $now_ts);
        $bucket_seconds = $interval_info['bucket_seconds'];
        $now_bucket = $interval_info['now_bucket'];
        $time_format = $interval_info['time_format'];

        $start_bucket = $now_bucket - ($bucket_seconds * ($limit - 1));
        $time_order = [];
        for ($i = 0; $i < $limit; $i++) {
            $ts = $start_bucket + ($i * $bucket_seconds);
            $time_order[] = gmdate($time_format, $ts);
        }

        // Query raw rows
        $placeholders = implode(',', array_fill(0, count($pids), '%d'));
        $lookback_seconds = 6 * 3600;
        $query_start = gmdate('Y-m-d H:i:s', $start_bucket - $lookback_seconds);
        $query_end = gmdate('Y-m-d H:i:s', $now_ts);

        $sql = $wpdb->prepare(
            "SELECT parameter_id, parameter_name, ts, value
             FROM `{$table}`
             WHERE parameter_id IN ({$placeholders})
             AND ts >= %s
             AND ts <= %s
             AND value IS NOT NULL
             ORDER BY parameter_id ASC, ts ASC",
            array_merge($pids, [$query_start, $query_end])
        );

        $raw_rows = $wpdb->get_results($sql, ARRAY_A);

        // Organize rows by parameter
        $rows_by_param = [];
        foreach ($pids as $pid) $rows_by_param[$pid] = [];
        foreach ($raw_rows as $r) {
            $pid = (int)$r['parameter_id'];
            $rows_by_param[$pid][] = $r;
        }

        // Get labels
        $name_sql = $wpdb->prepare("SELECT DISTINCT parameter_id, parameter_name FROM `{$table}` WHERE parameter_id IN ({$placeholders})", $pids);
        $name_rows = $wpdb->get_results($name_sql, ARRAY_A);
        $param_labels = [];
        foreach ($pids as $pid) $param_labels[$pid] = (string)$pid;
        foreach ($name_rows as $nr) $param_labels[(int)$nr['parameter_id']] = $nr['parameter_name'] ?: (string)$nr['parameter_id'];

        // Build pivot
        $pivot = [];
        foreach ($time_order as $tb) {
            $pivot[$tb] = [];
            $tb_ts = strtotime($tb);
            foreach ($pids as $pid) {
                $value = null;
                $rows_list = $rows_by_param[$pid] ?? [];
                for ($i = count($rows_list) - 1; $i >= 0; $i--) {
                    $rts = strtotime($rows_list[$i]['ts']);
                    if ($rts <= $tb_ts) {
                        $value = $rows_list[$i]['value'];
                        break;
                    }
                }
                $pivot[$tb][$pid] = $value !== null ? (float)$value : null;
            }
        }

        return [
            'time_order' => $time_order,
            'parameter_ids' => $pids,
            'pivot' => $pivot,
            'labels' => $param_labels
        ];
    }
}

/**
 * REST API Handler for Chart Data
 * Provides secure API endpoints for frontend chart loading
 */
class MyUplink_Display_REST {
    
    /**
     * Initialize REST API endpoints
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        // Chart data endpoint
        register_rest_route('myuplink-display/v1', '/chart-data', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_chart_data'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'parameter_ids' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [__CLASS__, 'validate_parameter_ids']
                ],
                'period' => [
                    'default' => '1day',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'start_date' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'end_date' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // Latest values endpoint
        register_rest_route('myuplink-display/v1', '/latest', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_latest_values'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'parameter_ids' => [
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
    }

    /**
     * Validate parameter IDs format
     */
    public static function validate_parameter_ids($value) {
        $ids = explode(',', $value);
        foreach ($ids as $id) {
            if (!is_numeric(trim($id))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check API permissions
     */
    public static function check_permissions($request) {
        $stored_key = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        $provided_key = $request->get_param('api_key');

        // Allow site owners to force public read access (e.g. for anonymous charts) via filter
        $public_override = apply_filters('myuplink_display_public_access', false, $request);
        if ($public_override) {
            return true;
        }

        // If no key is set, require admin privileges
        if (empty($stored_key)) {
            return current_user_can('manage_options');
        }

        // If key is set, check if it matches
        if (!empty($provided_key) && hash_equals($stored_key, $provided_key)) {
            return true;
        }

        return false;
    }

    /**
     * REST endpoint: Get chart data
     */
    public static function get_chart_data($request) {
        try {
            $parameter_ids = array_map('intval', explode(',', $request->get_param('parameter_ids')));
            $period = $request->get_param('period');
            $start_date = $request->get_param('start_date');
            $end_date = $request->get_param('end_date');
            
            $data = MyUplink_Display_Data::get_chart_data($parameter_ids, $period, $start_date, $end_date);
            
            return rest_ensure_response($data);
            
        } catch (Exception $e) {
            return new WP_Error('query_failed', 'Failed to fetch chart data', ['status' => 500]);
        }
    }

    /**
     * REST endpoint: Get latest values
     */
    public static function get_latest_values($request) {
        try {
            $parameter_ids_param = $request->get_param('parameter_ids');
            $parameter_ids = $parameter_ids_param ? array_map('intval', explode(',', $parameter_ids_param)) : [];
            
            $data = MyUplink_Display_Data::get_latest_values($parameter_ids);
            
            return rest_ensure_response($data);
            
        } catch (Exception $e) {
            return new WP_Error('query_failed', 'Failed to fetch latest values', ['status' => 500]);
        }
    }
}

/**
 * Shortcode Handler
 * Handles all shortcodes for data display
 */
class MyUplink_Display_Shortcode {
    
    /**
     * Initialize shortcodes
     */
    public static function init() {
        // Chart shortcodes removed: plotting via Chart.js was unreliable. Tables and pivot remain.
        add_shortcode('myuplink_table', [__CLASS__, 'render_table']);
        add_shortcode('myuplink_table_full', [__CLASS__, 'render_table_full']);
        add_shortcode('myuplink_table_pivot', [__CLASS__, 'render_table_pivot']);
    // New lightweight pivot chart shortcode (SVG-based)
    add_shortcode('myuplink_pivot_chart', [__CLASS__, 'render_pivot_chart']);
        add_shortcode('myuplink_value', [__CLASS__, 'render_single_value']);
    // Admin helper: list parameter IDs and names (only visible to admins)
    add_shortcode('myuplink_list_parameters', [__CLASS__, 'render_parameter_list']);
    // Export all pivoted data (streams CSV in chunks)
    add_shortcode('myuplink_pivot_export_all', [__CLASS__, 'render_pivot_export_all']);
    // Energy summary: compute delta production/consumption and ratio over common intervals
    add_shortcode('myuplink_energy_summary', [__CLASS__, 'render_energy_summary']);
        
        // AJAX handler for CSV export (frontend)
        add_action('wp_ajax_nopriv_myuplink_export_csv', [__CLASS__, 'handle_export_csv']);
        add_action('wp_ajax_myuplink_export_csv', [__CLASS__, 'handle_export_csv']);
        // AJAX handler for pivot CSV export
        add_action('wp_ajax_nopriv_myuplink_export_csv_pivot', [__CLASS__, 'handle_export_csv_pivot']);
        add_action('wp_ajax_myuplink_export_csv_pivot', [__CLASS__, 'handle_export_csv_pivot']);
        // AJAX handler for exporting ALL pivot data in safe, chunked streaming
        add_action('wp_ajax_nopriv_myuplink_export_csv_pivot_all', [__CLASS__, 'handle_export_csv_pivot_all']);
        add_action('wp_ajax_myuplink_export_csv_pivot_all', [__CLASS__, 'handle_export_csv_pivot_all']);
    }

    // Chart.js-based chart rendering (and inline test charts) were removed. Use the
    // [myuplink_pivot_chart] shortcode which provides a lightweight SVG renderer and selectable series.

    /**
     * Render data table shortcode
     * 
     * Usage: [myuplink_table parameter_ids="13,14,781"]
     */
    public static function render_table($atts = []) {
        $defaults = [
            'parameter_ids' => '',
            'title' => 'Latest Sensor Values'
        ];

        $atts = shortcode_atts($defaults, $atts, 'myuplink_table');

        $parameter_ids = !empty($atts['parameter_ids']) ? array_map('intval', explode(',', $atts['parameter_ids'])) : [];

        $rows = MyUplink_Display_Data::get_latest_values($parameter_ids);

        if (empty($rows)) {
            return '<div class="myuplink-error">No data available</div>';
        }

        ob_start();
        ?>
        <div class="myuplink-table-container">
            <?php if (!empty($atts['title'])): ?>
                <h3><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>

            <div class="myuplink-table-responsive">
                <table class="myuplink-data-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Parameter', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Value', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Unit', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Last Updated', 'myuplink-display'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['parameter_name'] ?: 'ID ' . $row['parameter_id']); ?></td>
                            <td><?php echo esc_html($row['value'] !== null ? number_format($row['value'], 2) : '—'); ?></td>
                            <td><?php echo esc_html($row['parameter_unit']); ?></td>
                            <td><?php echo esc_html(mysql2date('M j, Y g:i A', $row['timestamp'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
        .myuplink-table-container { margin: 20px 0; }
        .myuplink-table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .myuplink-data-table { border-collapse: collapse; width: 100%; table-layout: fixed; min-width: 600px; }
        .myuplink-data-table th, .myuplink-data-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 120px; }
        .myuplink-data-table th { background-color: #f9f9f9; font-weight: 600; }
        .myuplink-data-table thead th { position: sticky; top: 0; z-index: 2; background: #fff; }
        .myuplink-data-table thead th:first-child, .myuplink-data-table tbody td:first-child { position: sticky; left: 0; z-index: 3; background: #fff; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render full table shortcode (recent rows or full with limit)
     * Usage: [myuplink_table_full limit="100" parameter_ids="13,14" show_export="true"]
     */
    public static function render_table_full($atts = []) {
        $defaults = [
            'limit' => 100,
            'parameter_ids' => '',
            'show_export' => 'true',
            'title' => 'Sensor Data'
        ];

        $atts = shortcode_atts($defaults, $atts, 'myuplink_table_full');

        $limit = intval($atts['limit']) > 0 ? intval($atts['limit']) : 100;
        $parameter_ids = $atts['parameter_ids'] ? array_map('intval', explode(',', $atts['parameter_ids'])) : [];

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        $where = '';
        $params = [];
        if (!empty($parameter_ids)) {
            $placeholders = implode(',', array_fill(0, count($parameter_ids), '%d'));
            $where = "WHERE parameter_id IN ({$placeholders})";
            $params = $parameter_ids;
        }

        $sql = $wpdb->prepare("SELECT * FROM `{$table}` {$where} ORDER BY ts DESC, id DESC LIMIT %d", array_merge($params, [$limit]));
        $rows = $wpdb->get_results($sql, ARRAY_A);

        ob_start();
        ?>
        <div class="myuplink-full-table">
            <?php if (!empty($atts['title'])): ?>
                <h3><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>

            <?php if ($atts['show_export'] === 'true'): ?>
                <?php
                // Build export URL
                $export_url = add_query_arg([
                    'action' => 'myuplink_export_csv',
                    'parameter_ids' => $atts['parameter_ids'],
                    'limit' => $limit
                ], admin_url('admin-ajax.php'));
                ?>
                <p><a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Download CSV', 'myuplink-display'); ?></a></p>
            <?php endif; ?>

            <div class="myuplink-table-responsive">
                <table class="widefat striped myuplink-full-table-inner">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Timestamp', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Device ID', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Parameter ID', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Parameter Name', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Value', 'myuplink-display'); ?></th>
                            <th><?php esc_html_e('Unit', 'myuplink-display'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7"><?php esc_html_e('No data available.', 'myuplink-display'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['id']); ?></td>
                                <td><?php echo esc_html($row['ts']); ?></td>
                                <td><?php echo esc_html($row['device_id']); ?></td>
                                <td><?php echo esc_html($row['parameter_id']); ?></td>
                                <td><?php echo esc_html($row['parameter_name']); ?></td>
                                <td><?php echo esc_html($row['value']); ?></td>
                                <td><?php echo esc_html($row['parameter_unit']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <style>
            .myuplink-full-table-inner { border-collapse: collapse; width: 100%; table-layout: fixed; min-width: 800px; }
            .myuplink-full-table-inner th, .myuplink-full-table-inner td { padding: 8px 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 120px; }
            .myuplink-full-table-inner thead th { position: sticky; top: 0; background: #fff; z-index: 2; }
            .myuplink-full-table-inner thead th:first-child, .myuplink-full-table-inner tbody td:first-child { position: sticky; left: 0; background: #fff; z-index: 3; }
            </style>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render pivot table: time as rows, parameters as columns
     * Usage: [myuplink_table_pivot parameter_ids="13,14" limit="200" interval="hour"]
     * interval: minute|hour|day (controls grouping)
     */
    public static function render_table_pivot($atts = []) {
        $defaults = [
            'parameter_ids' => '',
            'limit' => 200,
            'interval' => 'hour',
            'title' => 'Pivoted Sensor Data'
        ];

        $atts = shortcode_atts($defaults, $atts, 'myuplink_table_pivot');

        $parameter_ids = $atts['parameter_ids'] ? array_map('intval', explode(',', $atts['parameter_ids'])) : [];
        $limit = intval($atts['limit']) > 0 ? intval($atts['limit']) : 200;

        // Choose grouping expression based on interval
        switch ($atts['interval']) {
            case 'minute':
                $group_expr = "DATE_FORMAT(ts, '%Y-%m-%d %H:%i:00')";
                break;
            case 'day':
                $group_expr = "DATE_FORMAT(ts, '%Y-%m-%d 00:00:00')";
                break;
            case 'hour':
            default:
                $group_expr = "DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')";
                break;
        }

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        // If no parameters specified, get distinct parameter_ids limited
        if (empty($parameter_ids)) {
            $parameter_ids = $wpdb->get_col("SELECT DISTINCT parameter_id FROM `{$table}` ORDER BY parameter_id LIMIT 50");
        }

        if (empty($parameter_ids)) {
            return '<div class="myuplink-error">No parameter IDs available.</div>';
        }

        // Fetch parameter names for labels if available
        $placeholders = implode(',', array_fill(0, count($parameter_ids), '%d'));
        $name_sql = $wpdb->prepare("SELECT DISTINCT parameter_id, parameter_name FROM `{$table}` WHERE parameter_id IN ({$placeholders})", $parameter_ids);
        $name_rows = $wpdb->get_results($name_sql, ARRAY_A);
        $param_labels = [];
        foreach ($parameter_ids as $pid) {
            $param_labels[$pid] = (string)$pid; // fallback
        }
        foreach ($name_rows as $nr) {
            $param_labels[(int)$nr['parameter_id']] = $nr['parameter_name'] ?: (string)$nr['parameter_id'];
        }

        // Build list of bucket timestamps (ascending) based on interval and limit
        $now_ts = strtotime(current_time('mysql', 1));
        $interval_info = MyUplink_Display_Data::interval_to_bucket($atts['interval'], $now_ts);
        $bucket_seconds = $interval_info['bucket_seconds'];
        $now_bucket = $interval_info['now_bucket'];
        $time_format = $interval_info['time_format'];

        $start_bucket = $now_bucket - ($bucket_seconds * ($limit - 1));

        $time_order = [];
        for ($i = 0; $i < $limit; $i++) {
            $ts = $start_bucket + ($i * $bucket_seconds);
            $time_order[] = gmdate($time_format, $ts);
        }

        // Query raw rows from earliest needed minus lookback window (6 hours) so older values can fill forward
        $lookback_seconds = 6 * 3600;
        $query_start = gmdate('Y-m-d H:i:s', $start_bucket - $lookback_seconds);
        $query_end = gmdate('Y-m-d H:i:s', $now_ts);

        $sql = $wpdb->prepare(
            "SELECT parameter_id, parameter_name, parameter_unit, ts, value
             FROM `{$table}`
             WHERE parameter_id IN ({$placeholders})
             AND ts >= %s
             AND ts <= %s
             AND value IS NOT NULL
             ORDER BY parameter_id ASC, ts ASC",
            array_merge($parameter_ids, [$query_start, $query_end])
        );

        $raw_rows = $wpdb->get_results($sql, ARRAY_A);

        // Organize rows per parameter for efficient lookup
        $rows_by_param = [];
        foreach ($parameter_ids as $pid) {
            $rows_by_param[$pid] = [];
        }
        foreach ($raw_rows as $r) {
            $pid = (int)$r['parameter_id'];
            $rows_by_param[$pid][] = $r;
        }

        // For each bucket and parameter, pick the latest row with ts <= bucket_time
        $pivot = [];
        foreach ($time_order as $tb) {
            $pivot[$tb] = [];
            $tb_ts = strtotime($tb);
            foreach ($parameter_ids as $pid) {
                $value = null;
                $rows_list = $rows_by_param[$pid] ?? [];
                // Binary search or linear scan from end: we'll scan backwards for simplicity
                for ($i = count($rows_list) - 1; $i >= 0; $i--) {
                    $rts = strtotime($rows_list[$i]['ts']);
                    if ($rts <= $tb_ts) {
                        $value = $rows_list[$i]['value'];
                        break;
                    }
                }
                $pivot[$tb][$pid] = $value !== null ? (float)$value : null;
            }
        }

        // Build export URL (include api_key if set in options)
        $api_key = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        $export_args = [
            'action' => 'myuplink_export_csv_pivot',
            'parameter_ids' => implode(',', $parameter_ids),
            'interval' => $atts['interval'],
            'limit' => $limit
        ];
        if (!empty($api_key)) {
            $export_args['api_key'] = $api_key;
        }
        $export_url = add_query_arg($export_args, admin_url('admin-ajax.php'));

        ob_start();
        ?>
        <div class="myuplink-pivot-table">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            <p><a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Download Pivot CSV', 'myuplink-display'); ?></a></p>
            <div class="myuplink-table-responsive">
                <table class="widefat striped myuplink-pivot-inner">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'myuplink-display'); ?></th>
                            <?php foreach ($parameter_ids as $pid): ?>
                                <th><?php echo esc_html($param_labels[$pid]); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($time_order)): ?>
                            <tr><td colspan="<?php echo count($parameter_ids) + 1; ?>"><?php esc_html_e('No data available.', 'myuplink-display'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($time_order as $tb): ?>
                                <tr>
                                    <td><?php echo esc_html($tb); ?></td>
                                    <?php foreach ($parameter_ids as $pid): ?>
                                        <td><?php echo isset($pivot[$tb][$pid]) ? esc_html($pivot[$tb][$pid]) : '—'; ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <style>
            .myuplink-pivot-inner { border-collapse: collapse; width: 100%; table-layout: fixed; min-width: 800px; }
            .myuplink-pivot-inner th, .myuplink-pivot-inner td { padding: 8px 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 110px; }
            .myuplink-pivot-inner thead th { position: sticky; top: 0; background: #fff; z-index: 2; }
            .myuplink-pivot-inner thead th:first-child, .myuplink-pivot-inner tbody td:first-child { position: sticky; left: 0; background: #fff; z-index: 3; }
            </style>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a lightweight pivot chart using inline SVG and minimal JS.
     * Usage: [myuplink_pivot_chart parameter_ids="4,8,10" interval="day" limit="200" height="250" show_legend="true"]
     */
    public static function render_pivot_chart($atts = []) {
        $defaults = [
            'parameter_ids' => '',
            'interval' => 'hour',
            'limit' => 200,
            'height' => 300,
            'width' => '100%',
            'title' => 'Pivot Chart',
            'show_legend' => 'true',
            // new options
            'x_ticks' => 8,
            'y_ticks' => 5,
            'timezone' => 'local' // 'local' or 'utc'
            , 'padding_bottom' => 72
            , 'energy_pair' => '' // Optional: "prod_id,cons_id" cumulative kWh counters to derive daily production/consumption deltas + ratio
        ];

        $atts = shortcode_atts($defaults, $atts, 'myuplink_pivot_chart');

        $pids = $atts['parameter_ids'] ? array_map('intval', explode(',', $atts['parameter_ids'])) : [];
        $limit = max(1, intval($atts['limit']));

        // Auto-include energy_pair cumulative IDs if not already present so we can derive deltas/ratio
        $energy_pair_ids = [];
        $auto_added_energy_ids = [];
        if (!empty($atts['energy_pair'])) {
            $energy_pair_ids = array_filter(array_map('intval', explode(',', $atts['energy_pair'])));
            foreach ($energy_pair_ids as $epid) {
                if (!in_array($epid, $pids, true)) {
                    $pids[] = $epid; // fetch underlying cumulative series internally
                    $auto_added_energy_ids[] = $epid; // mark for later removal from visible base series
                }
            }
        }

        $pivot_data = MyUplink_Display_Data::get_pivot($pids, $atts['interval'], $limit);
        $time_order = $pivot_data['time_order'];
        $parameter_ids = $pivot_data['parameter_ids'];
        $pivot = $pivot_data['pivot'];
        $labels = $pivot_data['labels'];

        if (empty($time_order) || empty($parameter_ids)) {
            return '<div class="myuplink-error">No data available for pivot chart.</div>';
        }

        // Prepare JSON-encoded series for the JS
        $series = [];
        foreach ($parameter_ids as $pid) {
            $pts = [];
            foreach ($time_order as $tb) {
                $v = isset($pivot[$tb][$pid]) ? $pivot[$tb][$pid] : null;
                $pts[] = $v !== null ? (float)$v : null;
            }
            $series[] = ['pid' => $pid, 'label' => $labels[$pid] ?? (string)$pid, 'points' => $pts];
        }

        // Derive daily energy production/consumption deltas and ratio from cumulative counters, if requested.
        // Usage example (must also include those IDs in parameter_ids so they are fetched):
        // [myuplink_pivot_chart parameter_ids="4,13,14,28392,28393" interval="day" limit="30" energy_pair="28392,28393" ...]
        // Assumes the provided parameter IDs are monotonically increasing cumulative kWh counters.
        if (!empty($atts['energy_pair'])) {
            $pair_parts = array_map('intval', explode(',', $atts['energy_pair']));
            if (count($pair_parts) === 2) {
                list($prod_pid, $cons_pid) = $pair_parts;
                $interval_lower = strtolower($atts['interval']);
                // Only derive when using daily buckets (interval="day"). Keeps change minimal & avoids misleading per-hour deltas.
                if ($interval_lower === 'day') {
                    $prod_series = null; $cons_series = null;
                    foreach ($series as $s) {
                        if ($s['pid'] === $prod_pid) $prod_series = $s;
                        if ($s['pid'] === $cons_pid) $cons_series = $s;
                    }
                    if ($prod_series && $cons_series) {
                        $prod_delta = []; $cons_delta = []; $ratio = [];
                        $prev_prod = null; $prev_cons = null;
                        for ($i = 0; $i < count($prod_series['points']); $i++) {
                            $cur_prod = $prod_series['points'][$i];
                            $cur_cons = $cons_series['points'][$i];
                            // Production delta
                            if ($prev_prod !== null && $cur_prod !== null && $cur_prod >= $prev_prod) {
                                $prod_delta[] = round($cur_prod - $prev_prod, 2);
                            } else {
                                $prod_delta[] = null; // first bucket or reset
                            }
                            // Consumption delta
                            if ($prev_cons !== null && $cur_cons !== null && $cur_cons >= $prev_cons) {
                                $cons_delta[] = round($cur_cons - $prev_cons, 2);
                            } else {
                                $cons_delta[] = null;
                            }
                            // Ratio (production / consumption)
                            if (end($prod_delta) !== null && end($cons_delta) !== null && end($cons_delta) > 0) {
                                $ratio[] = round(end($prod_delta) / end($cons_delta), 3);
                            } else {
                                $ratio[] = null;
                            }
                            $prev_prod = $cur_prod;
                            $prev_cons = $cur_cons;
                        }
                        // Append derived series (pid strings to avoid collision; JS uses index ordering & label only)
                        $series[] = ['pid' => 'energy_prod_delta', 'label' => 'Energy Prod Δ (kWh)', 'points' => $prod_delta];
                        $series[] = ['pid' => 'energy_cons_delta', 'label' => 'Energy Cons Δ (kWh)', 'points' => $cons_delta];
                        $series[] = ['pid' => 'energy_ratio', 'label' => 'Prod/Cons Ratio', 'points' => $ratio];

                        // If cumulative energy IDs were auto-added (not requested explicitly), remove their base series
                        if (!empty($auto_added_energy_ids)) {
                            $series = array_values(array_filter($series, function($s) use ($auto_added_energy_ids) {
                                // keep derived series and any original user-requested series
                                if (is_int($s['pid']) && in_array($s['pid'], $auto_added_energy_ids, true)) {
                                    return false; // hide auto-added cumulative
                                }
                                return true;
                            }));
                        }
                    }
                }
            }
        }

        $json_time = wp_json_encode($time_order);
        $json_series = wp_json_encode($series);

        $config = wp_json_encode([
            'maxXTicks' => intval($atts['x_ticks']),
            'yTicks' => intval($atts['y_ticks']),
            'timezone' => in_array(strtolower($atts['timezone']), ['utc','local']) ? strtolower($atts['timezone']) : 'local'
            , 'paddingBottom' => intval($atts['padding_bottom'])
        ]);

        $container_id = 'myuplink_pivot_' . wp_generate_uuid4();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($container_id); ?>" class="myuplink-pivot-chart" style="width:<?php echo esc_attr($atts['width']); ?>;">
            <?php if (!empty($atts['title'])): ?><h3><?php echo esc_html($atts['title']); ?></h3><?php endif; ?>
            <div class="myuplink-pivot-controls" style="margin-bottom:8px;">
                <?php if ($atts['show_legend'] === 'true'): ?>
                    <?php foreach ($series as $idx => $s): ?>
                        <label style="margin-right:10px; font-size:13px;">
                            <input type="checkbox" class="myuplink-pivot-toggle" data-series-index="<?php echo esc_attr($idx); ?>" checked />
                            <?php echo esc_html($s['label']); ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="myuplink-pivot-svg-wrapper" style="border:1px solid #e5e5e5; background:#fff; padding:6px;">
                <svg class="myuplink-pivot-svg" width="100%" height="<?php echo esc_attr(intval($atts['height'])); ?>" viewBox="0 0 800 <?php echo esc_attr(intval($atts['height'])); ?>" preserveAspectRatio="none" role="img" aria-label="Pivot chart"></svg>
            </div>

            <details style="margin-top:8px;"><summary>Show pivot table</summary>
                <div class="myuplink-table-responsive" style="margin-top:8px;">
                    <table class="widefat striped myuplink-pivot-inner">
                        <thead><tr><th>Time</th><?php foreach ($parameter_ids as $pid): ?><th><?php echo esc_html($labels[$pid]); ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($time_order as $tb): ?><tr><td><?php echo esc_html($tb); ?></td><?php foreach ($parameter_ids as $pid): ?><td><?php echo isset($pivot[$tb][$pid]) ? esc_html($pivot[$tb][$pid]) : '—'; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </div>

        <script>(function(){
            const times = <?php echo $json_time; ?>;
            const series = <?php echo $json_series; ?>;
            const CONFIG = <?php echo $config; ?>;
            const config = <?php echo $config; ?>;
            const container = document.getElementById('<?php echo esc_js($container_id); ?>');
            const svg = container.querySelector('svg.myuplink-pivot-svg');
            const height = parseInt(svg.getAttribute('height')) || 300;
            const viewW = 800; // fixed viewbox width
            const viewH = height;
            const PALETTE = ['#1f77b4','#ff7f0e','#2ca02c','#d62728','#9467bd','#8c564b','#17becf','#bcbd22'];

            function computeRanges(activeIdxs) {
                let min = Infinity, max = -Infinity;
                for (const i of activeIdxs) {
                    const pts = series[i].points;
                    for (const v of pts) {
                        if (v === null) continue;
                        if (v < min) min = v;
                        if (v > max) max = v;
                    }
                }
                if (min === Infinity || max === -Infinity) { min = 0; max = 1; }
                if (min === max) { max = min + 1; }
                return {min, max};
            }

            function render() {
                // get active series
                const toggles = container.querySelectorAll('.myuplink-pivot-toggle');
                const active = [];
                toggles.forEach((t,i)=>{ if (t.checked) active.push(parseInt(t.dataset.seriesIndex)); });
                if (active.length === 0) { svg.innerHTML = '<text x="10" y="20" fill="#666">No series selected</text>'; return; }

                const ranges = computeRanges(active);
                // Use separate paddings so we can give more room at the bottom for rotated X labels
                const paddingLeft = 64; // room for Y labels
                const paddingTop = 12;
                const paddingRight = 12;
                const paddingBottom = (CONFIG && CONFIG.paddingBottom) ? parseInt(CONFIG.paddingBottom) : 72; // configurable bottom padding
                const plotW = viewW - paddingLeft - paddingRight;
                const plotH = viewH - paddingTop - paddingBottom;

                // X positions by index
                const ptsX = times.map((t,i)=> paddingLeft + (i/(times.length-1||1))*plotW );

                // Build background grid, axes, ticks and data paths
                let inner = '';

                // Y ticks (numeric) - integer ticks preferred
                const yTicksRequested = Math.max(2, (CONFIG && CONFIG.yTicks) ? CONFIG.yTicks : 5);
                // Compute integer step
                let approxStep = (ranges.max - ranges.min) / (yTicksRequested - 1);
                let stepInt = Math.max(1, Math.ceil(approxStep));
                let minTick = Math.floor(ranges.min / stepInt) * stepInt;
                let maxTick = Math.ceil(ranges.max / stepInt) * stepInt;
                const yVals = [];
                for (let v = maxTick; v >= minTick; v -= stepInt) yVals.push(v);

                // Draw horizontal grid lines and labels (from top to bottom)
                for (let i=0;i<yVals.length;i++) {
                    const v = yVals[i]; // already from top (max) to bottom (min)
                    const y = paddingTop + ((maxTick - v)/(maxTick - minTick || 1))*plotH;
                    inner += '<line x1="'+paddingLeft+'" y1="'+y+'" x2="'+(paddingLeft+plotW)+'" y2="'+y+'" stroke="#eee" />';
                    inner += '<text x="'+(paddingLeft-8)+'" y="'+(y+4)+'" font-size="11" fill="#333" text-anchor="end">'+String(v)+'</text>';
                }

                // X ticks: choose up to configured number
                const maxXTicks = Math.min((CONFIG && CONFIG.maxXTicks) ? CONFIG.maxXTicks : 8, times.length);
                const xTickPositions = [];
                if (times.length === 1) {
                    xTickPositions.push(0);
                } else {
                    const step = Math.max(1, Math.floor((times.length-1)/(maxXTicks-1)));
                    for (let i=0;i<times.length;i+=step) xTickPositions.push(i);
                    if (xTickPositions[xTickPositions.length-1] !== times.length-1) xTickPositions.push(times.length-1);
                }

                // Draw vertical small tick lines and labels
                for (const idx of xTickPositions) {
                    const x = ptsX[idx];
                    const lblRaw = times[idx];
                    // Parse as UTC (replace space with 'T')
                    const dt = new Date(String(lblRaw).replace(' ', 'T') + 'Z');
                    const lbl = formatTimeLabel(dt, CONFIG && CONFIG.timezone ? CONFIG.timezone : 'local');
                    inner += '<line x1="'+x+'" y1="'+(paddingTop+plotH)+'" x2="'+x+'" y2="'+(paddingTop+plotH+6)+'" stroke="#ccc" />';
                    const lx = x;
                    // Pin labels to a fixed offset below the axis so increasing padding_bottom
                    // only increases whitespace under the labels, not the gap between axis and labels.
                    const labelOffset = 14; // pixels below axis baseline
                    const ly = paddingTop + plotH + labelOffset; // baseline for rotated labels (fixed)
                    inner += '<text x="'+lx+'" y="'+ly+'" transform="rotate(-90 '+lx+' '+ly+')" font-size="11" fill="#333" text-anchor="end">'+escapeHtml(lbl)+'</text>';
                }

                // Draw vertical Y axis
                inner += '<line x1="'+paddingLeft+'" y1="'+paddingTop+'" x2="'+paddingLeft+'" y2="'+(paddingTop+plotH)+'" stroke="#999" />';
                // Draw horizontal X axis
                inner += '<line x1="'+paddingLeft+'" y1="'+(paddingTop+plotH)+'" x2="'+(paddingLeft+plotW)+'" y2="'+(paddingTop+plotH)+'" stroke="#999" />';

                // Data series paths
                for (let si=0; si<series.length; si++) {
                    if (!active.includes(si)) continue;
                    const s = series[si];
                    // choose color from palette
                    const color = PALETTE[si % PALETTE.length];
                    let path = '';
                    let started = false;
                    for (let i=0;i<s.points.length;i++) {
                        const v = s.points[i];
                        if (v === null) { started = false; continue; }
                        const x = ptsX[i];
                        const y = paddingTop + ((maxTick - v)/(maxTick - minTick || 1))*plotH;
                        if (!started) { path += 'M '+x+' '+y; started = true; } else { path += ' L '+x+' '+y; }
                    }
                    inner += '<path d="'+path+'" fill="none" stroke="'+color+'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />';
                }

                // Update legend colors and add swatches
                const legendLabels = container.querySelectorAll('.myuplink-pivot-controls label');
                legendLabels.forEach((lab, idx) => {
                    const color = PALETTE[idx % PALETTE.length];
                    lab.style.color = color;
                    const input = lab.querySelector('input');
                    if (input) {
                        // ensure a small swatch after input
                        let sw = lab.querySelector('.myuplink-swatch');
                        if (!sw) {
                            sw = document.createElement('span');
                            sw.className = 'myuplink-swatch';
                            sw.style.display = 'inline-block';
                            sw.style.width = '12px';
                            sw.style.height = '12px';
                            sw.style.margin = '0 6px 0 0';
                            sw.style.verticalAlign = 'middle';
                            input.parentNode.insertBefore(sw, input.nextSibling);
                        }
                        sw.style.background = color;
                    }
                });

                // Ensure container is positioned relatively for tooltip absolute positioning
                if (!container.style.position) container.style.position = 'relative';

                // Tooltip element (create once)
                let tooltip = container.querySelector('.myuplink-tooltip');
                if (!tooltip) {
                    tooltip = document.createElement('div');
                    tooltip.className = 'myuplink-tooltip';
                    tooltip.style.cssText = 'position:absolute;pointer-events:none;background:#fff;border:1px solid #ccc;padding:6px;border-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,0.12);font-size:12px;display:none;z-index:999;';
                    container.appendChild(tooltip);
                }

                // Mouse move handler for tooltip
                svg.addEventListener('mousemove', function(evt){
                    try {
                        const rect = svg.getBoundingClientRect();
                        const mouseX = (evt.clientX - rect.left) * (viewW / rect.width);
                        // find nearest index
                        let nearest = 0; let md = Infinity;
                        for (let i=0;i<ptsX.length;i++) { const d = Math.abs(ptsX[i]-mouseX); if (d < md) { md = d; nearest = i; } }
                        // Build tooltip content (use same formatted label as X axis)
                        const dt_tip = new Date(String(times[nearest]).replace(' ', 'T') + 'Z');
                        const tipLabel = formatTimeLabel(dt_tip, CONFIG && CONFIG.timezone ? CONFIG.timezone : 'local');
                        let html = '<div style="font-weight:600;margin-bottom:6px;">'+escapeHtml(tipLabel)+'</div>';
                        for (let si=0; si<series.length; si++) {
                            if (!active.includes(si)) continue;
                            const s = series[si];
                            const val = s.points[nearest];
                            const color = PALETTE[si % PALETTE.length];
                            html += '<div style="margin-bottom:3px;"><span style="display:inline-block;width:10px;height:10px;background:'+color+';margin-right:6px;vertical-align:middle;"></span>' + escapeHtml(s.label) + ': ' + (val===null? '—' : formatNumber(val)) + '</div>';
                        }
                        tooltip.innerHTML = html;
                        // Position tooltip (inside container)
                        const left = Math.min(rect.width - 10, evt.clientX - rect.left + 12);
                        const top = evt.clientY - rect.top + 12;
                        tooltip.style.left = left + 'px';
                        tooltip.style.top = top + 'px';
                        tooltip.style.display = 'block';
                    } catch (e) { /* ignore */ }
                });

                svg.addEventListener('mouseleave', function(){ if (tooltip) tooltip.style.display = 'none'; });

                svg.innerHTML = inner;
            }

            function formatNumber(n) {
                // Small helper to nicely format numbers
                if (Math.abs(n) >= 1000 || Math.abs(n) < 0.01) return Number(n).toPrecision(3);
                return Number(n).toFixed(2).replace(/\.00$/, '');
            }

            function formatTimeLabel(dt, tz) {
                // Format as Ddd dd/mm/yy HH:MM; tz = 'utc' or 'local'
                try {
                    function pad2(n){ return (n<10? '0':'') + n; }
                    const weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    if (tz === 'utc') {
                        const day = weekdays[dt.getUTCDay()];
                        const dd = pad2(dt.getUTCDate());
                        const mm = pad2(dt.getUTCMonth()+1);
                        const yy = String(dt.getUTCFullYear()).slice(-2);
                        const hh = pad2(dt.getUTCHours());
                        const min = pad2(dt.getUTCMinutes());
                        return day + ' ' + dd + '/' + mm + '/' + yy + ' ' + hh + ':' + min;
                    } else {
                        const day = weekdays[dt.getDay()];
                        const dd = pad2(dt.getDate());
                        const mm = pad2(dt.getMonth()+1);
                        const yy = String(dt.getFullYear()).slice(-2);
                        const hh = pad2(dt.getHours());
                        const min = pad2(dt.getMinutes());
                        return day + ' ' + dd + '/' + mm + '/' + yy + ' ' + hh + ':' + min;
                    }
                } catch (e) {
                    return dt.toISOString().replace('T', ' ').replace('Z', '');
                }
            }

            function escapeHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

            // Attach toggles
            const toggles = container.querySelectorAll('.myuplink-pivot-toggle');
            toggles.forEach(t=> t.addEventListener('change', render));

            // Initial render
            render();
        })();</script>

        <style>
        /* Minimal inline styles to keep plugin self-contained */
        #<?php echo esc_attr($container_id); ?> .myuplink-pivot-inner { border-collapse: collapse; width: 100%; table-layout: fixed; min-width: 600px; }
        #<?php echo esc_attr($container_id); ?> .myuplink-pivot-inner th, #<?php echo esc_attr($container_id); ?> .myuplink-pivot-inner td { padding: 6px 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        </style>

        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to export CSV of the table
     */
    public static function handle_export_csv() {
        // Capability check: allow admin or if a public display key is set and matches
        if (!current_user_can('manage_options')) {
            // If not admin, deny - you may implement API key based access here
            wp_die('Permission denied');
        }

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        $parameter_ids = isset($_GET['parameter_ids']) ? sanitize_text_field($_GET['parameter_ids']) : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;

        $where = '';
        $params = [];
        if (!empty($parameter_ids)) {
            $ids = array_filter(array_map('absint', explode(',', $parameter_ids)));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $where = "WHERE parameter_id IN ({$placeholders})";
                $params = $ids;
            }
        }

        $limit_sql = $limit > 0 ? 'LIMIT ' . $limit : '';

        $sql = $wpdb->prepare("SELECT * FROM `{$table}` {$where} ORDER BY ts DESC, id DESC {$limit_sql}", $params);
        $rows = $wpdb->get_results($sql, ARRAY_A);

        // Output CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=myuplink_data_' . date('Ymd_His') . '.csv');

        $output = fopen('php://output', 'w');
        // CSV header row
        fputcsv($output, ['id', 'ts', 'device_id', 'parameter_id', 'parameter_name', 'value', 'parameter_unit']);

        if (!empty($rows)) {
            foreach ($rows as $r) {
                fputcsv($output, [$r['id'], $r['ts'], $r['device_id'], $r['parameter_id'], $r['parameter_name'], $r['value'], $r['parameter_unit']]);
            }
        }

        fclose($output);
        exit;
    }

    /**
     * Export pivoted CSV: time bucket as first column, then parameter columns
     * Accessible if admin or if api_key matches the stored key
     */
    public static function handle_export_csv_pivot() {
        // Permission: either admin or valid api_key
        $stored_key = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        $provided_key = isset($_GET['api_key']) ? sanitize_text_field($_GET['api_key']) : '';

        if (!empty($stored_key)) {
            if (empty($provided_key) || !hash_equals($stored_key, $provided_key)) {
                wp_die('Permission denied');
            }
        } else {
            if (!current_user_can('manage_options')) {
                wp_die('Permission denied');
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        $parameter_ids = isset($_GET['parameter_ids']) ? sanitize_text_field($_GET['parameter_ids']) : '';
        $interval = isset($_GET['interval']) ? sanitize_text_field($_GET['interval']) : 'hour';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;

        $pids = [];
        if (!empty($parameter_ids)) {
            $pids = array_filter(array_map('absint', explode(',', $parameter_ids)));
        }

        if (empty($pids)) {
            wp_die('No parameter IDs specified');
        }

        // group_expr not used directly here; use interval_to_bucket to compute time buckets below

        $placeholders = implode(',', array_fill(0, count($pids), '%d'));

        $limit_sql = $limit > 0 ? 'LIMIT ' . $limit : '';

        // Build list of buckets identically to render_table_pivot
        $now_ts = strtotime(current_time('mysql', 1));
        $interval_info = MyUplink_Display_Data::interval_to_bucket($interval, $now_ts);
        $bucket_seconds = $interval_info['bucket_seconds'];
        $now_bucket = $interval_info['now_bucket'];
        $time_format = $interval_info['time_format'];

        $start_bucket = $now_bucket - ($bucket_seconds * ($limit - 1));
        $time_order = [];
        for ($i = 0; $i < $limit; $i++) {
            $ts = $start_bucket + ($i * $bucket_seconds);
            $time_order[] = gmdate($time_format, $ts);
        }

        // Query raw rows from earliest needed minus lookback window (6 hours)
        $lookback_seconds = 6 * 3600;
        $query_start = gmdate('Y-m-d H:i:s', $start_bucket - $lookback_seconds);
        $query_end = gmdate('Y-m-d H:i:s', $now_ts);

        $sql = $wpdb->prepare(
            "SELECT parameter_id, parameter_name, ts, value
             FROM `{$table}`
             WHERE parameter_id IN ({$placeholders})
             AND ts >= %s
             AND ts <= %s
             AND value IS NOT NULL
             ORDER BY parameter_id ASC, ts ASC",
            array_merge($pids, [$query_start, $query_end])
        );

        $raw_rows = $wpdb->get_results($sql, ARRAY_A);

        // Organize rows by parameter
        $rows_by_param = [];
        foreach ($pids as $pid) $rows_by_param[$pid] = [];
        foreach ($raw_rows as $r) {
            $pid = (int)$r['parameter_id'];
            $rows_by_param[$pid][] = $r;
        }

        // Get parameter names
        $name_sql = $wpdb->prepare("SELECT DISTINCT parameter_id, parameter_name FROM `{$table}` WHERE parameter_id IN ({$placeholders})", $pids);
        $name_rows = $wpdb->get_results($name_sql, ARRAY_A);
        $param_labels = [];
        foreach ($pids as $pid) $param_labels[$pid] = $pid;
        foreach ($name_rows as $nr) $param_labels[(int)$nr['parameter_id']] = $nr['parameter_name'] ?: $nr['parameter_id'];

        // Prepare CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=myuplink_pivot_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');

        // Header row
        $header = array_merge(['time_bucket'], array_map(function($pid) use ($param_labels) {
            return $param_labels[$pid];
        }, $pids));
        fputcsv($out, $header);

        // For each bucket, find latest row <= bucket for each param
        foreach ($time_order as $tb) {
            $row = [$tb];
            $tb_ts = strtotime($tb);
            foreach ($pids as $pid) {
                $value = '';
                $rows_list = $rows_by_param[$pid] ?? [];
                for ($i = count($rows_list) - 1; $i >= 0; $i--) {
                    $rts = strtotime($rows_list[$i]['ts']);
                    if ($rts <= $tb_ts) {
                        $value = $rows_list[$i]['value'];
                        break;
                    }
                }
                $row[] = $value;
            }
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * Export ALL pivoted data safely by streaming in chunks.
     * Usage: call admin-ajax.php?action=myuplink_export_csv_pivot_all&parameter_ids=1,2,3&interval=30&api_key=KEY
     * This will compute time buckets from earliest to latest available and stream CSV rows one bucket at a time.
     */
    public static function handle_export_csv_pivot_all() {
        global $wpdb;

        $stored_key = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        $provided_key = isset($_GET['api_key']) ? sanitize_text_field($_GET['api_key']) : '';

        if (!empty($stored_key)) {
            if (empty($provided_key) || !hash_equals($stored_key, $provided_key)) {
                wp_die('Permission denied');
            }
        } else {
            if (!current_user_can('manage_options')) {
                wp_die('Permission denied');
            }
        }

        $parameter_ids = isset($_GET['parameter_ids']) ? sanitize_text_field($_GET['parameter_ids']) : '';
        $interval = isset($_GET['interval']) ? sanitize_text_field($_GET['interval']) : 'hour';

        $pids = [];
        if (!empty($parameter_ids)) {
            $pids = array_filter(array_map('absint', explode(',', $parameter_ids)));
        }
        if (empty($pids)) wp_die('No parameter IDs specified');

        $placeholders = implode(',', array_fill(0, count($pids), '%d'));

        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        // Find overall min and max timestamps for these parameters
        $min_row = $wpdb->get_row($wpdb->prepare("SELECT MIN(ts) as min_ts FROM `{$table}` WHERE parameter_id IN ({$placeholders}) AND value IS NOT NULL", $pids), ARRAY_A);
        $max_row = $wpdb->get_row($wpdb->prepare("SELECT MAX(ts) as max_ts FROM `{$table}` WHERE parameter_id IN ({$placeholders}) AND value IS NOT NULL", $pids), ARRAY_A);

        if (empty($min_row['min_ts']) || empty($max_row['max_ts'])) {
            wp_die('No data available for specified parameters');
        }

        // Determine bucket info
        $now_ts = strtotime(current_time('mysql', 1));
        $interval_info = MyUplink_Display_Data::interval_to_bucket($interval, $now_ts);
        $bucket_seconds = $interval_info['bucket_seconds'];
        $time_format = $interval_info['time_format'];

        $start_ts = strtotime($min_row['min_ts']);
        $end_ts = strtotime($max_row['max_ts']);

        // Align start to bucket boundary
        $start_bucket = $start_ts - ($start_ts % $bucket_seconds);
        $end_bucket = $end_ts - ($end_ts % $bucket_seconds);

        // Stream headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=myuplink_pivot_all_' . date('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');

        // Get parameter labels
        $name_sql = $wpdb->prepare("SELECT DISTINCT parameter_id, parameter_name FROM `{$table}` WHERE parameter_id IN ({$placeholders})", $pids);
        $name_rows = $wpdb->get_results($name_sql, ARRAY_A);
        $param_labels = [];
        foreach ($pids as $pid) $param_labels[$pid] = $pid;
        foreach ($name_rows as $nr) $param_labels[(int)$nr['parameter_id']] = $nr['parameter_name'] ?: $nr['parameter_id'];

        // Write CSV header
        $header = array_merge(['time_bucket'], array_map(function($pid) use ($param_labels) { return $param_labels[$pid]; }, $pids));
        fputcsv($out, $header);

        // We'll process bucket ranges in manageable chunks to avoid long-running single queries
        $buckets_total = intval(($end_bucket - $start_bucket) / $bucket_seconds) + 1;
        $batches = 5000; // number of buckets per iteration (tunable)

        for ($offset_bucket = 0; $offset_bucket < $buckets_total; $offset_bucket += $batches) {
            $batch_count = min($batches, $buckets_total - $offset_bucket);
            $batch_start = $start_bucket + ($offset_bucket * $bucket_seconds);
            $batch_end = $batch_start + (($batch_count - 1) * $bucket_seconds);

            // Query raw rows covering this batch plus a small lookback so we can fill forward (6 hours lookback)
            $lookback_seconds = 6 * 3600;
            $query_start = gmdate('Y-m-d H:i:s', $batch_start - $lookback_seconds);
            $query_end = gmdate('Y-m-d H:i:s', $batch_end + $bucket_seconds);

            $sql = $wpdb->prepare(
                "SELECT parameter_id, ts, value FROM `{$table}` WHERE parameter_id IN ({$placeholders}) AND ts >= %s AND ts <= %s AND value IS NOT NULL ORDER BY parameter_id ASC, ts ASC",
                array_merge($pids, [$query_start, $query_end])
            );

            $raw_rows = $wpdb->get_results($sql, ARRAY_A);

            // Organize rows by parameter
            $rows_by_param = [];
            foreach ($pids as $pid) $rows_by_param[$pid] = [];
            foreach ($raw_rows as $r) { $rows_by_param[(int)$r['parameter_id']][] = $r; }

            // For each bucket in this batch, write row
            for ($b = 0; $b < $batch_count; $b++) {
                $tb_ts = $batch_start + ($b * $bucket_seconds);
                $tb_label = gmdate($time_format, $tb_ts);
                $out_row = [$tb_label];
                foreach ($pids as $pid) {
                    $val = '';
                    $rows_list = $rows_by_param[$pid];
                    for ($i = count($rows_list) - 1; $i >= 0; $i--) {
                        $rts = strtotime($rows_list[$i]['ts']);
                        if ($rts <= $tb_ts) { $val = $rows_list[$i]['value']; break; }
                    }
                    $out_row[] = $val;
                }
                fputcsv($out, $out_row);
            }

            // Flush output buffers so the client receives data incrementally
            if (function_exists('ob_flush')) { @ob_flush(); }
            if (function_exists('flush')) { @flush(); }
        }

        fclose($out);
        exit;
    }

    /**
     * Render single value shortcode
     * 
     * Usage: [myuplink_value parameter_id="13" format="Temperature: {value} {unit}"]
     */
    public static function render_single_value($atts = []) {
        $defaults = [
            'parameter_id' => '',
            'format' => '{value} {unit}',
            'decimals' => '2'
        ];

        $atts = shortcode_atts($defaults, $atts, 'myuplink_value');

        if (empty($atts['parameter_id'])) {
            return '<span class="myuplink-error">Error: parameter_id required</span>';
        }

        $parameter_id = intval($atts['parameter_id']);
        $rows = MyUplink_Display_Data::get_latest_values([$parameter_id]);

        if (empty($rows)) {
            return '<span class="myuplink-no-data">No data</span>';
        }

        $row = $rows[0];
        $value = $row['value'] !== null ? number_format($row['value'], intval($atts['decimals'])) : '—';
        $unit = $row['parameter_unit'];
        $name = $row['parameter_name'] ?: "Parameter {$parameter_id}";

        // Replace placeholders in format string
        $output = str_replace(
            ['{value}', '{unit}', '{name}'],
            [$value, $unit, $name],
            $atts['format']
        );

        return '<span class="myuplink-single-value">' . esc_html($output) . '</span>';
    }

    /**
     * Admin helper shortcode: list parameter IDs and their names
     * Usage (admin only): [myuplink_list_parameters]
     */
    public static function render_parameter_list($atts = []) {
        if (!current_user_can('manage_options')) {
            return '<div class="myuplink-error">Permission denied.</div>';
        }

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        $rows = $wpdb->get_results("SELECT DISTINCT parameter_id, parameter_name FROM `{$table}` ORDER BY parameter_id", ARRAY_A);
        if (empty($rows)) return '<div class="myuplink-no-data">No parameters found.</div>';

        ob_start();
        ?>
        <div class="myuplink-parameter-list">
            <h3>Available Parameters</h3>
            <div class="myuplink-table-responsive">
                <table class="widefat striped" style="max-width:700px;">
                    <thead><tr><th>Parameter ID</th><th>Parameter Name</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr><td><?php echo esc_html($r['parameter_id']); ?></td><td><?php echo esc_html($r['parameter_name']); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a secure download link for exporting ALL pivot data (streamed)
     * Usage: [myuplink_pivot_export_all parameter_ids="1,2,3" interval="30" include_api_key="false" label="Download all pivot CSV"]
     */
    public static function render_pivot_export_all($atts = []) {
        $defaults = [ 'parameter_ids' => '', 'interval' => '30', 'include_api_key' => 'false', 'label' => 'Download all pivot CSV' ];
        $atts = shortcode_atts($defaults, $atts, 'myuplink_pivot_export_all');

        $parameter_ids = sanitize_text_field($atts['parameter_ids']);
        if (empty($parameter_ids)) return '<div class="myuplink-error">Error: parameter_ids required</div>';

        $interval = sanitize_text_field($atts['interval']);
        $include_key = in_array(strtolower($atts['include_api_key']), ['1','true','yes'], true);

        $api_key = '';
        $stored_key = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        if ($include_key && !empty($stored_key) && current_user_can('manage_options')) {
            $api_key = $stored_key;
        }

        $args = [ 'action' => 'myuplink_export_csv_pivot_all', 'parameter_ids' => $parameter_ids, 'interval' => $interval ];
        if (!empty($api_key)) $args['api_key'] = $api_key;

        $export_url = add_query_arg($args, admin_url('admin-ajax.php'));

        ob_start();
        ?>
        <div class="myuplink-pivot-export-all">
            <p><a class="button" href="<?php echo esc_url($export_url); ?>"><?php echo esc_html($atts['label']); ?></a></p>
            <p class="description">This will stream a CSV of pivoted values from the earliest to the latest data for the requested parameters. Large exports may take a while; consider narrowing by parameter or exporting the raw table if you need row-level detail.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render energy summary for production and consumption parameters over several intervals.
     * Usage: [myuplink_energy_summary prod_id="28392" cons_id="28393"]
     * Optional: days="1" to show only a single interval in days; otherwise shows 1/7/30/365/total
     */
    public static function render_energy_summary($atts = []) {
        $defaults = [ 'prod_id' => '', 'cons_id' => '', 'days' => '' ];
        $atts = shortcode_atts($defaults, $atts, 'myuplink_energy_summary');

        $prod_id = intval($atts['prod_id']);
        $cons_id = intval($atts['cons_id']);
        if (!$prod_id || !$cons_id) {
            return '<div class="myuplink-error">Error: prod_id and cons_id are required.</div>';
        }

        global $wpdb;
        $table = $wpdb->prefix . MYUPLINK_RAW_DATA_TABLE;

        // Helper to get the latest value <= given timestamp (UTC)
        $get_latest_at = function($param_id, $ts_mysql) use ($wpdb, $table) {
            $sql = $wpdb->prepare("SELECT value, ts FROM `{$table}` WHERE parameter_id = %d AND ts <= %s AND value IS NOT NULL ORDER BY ts DESC LIMIT 1", [$param_id, $ts_mysql]);
            return $wpdb->get_row($sql, ARRAY_A);
        };

        // Helper to get earliest value >= given timestamp (UTC)
        $get_earliest_at = function($param_id, $ts_mysql) use ($wpdb, $table) {
            $sql = $wpdb->prepare("SELECT value, ts FROM `{$table}` WHERE parameter_id = %d AND ts >= %s AND value IS NOT NULL ORDER BY ts ASC LIMIT 1", [$param_id, $ts_mysql]);
            return $wpdb->get_row($sql, ARRAY_A);
        };

        // Intervals to compute (days => human label)
        // Include 48 hours (2 days), 3 days and 14 days per user request.
        $intervals = [
            1 => 'Last 24 hours',
            2 => 'Last 48 hours',
            3 => 'Last 3 days',
            7 => 'Last 7 days',
            14 => 'Last 14 days',
            30 => 'Last 30 days',
            365 => 'Last 365 days'
        ];
        if (!empty($atts['days'])) {
            $d = intval($atts['days']);
            if ($d > 0) $intervals = [$d => "$d days"]; else $intervals = [];
        }

        // We'll compute per-interval: find start_ts and end_ts (now), then find nearest values
        $now_ts = time();
        $now_mysql = gmdate('Y-m-d H:i:s', $now_ts);

        $rows = [];
        foreach ($intervals as $days => $label) {
            $start_ts = $now_ts - ($days * 24 * 3600);
            $start_mysql = gmdate('Y-m-d H:i:s', $start_ts);

            // For cumulative counters: find latest <= end, and latest <= start (or earliest >= start if none before)
            $end_prod = $get_latest_at($prod_id, $now_mysql);
            $end_cons = $get_latest_at($cons_id, $now_mysql);

            $start_prod = $get_latest_at($prod_id, $start_mysql);
            if (!$start_prod) { // try earliest >= start
                $start_prod = $get_earliest_at($prod_id, $start_mysql);
            }
            $start_cons = $get_latest_at($cons_id, $start_mysql);
            if (!$start_cons) {
                $start_cons = $get_earliest_at($cons_id, $start_mysql);
            }

            $prod_delta = null; $cons_delta = null;
            if (!empty($end_prod) && !empty($start_prod) && is_numeric($end_prod['value']) && is_numeric($start_prod['value'])) {
                $prod_delta = floatval($end_prod['value']) - floatval($start_prod['value']);
            }
            if (!empty($end_cons) && !empty($start_cons) && is_numeric($end_cons['value']) && is_numeric($start_cons['value'])) {
                $cons_delta = floatval($end_cons['value']) - floatval($start_cons['value']);
            }

            $ratio = null;
            if ($cons_delta !== null && $cons_delta != 0 && $prod_delta !== null) {
                $ratio = $prod_delta / $cons_delta;
            }

            $rows[] = [ 'label' => $label, 'prod' => $prod_delta, 'cons' => $cons_delta, 'ratio' => $ratio, 'start_ts' => $start_mysql, 'end_ts' => $now_mysql ];
        }

        // Total: compute earliest available to latest available across both params
        $min_row = $wpdb->get_row($wpdb->prepare("SELECT MIN(ts) as min_ts FROM `{$table}` WHERE parameter_id IN (%d,%d) AND value IS NOT NULL", [$prod_id, $cons_id]), ARRAY_A);
        $max_row = $wpdb->get_row($wpdb->prepare("SELECT MAX(ts) as max_ts FROM `{$table}` WHERE parameter_id IN (%d,%d) AND value IS NOT NULL", [$prod_id, $cons_id]), ARRAY_A);
        if (!empty($min_row['min_ts']) && !empty($max_row['max_ts'])) {
            $total_start = $min_row['min_ts'];
            $total_end = $max_row['max_ts'];
            $ts_start = gmdate('Y-m-d H:i:s', strtotime($total_start));
            $ts_end = gmdate('Y-m-d H:i:s', strtotime($total_end));

            $end_prod = $wpdb->get_row($wpdb->prepare("SELECT value FROM `{$table}` WHERE parameter_id = %d AND ts <= %s AND value IS NOT NULL ORDER BY ts DESC LIMIT 1", [$prod_id, $ts_end]), ARRAY_A);
            $start_prod = $wpdb->get_row($wpdb->prepare("SELECT value FROM `{$table}` WHERE parameter_id = %d AND ts >= %s AND value IS NOT NULL ORDER BY ts ASC LIMIT 1", [$prod_id, $ts_start]), ARRAY_A);
            $end_cons = $wpdb->get_row($wpdb->prepare("SELECT value FROM `{$table}` WHERE parameter_id = %d AND ts <= %s AND value IS NOT NULL ORDER BY ts DESC LIMIT 1", [$cons_id, $ts_end]), ARRAY_A);
            $start_cons = $wpdb->get_row($wpdb->prepare("SELECT value FROM `{$table}` WHERE parameter_id = %d AND ts >= %s AND value IS NOT NULL ORDER BY ts ASC LIMIT 1", [$cons_id, $ts_start]), ARRAY_A);

            $total_prod = null; $total_cons = null; $total_ratio = null;
            if (!empty($end_prod['value']) && !empty($start_prod['value'])) $total_prod = floatval($end_prod['value']) - floatval($start_prod['value']);
            if (!empty($end_cons['value']) && !empty($start_cons['value'])) $total_cons = floatval($end_cons['value']) - floatval($start_cons['value']);
            if ($total_cons !== null && $total_cons != 0 && $total_prod !== null) $total_ratio = $total_prod / $total_cons;
        } else {
            $total_prod = $total_cons = $total_ratio = null;
        }

        // Render
        ob_start();
        ?>
        <div class="myuplink-energy-summary">
            <h3>Energy Summary</h3>
            <table class="widefat striped" style="max-width:600px;">
                <thead><tr><th>Interval</th><th>Production (kWh)</th><th>Consumption (kWh)</th><th>Prod/Cons</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['label']); ?></td>
                        <td><?php echo $r['prod'] !== null ? esc_html(number_format($r['prod'], 2)) : '—'; ?></td>
                        <td><?php echo $r['cons'] !== null ? esc_html(number_format($r['cons'], 2)) : '—'; ?></td>
                        <td><?php echo $r['ratio'] !== null ? esc_html(number_format($r['ratio'], 2)) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700;"><td>Total</td><td><?php echo $total_prod !== null ? esc_html(number_format($total_prod,2)) : '—'; ?></td><td><?php echo $total_cons !== null ? esc_html(number_format($total_cons,2)) : '—'; ?></td><td><?php echo $total_ratio !== null ? esc_html(number_format($total_ratio,2)) : '—'; ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

/**
 * Admin Interface for Display Settings
 */
class MyUplink_Display_Admin {
    
    /**
     * Initialize admin functionality
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    /**
     * Register settings
     */
    public static function register_settings() {
        $settings = [
            MyUplink_Display_Install::OPTION_REST_KEY => 'sanitize_text_field',
            MyUplink_Display_Install::OPTION_DEFAULT_COLORS => 'sanitize_text_field',
            MyUplink_Display_Install::OPTION_DEFAULT_HEIGHT => 'absint'
        ];

        foreach ($settings as $option => $sanitize_callback) {
            register_setting('myuplink_display', $option, [
                'sanitize_callback' => $sanitize_callback
            ]);
        }

        add_settings_section(
            'myuplink_display_section',
            __('Display Settings', 'myuplink-display'),
            [__CLASS__, 'render_section'],
            'myuplink_display'
        );

        add_settings_field('rest_key', __('REST API Key (optional)', 'myuplink-display'), 
            [__CLASS__, 'render_rest_key'], 'myuplink_display', 'myuplink_display_section');
        add_settings_field('default_colors', __('Default Chart Colors', 'myuplink-display'), 
            [__CLASS__, 'render_default_colors'], 'myuplink_display', 'myuplink_display_section');
        add_settings_field('default_height', __('Default Chart Height', 'myuplink-display'), 
            [__CLASS__, 'render_default_height'], 'myuplink_display', 'myuplink_display_section');
    }

    /**
     * Add menu page
     */
    public static function add_menu() {
        add_options_page(
            __('MyUplink Display', 'myuplink-display'),
            __('MyUplink Display', 'myuplink-display'),
            'manage_options',
            'myuplink_display',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('MyUplink Display Settings', 'myuplink-display'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('myuplink_display');
                do_settings_sections('myuplink_display');
                submit_button();
                ?>
            </form>

            <div class="myuplink-help-section">
                <h2>Shortcode Usage Guide</h2>
                
                <h3>Chart Shortcode</h3>
                <p><strong>Basic usage:</strong></p>
                <code>[myuplink_chart parameter_ids="13,14" period="1day"]</code>
                
                <p><strong>With custom colors:</strong></p>
                <code>[myuplink_chart parameter_ids="13,14" period="7days" colors="#ff0000,#00ff00"]</code>
                
                <p><strong>Available periods:</strong></p>
                <ul>
                    <li><code>1day, day, 24h</code> - Last 24 hours (5-minute intervals)</li>
                    <li><code>7days, week, 7d</code> - Last 7 days (hourly intervals)</li>
                    <li><code>1month, month, 30d</code> - Last 30 days (daily intervals)</li>
                    <li><code>yearly, year, 365d</code> - Last year (monthly intervals)</li>
                </ul>
                
                <h3>Table Shortcode</h3>
                <code>[myuplink_table parameter_ids="13,14,781" title="Current Values"]</code>
                
                <h3>Single Value Shortcode</h3>
                <code>[myuplink_value parameter_id="13" format="Temperature: {value}°{unit}"]</code>
                
                <h3>Chart Parameters</h3>
                <ul>
                    <li><strong>parameter_ids</strong> - Comma-separated parameter IDs (required)</li>
                    <li><strong>period</strong> - Time period (1day, 7days, 1month, yearly)</li>
                    <li><strong>colors</strong> - Custom hex colors (comma-separated)</li>
                    <li><strong>height</strong> - Chart height in pixels</li>
                    <li><strong>title</strong> - Chart title</li>
                    <li><strong>start_date/end_date</strong> - Custom date range (YYYY-MM-DD format)</li>
                </ul>
            </div>
            <?php self::render_shortcodes_reference(); ?>
        </div>
        <?php
    }

    /**
     * Render a detailed shortcodes reference table for the admin page
     */
    public static function render_shortcodes_reference() {
        // Define available shortcodes with metadata and examples
        $shortcodes = [
            'myuplink_pivot_chart' => [
                'description' => 'Lightweight SVG pivot chart. Renders time buckets on X and selectable parameter series on Y. No external chart libraries required.',
                'params' => 'parameter_ids (required), interval, limit, height, width, title, show_legend, x_ticks, y_ticks, timezone, padding_bottom',
                'example' => '[myuplink_pivot_chart parameter_ids="13,14" interval="30" limit="96" height="360" padding_bottom="110"]'
            ],
            'myuplink_table' => [
                'description' => 'Simple table showing the latest value for each requested parameter.',
                'params' => 'parameter_ids, title',
                'example' => '[myuplink_table parameter_ids="13,14,781" title="Current Values"]'
            ],
            'myuplink_table_full' => [
                'description' => 'Full recent rows table. Can filter by parameter_ids and includes a CSV export button (admin-only by default).',
                'params' => 'parameter_ids, limit, show_export, title',
                'example' => '[myuplink_table_full parameter_ids="13,14" limit="500" show_export="true"]'
            ],
            'myuplink_table_pivot' => [
                'description' => 'Pivot table where each row is a time bucket and each column is a parameter (values are averaged per bucket).',
                'params' => 'parameter_ids, limit, interval (minute|hour|day), title',
                'example' => '[myuplink_table_pivot parameter_ids="13,14" interval="hour" limit="200"]'
            ],
            'myuplink_value' => [
                'description' => 'Inline single-value display for a parameter. Supports custom format strings.',
                'params' => 'parameter_id (required), format, decimals',
                'example' => '[myuplink_value parameter_id="13" format="Temperature: {value}°{unit}"]'
            ]
        ];

        ?>
        <div class="myuplink-shortcodes-ref" style="margin-top:30px;">
            <h2>Available Shortcodes</h2>
            <p>Below is a quick reference of the shortcodes provided by this plugin, their parameters and example usage. Paste any example directly into a post or page.</p>

            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th>Shortcode</th>
                        <th>Description</th>
                        <th>Parameters</th>
                        <th>Example</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shortcodes as $name => $meta): ?>
                        <tr>
                            <td><code>[<?php echo esc_html($name); ?>]</code></td>
                            <td><?php echo esc_html($meta['description']); ?></td>
                            <td><code><?php echo esc_html($meta['params']); ?></code></td>
                            <td><code><?php echo esc_html($meta['example']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:12px;">Notes: parameter IDs are numeric IDs from the collector table. If you want parameter names to be used in headers or labels, ensure the collector stores a descriptive <code>parameter_name</code> for each point.</p>
        </div>
        <?php
    }

    // Field renderers
    public static function render_section() {
        echo '<p>Configure display settings for charts and tables.</p>';
    }

    public static function render_rest_key() {
        $value = get_option(MyUplink_Display_Install::OPTION_REST_KEY, '');
        echo '<input type="text" class="regular-text" name="' . esc_attr(MyUplink_Display_Install::OPTION_REST_KEY) . '" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Optional key for public chart access. Leave empty to require admin login.</p>';
    }

    public static function render_default_colors() {
        $value = get_option(MyUplink_Display_Install::OPTION_DEFAULT_COLORS, '');
        echo '<input type="text" class="large-text" name="' . esc_attr(MyUplink_Display_Install::OPTION_DEFAULT_COLORS) . '" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Comma-separated hex colors (e.g., #1f77b4,#ff7f0e,#2ca02c)</p>';
    }

    public static function render_default_height() {
        $value = get_option(MyUplink_Display_Install::OPTION_DEFAULT_HEIGHT, 350);
        echo '<input type="number" min="200" max="800" class="small-text" name="' . esc_attr(MyUplink_Display_Install::OPTION_DEFAULT_HEIGHT) . '" value="' . esc_attr($value) . '" />';
        echo '<p class="description">Default chart height in pixels</p>';
    }
}

/**
 * Frontend Scripts and Styles
 */
class MyUplink_Display_Frontend {
    
    /**
     * Initialize frontend functionality
     */
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Enqueue scripts and styles when charts are present
     */
    public static function enqueue_scripts() {
        global $post;
        
        // Only load on singular views
        if (!is_singular()) {
            return;
        }

        $content = $post ? $post->post_content : '';
        // Only enqueue minimal table styles for pivot/table displays — plotting is intentionally disabled.
        wp_enqueue_style('myuplink-display-styles', plugins_url('css/myuplink-display.css', __FILE__));
    }

    /**
     * Generate JavaScript for chart initialization
     */
    // Chart.js initialization code removed. No frontend script generator is needed.
}

// Initialize plugin components
add_action('plugins_loaded', function() {
    // Initialize all components
    MyUplink_Display_REST::init();
    MyUplink_Display_Shortcode::init();
    MyUplink_Display_Admin::init();
    MyUplink_Display_Frontend::init();
    
    // Load translations
    load_plugin_textdomain('myuplink-display', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Plugin activation and deactivation hooks
register_activation_hook(__FILE__, ['MyUplink_Display_Install', 'activate']);
register_deactivation_hook(__FILE__, ['MyUplink_Display_Install', 'deactivate']);