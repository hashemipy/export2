<?php
/**
 * سیستم لاگ کردن عملیات‌های پلاگین
 * Logging System for Transfer Operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIE_Logging {
    
    private static $instance = null;
    private $table_name = 'pie_logs';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . $this->table_name;
        
        add_action('admin_init', [$this, 'create_logs_table']);
        add_action('wp_ajax_pie_get_log_details', [$this, 'ajax_get_log_details']);
    }
    
    /**
     * AJAX: دریافت جزئیات لاگ
     */
    public function ajax_get_log_details() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        global $wpdb;
        $log_id = intval($_POST['log_id']);
        
        $log = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $log_id
            )
        );
        
        if (!$log) {
            wp_send_json_error('لاگ پیدا نشد');
        }
        
        wp_send_json_success([
            'operation_type' => $log->operation_type,
            'product_name' => $log->product_name ?? 'N/A',
            'status' => $log->status,
            'message' => $log->message,
            'error_code' => $log->error_code,
            'request_data' => $log->request_data,
            'response_data' => $log->response_data,
            'details' => $log->details
        ]);
    }
    
    /**
     * ایجاد جدول لاگ‌ها
     */
    public function create_logs_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if ($table_exists) {
            // بررسی و اضافه کردن ستون‌های جدید اگر وجود ندارند
            $columns = $wpdb->get_col("DESC {$this->table_name}", 0);
            
            $needed_columns = ['product_id', 'product_name', 'details', 'request_data', 'response_data', 'error_code'];
            foreach ($needed_columns as $col) {
                if (!in_array($col, $columns)) {
                    if ($col === 'product_id') {
                        $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN {$col} INT(11) DEFAULT NULL");
                    } elseif ($col === 'product_name') {
                        $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN {$col} VARCHAR(255) DEFAULT NULL");
                    } elseif ($col === 'error_code') {
                        $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN {$col} VARCHAR(50) DEFAULT NULL");
                    } else {
                        $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN {$col} LONGTEXT DEFAULT NULL");
                    }
                }
            }
            return;
        }
        
        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            operation_type VARCHAR(50) NOT NULL,
            product_id INT(11) DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            message LONGTEXT,
            error_code VARCHAR(50) DEFAULT NULL,
            details LONGTEXT,
            request_data LONGTEXT,
            response_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY operation_type (operation_type),
            KEY status (status),
            KEY created_at (created_at),
            KEY product_id (product_id)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * ثبت لاگ دقیق با جزئیات کامل
     */
    public function log($operation_type, $status, $message, $details = []) {
        global $wpdb;
        
        $log_data = [
            'operation_type' => sanitize_text_field($operation_type),
            'product_id' => isset($details['product_id']) ? intval($details['product_id']) : null,
            'product_name' => isset($details['product_name']) ? sanitize_text_field($details['product_name']) : null,
            'status' => sanitize_text_field($status),
            'message' => wp_kses_post($message),
            'error_code' => isset($details['error_code']) ? sanitize_text_field($details['error_code']) : null,
            'details' => isset($details['details']) ? wp_json_encode($details['details']) : null,
            'request_data' => isset($details['request_data']) ? wp_json_encode($details['request_data']) : null,
            'response_data' => isset($details['response_data']) ? wp_json_encode($details['response_data']) : null,
            'created_at' => current_time('mysql')
        ];
        
        $format = [
            '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
        ];
        
        $result = $wpdb->insert($this->table_name, $log_data, $format);
        
        $log_id = $wpdb->insert_id;
        
        // ثبت همزمان در error_log برای debugging
        error_log("[PIE LOG ID: {$log_id}] {$operation_type} - {$status}: {$message}");
        if (!empty($details['error_code'])) {
            error_log("[PIE ERROR] Code: {$details['error_code']}");
        }
        
        return $log_id;
    }
    
    /**
     * نمایش تب لاگ‌ها
     */
    public function render_logs_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('سازی دسترسی ندارید');
        }
        
        global $wpdb;
        
        // فیلترها
        $filter_operation = isset($_GET['operation']) ? sanitize_text_field($_GET['operation']) : '';
        $filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $filter_days = isset($_GET['days']) ? intval($_GET['days']) : 7;
        
        // ساختن query
        $query = "SELECT * FROM {$this->table_name} WHERE 1=1";
        $params = [];
        
        if (!empty($filter_operation)) {
            $query .= " AND operation_type = %s";
            $params[] = $filter_operation;
        }
        
        if (!empty($filter_status)) {
            $query .= " AND status = %s";
            $params[] = $filter_status;
        }
        
        if ($filter_days > 0) {
            $query .= " AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = $filter_days;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT 100";
        
        $logs = $wpdb->get_results(
            $params ? $wpdb->prepare($query, ...$params) : $query
        );
        
        // آمار
        $stats = [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}"),
            'today' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE DATE(created_at) = CURDATE()"),
            'success' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'success'"),
            'failed' => $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'failed'")
        ];
        
        ?>
        <div class="wrap">
            <h1>📊 لاگ‌های عملیات</h1>
            
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0;">
                <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px; margin-bottom: 5px;">کل عملیات</div>
                    <div style="font-size: 24px; font-weight: bold; color: #333;"><?php echo $stats['total']; ?></div>
                </div>
                <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px; margin-bottom: 5px;">امروز</div>
                    <div style="font-size: 24px; font-weight: bold; color: #2196f3;"><?php echo $stats['today']; ?></div>
                </div>
                <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px; margin-bottom: 5px;">موفق</div>
                    <div style="font-size: 24px; font-weight: bold; color: #4caf50;">✓ <?php echo $stats['success']; ?></div>
                </div>
                <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px; margin-bottom: 5px;">ناموفق</div>
                    <div style="font-size: 24px; font-weight: bold; color: #f44336;">✗ <?php echo $stats['failed']; ?></div>
                </div>
            </div>
            
            <!-- فیلترها -->
            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="pie-logs">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">نوع عملیات:</label>
                            <select name="operation" style="width: 100%; padding: 5px;">
                                <option value="">همه</option>
                                <option value="send" <?php selected($filter_operation, 'send'); ?>>ارسال</option>
                                <option value="receive" <?php selected($filter_operation, 'receive'); ?>>دریافت</option>
                                <option value="upload" <?php selected($filter_operation, 'upload'); ?>>آپلود</option>
                                <option value="download" <?php selected($filter_operation, 'download'); ?>>دانلود</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">وضعیت:</label>
                            <select name="status" style="width: 100%; padding: 5px;">
                                <option value="">همه</option>
                                <option value="success" <?php selected($filter_status, 'success'); ?>>موفق</option>
                                <option value="failed" <?php selected($filter_status, 'failed'); ?>>ناموفق</option>
                                <option value="pending" <?php selected($filter_status, 'pending'); ?>>منتظر</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">مدت زمان:</label>
                            <select name="days" style="width: 100%; padding: 5px;">
                                <option value="1" <?php selected($filter_days, 1); ?>>امروز</option>
                                <option value="7" <?php selected($filter_days, 7); ?>>هفته اخیر</option>
                                <option value="30" <?php selected($filter_days, 30); ?>>ماه اخیر</option>
                                <option value="0" <?php selected($filter_days, 0); ?>>همه</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; align-items: flex-end; gap: 5px;">
                            <button type="submit" class="button button-primary" style="flex: 1;">🔍 فیلتر</button>
                            <a href="?page=pie-logs" class="button" style="padding: 5px 10px;">⟳ بازنشانی</a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- جدول لاگ‌ها -->
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width: 10%;">تاریخ/ساعت</th>
                        <th style="width: 15%;">نوع عملیات</th>
                        <th style="width: 20%;">محصول</th>
                        <th style="width: 10%;">وضعیت</th>
                        <th style="width: 30%;">پیام</th>
                        <th style="width: 15%;">جزئیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($logs)) : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td style="font-size: 12px; color: #666;">
                                    <?php 
                                    $timestamp = strtotime($log->created_at);
                                    $datetime = new DateTime('@' . $timestamp, new DateTimeZone('UTC'));
                                    $datetime->setTimeZone(new DateTimeZone('Asia/Tehran'));
                                    echo $datetime->format('Y/m/d H:i');
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $op_labels = [
                                        'send' => '📤 ارسال',
                                        'receive' => '📥 دریافت',
                                        'upload' => '📁 آپلود',
                                        'download' => '💾 دانلود'
                                    ];
                                    echo $op_labels[$log->operation_type] ?? $log->operation_type;
                                    ?>
                                </td>
                                <td>
                                    <span style="color: #999; font-size: 12px;"><?php echo esc_html(substr($log->message, 0, 40)); ?></span>
                                </td>
                                <td>
                                    <?php
                                    if ($log->status === 'success') {
                                        echo '<span style="background: #c8e6c9; color: #2e7d32; padding: 3px 8px; border-radius: 3px; font-size: 12px;">✓ موفق</span>';
                                    } elseif ($log->status === 'failed') {
                                        echo '<span style="background: #ffcdd2; color: #c62828; padding: 3px 8px; border-radius: 3px; font-size: 12px;">✗ ناموفق</span>';
                                    } else {
                                        echo '<span style="background: #fff9c4; color: #f57f17; padding: 3px 8px; border-radius: 3px; font-size: 12px;">⏳ منتظر</span>';
                                    }
                                    ?>
                                </td>
                                <td style="font-size: 12px;">
                                    <?php echo wp_kses_post(substr($log->message, 0, 50)); ?>
                                    <?php if (strlen($log->message) > 50) echo '...'; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small" onclick="showLogDetails(<?php echo $log->id; ?>)">
                                        مشاهده جزئیات
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: #999;">
                                لاگی برای نمایش وجود ندارد
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
        </div>
        
        <style>
            .log-modal {
                display: none;
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9999;
                justify-content: center;
                align-items: center;
            }
            .log-modal.active {
                display: flex;
            }
            .log-modal-content {
                background: white;
                border-radius: 5px;
                padding: 20px;
                max-width: 600px;
                max-height: 80vh;
                overflow-y: auto;
                position: relative;
            }
            .log-modal-close {
                position: absolute;
                top: 10px; right: 10px;
                background: none;
                border: none;
                font-size: 24px;
                cursor: pointer;
            }
        </style>
        
        <div id="logModal" class="log-modal">
            <div class="log-modal-content">
                <button type="button" class="log-modal-close" onclick="document.getElementById('logModal').classList.remove('active');">×</button>
                <div id="logModalBody"></div>
            </div>
        </div>
        
        <script>
        function showLogDetails(logId) {
            jQuery.ajax({
                type: 'POST',
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                data: {
                    action: 'pie_get_log_details',
                    log_id: logId,
                    nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        const log = response.data;
                        let html = '<h3>' + log.operation_type + ' - ' + log.product_name + '</h3>';
                        html += '<p><strong>وضعیت:</strong> ' + log.status + '</p>';
                        html += '<p><strong>پیام:</strong> ' + log.message + '</p>';
                        
                        if (log.error_code) {
                            html += '<p><strong>کد خطا:</strong> <code>' + log.error_code + '</code></p>';
                        }
                        
                        if (log.request_data) {
                            html += '<h4>درخواست:</h4>';
                            html += '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 300px;">' + JSON.stringify(JSON.parse(log.request_data), null, 2) + '</pre>';
                        }
                        
                        if (log.response_data) {
                            html += '<h4>پاسخ:</h4>';
                            html += '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 300px;">' + JSON.stringify(JSON.parse(log.response_data), null, 2) + '</pre>';
                        }
                        
                        if (log.details) {
                            html += '<h4>جزئیات:</h4>';
                            html += '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 300px;">' + JSON.stringify(JSON.parse(log.details), null, 2) + '</pre>';
                        }
                        
                        jQuery('#logModalBody').html(html);
                        jQuery('#logModal').addClass('active');
                    }
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * پاک کردن لاگ‌های قدیمی
     */
    public static function cleanup_old_logs($days = 30) {
        global $wpdb;
        $instance = self::get_instance();
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$instance->table_name} 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}

// فعال‌سازی
PIE_Logging::get_instance();
