<?php
/**
 * REST API Endpoints برای ارسال/دریافت محصولات
 * REST API for Product Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIE_API {
    
    private static $instance = null;
    private $settings = null;
    private $logging = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->settings = PIE_Settings::get_instance();
        $this->logging = PIE_Logging::get_instance();
        add_action('rest_api_init', [$this, 'register_endpoints']);
    }
    
    /**
     * ثبت API endpoints
     */
    public function register_endpoints() {
        // Endpoint: دریافت محصول
        register_rest_route('pie/v1', '/receive-product', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_product'],
            'permission_callback' => [$this, 'check_auth_for_receive']
        ]);
        
        // Endpoint: پیش‌نمایش محصول (بدون ذخیره نهایی)
        register_rest_route('pie/v1', '/preview-product', [
            'methods' => 'POST',
            'callback' => [$this, 'preview_product'],
            'permission_callback' => [$this, 'check_auth_for_receive']
        ]);
        
        // Endpoint: تأیید و آپلود نهایی
        register_rest_route('pie/v1', '/confirm-product', [
            'methods' => 'POST',
            'callback' => [$this, 'confirm_product'],
            'permission_callback' => [$this, 'check_auth_for_receive']
        ]);
        
        // Endpoint: بررسی ارتباط
        register_rest_route('pie/v1', '/health-check', [
            'methods' => 'GET',
            'callback' => [$this, 'health_check'],
            'permission_callback' => '__return_true'
        ]);

        // Endpoint: دریافت تغییر موجودی از سایت مقابل
        register_rest_route('pie/v1', '/update-stock', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_stock'],
            'permission_callback' => [$this, 'check_auth_for_receive'],
        ]);

        // Endpoint: لیست محصولات برای mapping دستی
        register_rest_route('pie/v1', '/list-products', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_products'],
            'permission_callback' => [$this, 'check_auth_for_receive'],
        ]);

        // Endpoint: ثبت mapping معکوس روی سایت مقابل (برای sync دو طرفه)
        register_rest_route('pie/v1', '/register-map', [
            'methods'             => 'POST',
            'callback'            => [$this, 'register_map_remote'],
            'permission_callback' => [$this, 'check_auth_for_receive'],
        ]);

        // Endpoint: یافتن محصول بر اساس SKU
        // برای جفت‌سازی مطمئن وقتی پاسخ مستقیم ارسال به‌خاطر timeout گم شده باشد
        register_rest_route('pie/v1', '/find-product', [
            'methods'             => 'GET',
            'callback'            => [$this, 'find_product_by_sku'],
            'permission_callback' => [$this, 'check_auth_for_receive'],
        ]);

        // Endpoint: دریافت parent_id یک variation از سایت ۱
        // برای جفت‌سازی فایل‌های JSON قدیمی که _s1_id در سطح محصول ندارند
        register_rest_route('pie/v1', '/product-parent/(?P<variation_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_product_parent'],
            'permission_callback' => [$this, 'check_auth_for_receive'],
            'args'                => [
                'variation_id' => [
                    'required'          => true,
                    'validate_callback' => function($param) { return is_numeric($param) && $param > 0; },
                ],
            ],
        ]);

        // Endpoint داخلی: پردازش فوری یک آیتم صف (loopback از همین سایت)
        // امنیت با توکن یکبارمصرف transient بررسی می‌شود، نه Basic Auth
        register_rest_route('pie/v1', '/process-queue', [
            'methods'             => 'POST',
            'callback'            => [$this, 'process_queue_loopback'],
            'permission_callback' => '__return_true',
        ]);

        // Endpoint داخلی: پردازش آیتم صف با تأخیر (بعد از اتمام lock)
        // توسط schedule_wakeup_for_pending_items فراخوانی می‌شود
        register_rest_route('pie/v1', '/process-queue-delayed', [
            'methods'             => 'POST',
            'callback'            => [$this, 'process_queue_loopback_delayed'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * هندلر loopback با تأخیر: بعد از اتمام lock پردازش می‌کند
     * توسط schedule_wakeup_for_pending_items فراخوانی می‌شود
     * delay_sec: چند ثانیه صبر کن تا lock سایت بیفتد
     */
    public function process_queue_loopback_delayed(WP_REST_Request $request) {
        $body      = $request->get_json_params();
        $queue_id  = intval($body['queue_id'] ?? 0);
        $token     = sanitize_text_field($body['token'] ?? '');
        $delay_sec = max(1, min(120, intval($body['delay_sec'] ?? 35)));

        if (!$queue_id || !$token) {
            return new WP_REST_Response(['success' => false, 'message' => 'پارامتر ناقص'], 400);
        }

        $expected = hash_hmac('sha256', 'pie_queue_' . $queue_id, wp_salt('auth'));
        if (!hash_equals($expected, $token)) {
            return new WP_REST_Response(['success' => false, 'message' => 'توکن نامعتبر'], 403);
        }

        // به جای sleep یکجا، هر ثانیه چک کن که آیا lock افتاده
        // حداکثر delay_sec ثانیه صبر می‌کنیم
        $waited = 0;
        while ($waited < $delay_sec) {
            sleep(1);
            $waited++;
            // بررسی مستقیم از DB
            global $wpdb;
            $table_q   = $wpdb->prefix . 'pie_stock_queue';
            $table_map = $wpdb->prefix . 'pie_stock_map';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT m.is_locked, m.locked_until, q.retry_after
                 FROM {$table_q} q
                 JOIN {$table_map} m ON m.id = q.map_id
                 WHERE q.id = %d",
                $queue_id
            ));
            if (!$row) {
                break; // آیتم دیگر وجود ندارد
            }
            $still_locked = ($row->is_locked && strtotime($row->locked_until) > time());
            $retry_ready  = (!$row->retry_after || strtotime($row->retry_after) <= time());
            if (!$still_locked && $retry_ready) {
                break; // lock افتاده - همین الان پردازش کن
            }
        }

        // lock های منقضی‌شده را unlock کن
        PIE_StockSync::get_instance()->cleanup_expired_locks();

        // آیتم را پردازش کن
        PIE_StockSync::get_instance()->async_process_queue_item($queue_id);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * هندلر loopback داخلی برای پردازش فوری صف موجودی
     * توسط StockSync::trigger_async_processing فراخوانی می‌شود
     */
    public function process_queue_loopback(WP_REST_Request $request) {
        $body     = $request->get_json_params();
        $queue_id = intval($body['queue_id'] ?? 0);
        $token    = sanitize_text_field($body['token'] ?? '');

        if (!$queue_id || !$token) {
            return new WP_REST_Response(['success' => false, 'message' => 'پارامتر ناقص'], 400);
        }

        // اعتبارسنجی با HMAC ثابت (بدون race condition بین loopback‌های همزمان)
        $expected = hash_hmac('sha256', 'pie_queue_' . $queue_id, wp_salt('auth'));
        if (!hash_equals($expected, $token)) {
            return new WP_REST_Response(['success' => false, 'message' => 'توکن نامعتبر'], 403);
        }

        PIE_StockSync::get_instance()->async_process_queue_item($queue_id);

        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * تأیید دسترسی برای دریافت محصول یا موجودی
     * - /receive-product و /preview-product و /confirm-product : فقط سایت ۲
     * - /update-stock و /list-products : هر دو سایت (sync دو طرفه)
     */
    public function check_auth_for_receive() {
        $config = $this->settings->get_config();

        // تعیین endpoint فعلی از REQUEST_URI
        $request_uri   = $_SERVER['REQUEST_URI'] ?? '';

        // endpoint های /update-stock، /list-products و /register-map برای هر دو سایت مجاز هستند
        // بقیه endpoint ها (انتقال محصول) فقط برای سایت ۲ مجاز هستند
        $is_stock_sync = (
            strpos($request_uri, '/update-stock')  !== false ||
            strpos($request_uri, '/list-products') !== false ||
            strpos($request_uri, '/register-map')  !== false ||
            strpos($request_uri, '/find-product')  !== false
        );
        if (!$is_stock_sync && $config['site_role'] !== 'site2') {
            error_log("[PIE] Receive endpoint: site_role is not site2 (got: {$config['site_role']})");
            return new WP_Error('wrong_site', 'This endpoint is only available on Site 2', ['status' => 403]);
        }

        // بررسی Basic Auth header
        $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        
        if (empty($auth_header)) {
            error_log("[PIE] receive-product: no Authorization header found");
            return new WP_Error('no_auth', 'Authorization header is missing', ['status' => 401]);
        }
        
        if (strpos($auth_header, 'Basic ') !== 0) {
            error_log("[PIE] receive-product: Authorization header is not Basic format (got: {$auth_header})");
            return new WP_Error('invalid_auth_type', 'Only Basic Auth is supported', ['status' => 401]);
        }
        
        // تجزیه credentials
        $encoded = substr($auth_header, 6);
        $credentials = base64_decode($encoded, true);
        
        if ($credentials === false) {
            error_log("[PIE] receive-product: base64 decode failed");
            return new WP_Error('invalid_encoding', 'Invalid base64 encoding', ['status' => 401]);
        }
        
        $parts = explode(':', $credentials, 2);
        
        if (count($parts) !== 2) {
            error_log("[PIE] receive-product: credentials not in key:secret format (parts count: " . count($parts) . ")");
            return new WP_Error('invalid_format', 'Credentials must be in key:secret format', ['status' => 401]);
        }
        
        list($key, $secret) = $parts;
        
        error_log("[PIE] Validating API key: " . substr($key, 0, 15) . "... (length: " . strlen($key) . ")");
        
        // استفاده از APIKeyManager برای اعتبارسنجی
        $api_manager = PIE_APIKeyManager::get_instance();
        $valid_key = $api_manager->validate_key($key, $secret);
        
        if (!$valid_key) {
            error_log("[PIE] API key validation failed");
            return new WP_Error('invalid_credentials', 'Invalid API credentials', ['status' => 401]);
        }
        
        error_log("[PIE] API key validated successfully: " . $valid_key['name']);
        return true;
    }
    
    /**
     * تأیید دسترسی API
     */
    public function check_api_auth() {
        $config = $this->settings->get_config();
        
        if ($config['site_role'] !== 'site2') {
            error_log("[PIE] API Auth failed: site_role is not site2 (got: {$config['site_role']})");
            return false;
        }
        
        if (empty($config['api_consumer_key'])) {
            error_log("[PIE] API Auth failed: api_consumer_key is empty");
            return false;
        }
        
        // بررسی Basic Auth
        $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        
        if (empty($auth_header)) {
            error_log("[PIE] API Auth failed: no Authorization header");
            return false;
        }
        
        if (strpos($auth_header, 'Basic ') !== 0) {
            error_log("[PIE] API Auth failed: Authorization header is not Basic");
            return false;
        }
        
        $credentials = base64_decode(substr($auth_header, 6));
        $parts = explode(':', $credentials, 2);
        
        if (count($parts) !== 2) {
            error_log("[PIE] API Auth failed: credentials not in key:secret format");
            return false;
        }
        
        list($key, $secret) = $parts;
        
        error_log("[PIE] API Auth check: key={$key}, secret_len=" . strlen($secret));
        error_log("[PIE] Expected key: {$config['api_consumer_key']}, secret_len=" . strlen($config['api_consumer_secret']));
        
        if ($key !== $config['api_consumer_key'] || $secret !== $config['api_consumer_secret']) {
            error_log("[PIE] API Auth failed: credentials mismatch");
            return false;
        }
        
        return true;
    }
    
    /**
     * دریافت محصول - مستقیماً import بدون pending (دقیقاً مثل فایل آپلود شده)
     */
    public function receive_product(WP_REST_Request $request) {
        try {
            $body = $request->get_body();
            error_log("[PIE API] receive_product called, body length: " . strlen($body));
            
            // JSON می‌تواند array یا object باشد
            $json_data = json_decode($body, true);
            
            if (!$json_data) {
                error_log("[PIE API] JSON decode failed");
                $this->logging->log('api_receive', 'failed', 'خطا در تجزیه JSON');
                return new WP_REST_Response(['success' => false, 'message' => 'JSON decode error'], 400);
            }
            
            // اگر array باشد، اولین محصول را بگیر
            if (is_array($json_data) && isset($json_data[0])) {
                $json_data = $json_data[0];
            }
            
            if (!isset($json_data['name']) || empty($json_data['name'])) {
                error_log("[PIE API] Missing product name");
                $this->logging->log('api_receive', 'failed', 'نام محصول ارسال نشده است');
                return new WP_REST_Response(['success' => false, 'message' => 'نام محصول الزامی است'], 400);
            }
            
            error_log("[PIE API] Product JSON received: {$json_data['name']} (has attributes: " . (isset($json_data['attributes']) ? count($json_data['attributes']) : 0) . ", variations: " . (isset($json_data['variations']) ? count($json_data['variations']) : 0) . ")");
            
            // ⭐ مستقیماً import کن - دقیقاً مثل فایل JSON آپلود شده
            $transfer = PIE_Transfer::get_instance();
            $result = $transfer->create_product_from_json($json_data);
            
            if ($result['success']) {
                $this->logging->log('api_receive', 'success', "محصول '{$json_data['name']}' دریافت و آپلود شد (ID: {$result['product_id']}) - Attributes: " . (isset($json_data['attributes']) ? count($json_data['attributes']) : 0) . ", Variations: " . (isset($json_data['variations']) ? count($json_data['variations']) : 0));
                error_log("[PIE API] Product created successfully: ID {$result['product_id']}");
                return new WP_REST_Response(['success' => true, 'message' => 'محصول دریافت و آپلود شد', 'product_id' => $result['product_id']], 200);
            } else {
                error_log("[PIE API] Product creation failed: {$result['error']}");
                $this->logging->log('api_receive', 'failed', "خطا: {$result['error']}");
                return new WP_REST_Response(['success' => false, 'message' => $result['error']], 400);
            }
            
        } catch (Exception $e) {
            error_log("[PIE API] receive_product exception: " . $e->getMessage());
            $this->logging->log('api_receive', 'failed', "خطا: " . $e->getMessage());
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * دریافت محصول از سایت �� و import مستقیم در سایت ۲
     * دقیقاً همان منطق handle_upload - از همان import_products استفاده می‌شود
     */
    public function preview_product(WP_REST_Request $request) {
        try {
            $body = $request->get_body();
            $products = json_decode($body, true);
            
            if (!$products) {
                return new WP_REST_Response(['success' => false, 'message' => 'JSON نامعتبر است'], 400);
            }
            
            // ساختار: آرایه‌ای از محصولات (مثل فایل JSON دستی)
            if (!isset($products[0])) {
                $products = [$products];
            }
            
            $plugin = Product_Import_Export::get_instance();
            $result = $plugin->import_products_public($products);

            $this->logging->log('api_receive', 'success', $result['message']);

            // برگرداندن product_id و variation_ids برای ثبت خودکار mapping در سایت ۱
            return new WP_REST_Response([
                'success'       => true,
                'message'       => $result['message'],
                'product_id'    => $result['product_id']    ?? null,
                'variation_ids' => $result['variation_ids'] ?? [],
            ], 200);
            
        } catch (Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * تأیید و آپلود نهایی محصول
     */
    public function confirm_product(WP_REST_Request $request) {
        try {
            $json_data = $request->get_json_params();
            
            if (!isset($json_data['preview_key'])) {
                error_log("[PIE API] Missing preview_key in confirm");
                $this->logging->log('api_confirm', 'failed', 'preview_key ارسال نشده است');
                return new WP_REST_Response(['success' => false, 'message' => 'preview_key required'], 400);
            }
            
            // دریافت از transient
            $product_data = get_transient($json_data['preview_key']);
            
            if (!$product_data) {
                error_log("[PIE API] Preview key expired or not found: {$json_data['preview_key']}");
                $this->logging->log('api_confirm', 'failed', 'preview_key منقضی یا پیدا نشد');
                return new WP_REST_Response(['success' => false, 'message' => 'Preview منقضی شده است. دوباره ارسال کنید'], 400);
            }
            
            error_log("[PIE API] Confirming product: {$product_data['name']}");
            
            // ایجاد محصول
            $transfer = PIE_Transfer::get_instance();
            $result = $transfer->create_product_from_json($product_data);
            
            if ($result['success']) {
                // حذف preview
                delete_transient($json_data['preview_key']);
                
                $this->logging->log('api_confirm', 'success', "محصول '{$product_data['name']}' تأیید و آپلود شد (ID: {$result['product_id']})");
                error_log("[PIE API] Product confirmed and created: ID {$result['product_id']}");
                
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'محصول تأیید و آپلود شد',
                    'product_id' => $result['product_id']
                ], 200);
            } else {
                error_log("[PIE API] Product creation failed during confirm: {$result['error']}");
                $this->logging->log('api_confirm', 'failed', "خطا: {$result['error']}");
                return new WP_REST_Response(['success' => false, 'message' => $result['error']], 400);
            }
            
        } catch (Exception $e) {
            error_log("[PIE API] confirm_product exception: " . $e->getMessage());
            $this->logging->log('api_confirm', 'failed', "خطا: " . $e->getMessage());
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * دریافت تغییر موجودی از سایت مقابل و اعمال آن
     *
     * منطق conflict resolution هنگام قطعی هاست:
     *  - اگر موجودی فعلی این سایت کمتر از مقدار دریافتی باشد
     *    مقدار کمتر را برگردان تا سایت فرستنده بتو������ند خودش را هم کاهش دهد
     */
    public function update_stock(WP_REST_Request $request) {
        $body = $request->get_json_params();

        $product_id   = intval($body['product_id']   ?? 0);
        $variation_id = intval($body['variation_id'] ?? 0) ?: null;
        $new_stock    = isset($body['new_stock']) ? intval($body['new_stock']) : null;
        $map_id       = intval($body['map_id']       ?? 0);
        $direction    = sanitize_text_field($body['direction'] ?? '');

        if (!$product_id || $new_stock === null) {
            return new WP_REST_Response(['success' => false, 'message' => 'product_id و new_stock الزامی هستند'], 400);
        }

        // جلوگیری از Ping-Pong:
        // اگر map قفل است و این درخواست از همان جهتی آمده که ما push کردیم،
        // یعنی echo برگشتی sync خودمان است → نادیده بگیر (200).
        // اما اگر درخواست از جهت مخالف آمده (سایت مقابل واقعاً فروخته)،
        // → 423 برمی‌گردانیم تا سایت فرستنده بعد از unlock دوباره امتحان کند.
        if ($map_id) {
            global $wpdb;
            $table_map = $wpdb->prefix . 'pie_stock_map';
            $map_row   = $wpdb->get_row($wpdb->prepare(
                "SELECT is_locked, locked_until, last_sync_direction FROM {$table_map} WHERE id = %d LIMIT 1",
                $map_id
            ));
            if ($map_row && $map_row->is_locked && strtotime($map_row->locked_until) > time()) {
                // تشخیص echo برگشتی:
                // lock توسط همین direction ایجاد شده = ما قبلاً push زدیم و حالا
                // echo برگشتی آن به ما رسیده → نادیده بگیر
                // direction مخالف = سایت مقابل تغییر واقعی داشته → 423 retry
                // last_sync_direction خالی = اولین sync، lock شاید stale باشد → نادیده بگیر
                $last_dir = $map_row->last_sync_direction ?? '';
                $is_echo  = empty($last_dir) || ($last_dir === $direction);
                if ($is_echo) {
                    return new WP_REST_Response([
                        'success' => true,
                        'message' => 'locked: echo برگشتی یا lock بی‌ربط - نادیده گرفته شد',
                    ], 200);
                } else {
                    // سایت مقابل یک تغییر واقعی داشته - بعد از unlock دوباره بفرست
                    $retry_after = strtotime($map_row->locked_until) + 1;
                    return new WP_REST_Response([
                        'success'      => false,
                        'message'      => 'locked: بعد از ' . max(1, $retry_after - time()) . ' ثانیه دوباره امتحان کنید',
                        'retry_after'  => $retry_after,
                        'locked_until' => $map_row->locked_until,
                    ], 423);
                }
            }
        }

        // بارگذاری محصول صحیح (variation یا ساده)
        $target_id = $variation_id ?: $product_id;
        $product   = wc_get_product($target_id);

        if (!$product) {
            return new WP_REST_Response(['success' => false, 'message' => "محصول {$target_id} پیدا نشد"], 404);
        }

        $current_stock   = (int) $product->get_stock_quantity();
        // prev_stock: موجودی مشترک قبل از قطعی هاست که فرستنده ارسال می‌کند
        // اگر ارسال نشده باشد از آن صرف‌نظر می‌کنیم و conflict بررسی نمی‌شود
        $prev_stock      = isset($body['prev_stock']) ? intval($body['prev_stock']) : null;

        // conflict resolution: فقط در سناریوی فروش همزمان در زمان قطعی هاست
        // شرط conflict:
        //   ۱. فرستنده موجودی را کاهش داده (new_stock < prev_stock)
        //   ۲. local هم فروش داشته و از new_stock پایین‌تر رفته (current_stock < new_stock)
        //
        // مثال: prev=10, sender فروخت → new_stock=7, local هم فروخت → current=5
        //   → 5 < 7 = conflict → مقدار کمتر (5) را برمی‌گردانیم
        //
        // sync معمولی (restocking): prev=5, new_stock=10 → new_stock > prev → conflict نیست
        // sync معمولی (کاهش):      prev=10, new_stock=7 → current=10 >= new_stock → conflict نیست
        if (
            $prev_stock !== null          &&
            $new_stock < $prev_stock      &&   // فرستنده کاهش داده
            $current_stock < $new_stock        // local از آن هم پایین‌تر رفته
        ) {
            return new WP_REST_Response([
                'success'       => false,
                'message'       => 'conflict: موجودی local کمتر از مقدار ارسالی است',
                'current_stock' => $current_stock,
            ], 409);
        }

        // قفل map قبل از آپدیت تا hook on_stock_changed دوباره push نکند
        if ($map_id) {
            global $wpdb;
            $table_map = $wpdb->prefix . 'pie_stock_map';
            $wpdb->update($table_map, [
                'is_locked'           => 1,
                'locked_until'        => date('Y-m-d H:i:s', time() + PIE_StockSync::LOCK_TTL),
                'last_sync_direction' => $direction,
            ], ['id' => $map_id]);
        }

        // transient کوتاه‌مدت روی product_id می‌گذاریم.
        // on_stock_changed این transient را می‌خواند و اگر وجود داشت push را skip می‌کند.
        // این مانع echo برگشتی می‌شود حتی اگر lock به هر دلیلی کار نکند.
        $sync_key = 'pie_syncing_' . ($variation_id ?: $product_id);
        // TTL = 5 ثانیه (تا hook اجرا شود و transient هنوز وجود داشته باشد)
        set_transient($sync_key, $new_stock, 5);

        // اعمال موجودی جدید
        // تنبیه: wc_update_product_stock خود hook on_stock_changed را صدا می‌زند
        wc_update_product_stock($product, $new_stock, 'set');

        // transient را نباید فوری پاک کنی - WordPress خود آن را بعد از TTL حذف می‌کند
        // delete_transient($sync_key); ← REMOVED - این باعث race condition می‌شود

        // ثبت در لاگ
        $this->logging->log('stock_receive', 'success',
            "موجودی محصول {$target_id} از {$current_stock} به {$new_stock} تغییر کرد (map_id: {$map_id})"
        );

        return new WP_REST_Response([
            'success'       => true,
            'message'       => 'موجودی آپدیت شد',
            'previous_stock' => $current_stock,
            'new_stock'     => $new_stock,
        ], 200);
    }

    /**
     * لیست محصولات و متغیرهای این سایت برای mapping دستی
     */
    public function list_products(WP_REST_Request $request) {
        $products_wc = wc_get_products([
            'status'  => 'publish',
            'limit'   => 300,
            'type'    => ['simple', 'variable'],
            'orderby' => 'date',
            'order'   => 'DESC',
        ]);

        $result = [];

        foreach ($products_wc as $product) {
            $entry = [
                'product_id' => $product->get_id(),
                'type'       => $product->get_type(),
                'label'      => "#{$product->get_id()} - {$product->get_name()}",
                'variations' => [],
            ];

            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $var_id) {
                    $variation = wc_get_product($var_id);
                    if (!$variation) continue;

                    $attrs = [];
                    foreach ($variation->get_variation_attributes() as $attr => $val) {
                        // urldecode برای نمایش صحیح مقادیر فارسی در سایت مقابل
                        $decoded_val  = urldecode($val);
                        $decoded_attr = urldecode(str_replace('attribute_', '', $attr));
                        $attrs[] = wc_attribute_label($decoded_attr) . ':' . $decoded_val;
                    }

                    $entry['variations'][] = [
                        'id'    => $var_id,
                        'label' => implode(' | ', $attrs) ?: "متغیر #{$var_id}",
                        'stock' => $variation->get_stock_quantity(),
                    ];
                }
            }

            $result[] = $entry;
        }

        return new WP_REST_Response(['products' => $result], 200);
    }

    /**
     * ثبت mapping معکوس روی سایت مقابل
     * وقتی سایت ۱ یک نگاشت ثبت می‌کند، همین endpoint روی سایت ۲ فراخوانی می‌شود
     * تا سایت ۲ هم بتواند تغییرات موجودی خودش را به سایت ۱ push کند
     */
    public function register_map_remote(WP_REST_Request $request) {
        $body = $request->get_json_params();

        $s1_product_id   = intval($body['s1_product_id']   ?? 0);
        $s2_product_id   = intval($body['s2_product_id']   ?? 0);
        $s1_variation_id = intval($body['s1_variation_id'] ?? 0) ?: null;
        $s2_variation_id = intval($body['s2_variation_id'] ?? 0) ?: null;
        $product_name    = sanitize_text_field($body['product_name']    ?? '');
        $variation_attrs = sanitize_text_field($body['variation_attrs'] ?? '');
        $s1_site_url     = esc_url_raw($body['s1_site_url'] ?? '');

        if (!$s1_product_id || !$s2_product_id) {
            return new WP_REST_Response(['success' => false, 'message' => 'product_id الزامی است'], 400);
        }

        // اگر s1_site_url ارسال شده، آن را در تنظیمات سایت ۲ ذخیره کن
        // تا سایت ۲ بداند به کجا push کند
        if ($s1_site_url) {
            $settings = PIE_Settings::get_instance();
            $config   = $settings->get_config();

            // فقط اگر remote_site_url هنوز تنظیم نشده�� آن را ذخیره کن
            // (تا تنظیم دستی کاربر را override نکنیم)
            if (empty($config['remote_site_url'])) {
                $config['remote_site_url'] = $s1_site_url;
                update_option('pie_site_config', $config); // همان option_key که Settings.php استفاده می‌کند
                error_log("[PIE] register_map_remote: remote_site_url auto-set to {$s1_site_url}");
            }
        }

        $stock_sync = PIE_StockSync::get_instance();
        $map_id     = $stock_sync->register_mapping(
            $s1_product_id,
            $s2_product_id,
            $s1_variation_id,
            $s2_variation_id,
            $product_name,
            $variation_attrs
        );

        return new WP_REST_Response([
            'success' => true,
            'map_id'  => $map_id,
            'message' => 'mapping معکوس ثبت شد',
        ], 200);
    }

    /**
     * یافتن محصول بر اساس SKU
     * خروجی: product_id و نگاشت SKU متغیرها → variation_id
     * برای جفت‌سازی مطمئن وقتی پاسخ مستقیم ارسال به‌خاطر timeout گم شده باشد
     */
    public function find_product_by_sku(WP_REST_Request $request) {
        $sku = sanitize_text_field($request->get_param('sku'));
        if (empty($sku)) {
            return new WP_REST_Response(['success' => false, 'message' => 'sku الزامی است'], 400);
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return new WP_REST_Response(['success' => false, 'message' => 'محصول با این SKU پیدا نشد'], 404);
        }

        $variation_ids = [];
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if (!$child) continue;
                $child_sku = $child->get_sku();
                if ($child_sku) {
                    // کلید: SKU متغیر — تا سمت سایت ۱ بتواند با fallback مبتنی بر SKU تطبیق دهد
                    $variation_ids[$child_sku] = $child_id;
                }
            }
        }

        return new WP_REST_Response([
            'success'       => true,
            'product_id'    => $product_id,
            'variation_ids' => $variation_ids,
        ], 200);
    }

    /**
     * دریافت parent_id یک variation — برای جفت‌سازی فایل‌های JSON قدیمی
     * GET /wp-json/pie/v1/product-parent/{variation_id}
     * پاسخ: { parent_id: int, variation_id: int }
     */
    public function get_product_parent(WP_REST_Request $request) {
        $variation_id = intval($request->get_param('variation_id'));

        $post = get_post($variation_id);
        if (!$post) {
            error_log("[PIE API] get_product_parent: variation_id={$variation_id} پیدا نشد");
            return new WP_REST_Response(['success' => false, 'message' => 'variation پیدا نشد'], 404);
        }

        // اگر variation باشد، post_parent = product parent
        if ($post->post_type === 'product_variation') {
            $parent_id = intval($post->post_parent);
            error_log("[PIE API] get_product_parent: variation_id={$variation_id} => parent_id={$parent_id}");
            return new WP_REST_Response([
                'success'      => true,
                'parent_id'    => $parent_id,
                'variation_id' => $variation_id,
            ], 200);
        }

        // اگر خودش product بود (نه variation)، همان ID را برمی‌گردانیم
        if ($post->post_type === 'product') {
            error_log("[PIE API] get_product_parent: id={$variation_id} خودش product است (نه variation)");
            return new WP_REST_Response([
                'success'      => true,
                'parent_id'    => $variation_id,
                'variation_id' => 0,
            ], 200);
        }

        return new WP_REST_Response(['success' => false, 'message' => 'post_type نامعتبر: ' . $post->post_type], 400);
    }

    /**
     * بررسی ارتباط
     */
    public function health_check() {
        $config = $this->settings->get_config();
        return new WP_REST_Response([
            'status' => 'ok',
            'config' => [
                'site_role' => $config['site_role'],
                'plugin_version' => PIE_VERSION
            ]
        ]);
    }

}

// فعال‌سازی
PIE_API::get_instance();
