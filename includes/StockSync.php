<?php
/**
 * سیستم هماهنگ‌سازی موجودی بین دو سایت
 * Stock Synchronization System
 *
 * معماری:
 *  - جدول pie_stock_map   : نگاشت ID محصول/متغیر سایت ۱ به سایت ۲
 *  - جدول pie_stock_queue : صف تغییرات موجودی که باید ارسال شوند
 *
 * جریان push async (هنگام خرید/تغییر موجودی):
 *  woocommerce_reduce_order_stock / woocommerce_product_set_stock → push_stock_change()
 *    → آیتم در صف ذخیره می‌شود
 *    → wp_schedule_single_event (1 ثانیه بعد) بدون block کردن request کاربر فراخوانی می‌شود
 *    → در request جداگانه: اگر موفق: sync انجام شد
 *    → اگر ناموفق (هاست مقابل قطع): در صف با retry_after ذخیره شود
 *
 * جریان retry (Cron هر ۱۵ دقیقه):
 *  → فقط ردیف‌های صف که retry_after گذشته پردازش شوند
 *  → موجودی هر دو طرف خوانده شود
 *  → کمترین موجودی به عنوان مرجع انتخاب شود (conflict resolution)
 *
 * جلوگیری از Ping-Pong:
 *  → قبل از push: is_locked=1 + locked_until=now+30s در جدول map ثبت شود
 *  → سایت گیرنده هنگام آپدیت موجودی، وجود lock را بررسی می‌کند
 *  → اگر lock بود: push برگشتی انجام نمی‌شود
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIE_StockSync {

    private static $instance = null;
    private $settings        = null;
    private $logging         = null;

    /** نام جداول در database */
    const TABLE_MAP   = 'pie_stock_map';
    const TABLE_QUEUE = 'pie_stock_queue';

    /** مدت lock برای جلوگیری از Ping-Pong (ثانیه)
     *  ۵ ثانیه کافی است چون last_sync_direction جهت را تشخیص می‌دهد
     *  و نیازی به lock طولانی نیست */
    const LOCK_TTL = 5;

    /** تعداد دقیقه تا retry بعد از خطا - به‌صورت exponential backoff */
    const RETRY_DELAY_MINUTES = 5;
    
    /** حداکثر تعداد retry تلاش */
    const MAX_RETRY_ATTEMPTS = 10;
    
    /** حداکثر محدودیت exponential backoff (۳ ساعت) */
    const MAX_BACKOFF_MINUTES = 180;

    /** نام Cron event */
    const CRON_HOOK       = 'pie_stock_sync_retry';
    const CRON_HOOK_FAST  = 'pie_stock_sync_fast_retry'; // هر ۱ دقیقه برای retry سریع بعد از lock

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->settings = PIE_Settings::get_instance();
        $this->logging  = PIE_Logging::get_instance();

        // hook کاهش موجودی هنگام ثبت سفارش
        add_action('woocommerce_reduce_order_stock', [$this, 'on_order_stock_reduced'], 10, 1);

        // hook تغییر دستی موجودی در ادمین
        add_action('woocommerce_product_set_stock',   [$this, 'on_stock_changed'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock_changed'], 10, 1);

        // ✅ مسئله ۱: hook برای تمام تغییرات موجودی (افزایشی + کاهشی)
        // این hook وقتی سفارش کنسل شود و موجودی افزایش یابد نیز فراخوانی می‌شود
        add_action('woocommerce_product_stock_set_value', [$this, 'on_stock_changed'], 10, 1);

        // اضافه کردن interval سفارشی ۱۵ دقیقه‌ای
        // باید قبل از wp_schedule_event ثبت شود
        add_filter('cron_schedules', [$this, 'add_cron_interval']);

        // Cron برای retry ناموفق‌ها (هر ۱۵ دقیقه - fallback اصلی)
        add_action(self::CRON_HOOK, [$this, 'process_retry_queue']);

        // Cron سریع برای retry بعد از lock (هر ۱ دقیقه)
        add_action(self::CRON_HOOK_FAST, [$this, 'process_retry_queue']);

        // hook پردازش async - فراخوانی شده از wp_schedule_single_event
        add_action('pie_async_process_queue_item', [$this, 'async_process_queue_item'], 10, 1);

        // ثبت Cron schedule بعد از init تا مطمئن شویم interval ثبت شده است
        add_action('init', [$this, 'maybe_schedule_cron'], 5);

        // AJAX handlers
        add_action('wp_ajax_pie_stock_get_map',      [$this, 'ajax_get_map']);
        add_action('wp_ajax_pie_stock_add_map',      [$this, 'ajax_add_map']);
        add_action('wp_ajax_pie_stock_delete_map',       [$this, 'ajax_delete_map']);
        add_action('wp_ajax_pie_stock_delete_product_maps', [$this, 'ajax_delete_product_maps']);
        add_action('wp_ajax_pie_stock_force_sync',   [$this, 'ajax_force_sync']);
        add_action('wp_ajax_pie_stock_fetch_remote', [$this, 'ajax_fetch_remote_products']);
        add_action('wp_ajax_pie_get_variations',     [$this, 'ajax_get_variations']);
        add_action('wp_ajax_pie_adjust_stock',       [$this, 'ajax_adjust_stock']);
        add_action('wp_ajax_pie_refresh_all_stocks', [$this, 'ajax_refresh_all_stocks']);
    }

    /**
     * ثبت Cron schedule در هنگام init
     * فراخوانی در constructor خطرناک است چون interval filter هنوز اجرا نشده
     */
    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'pie_every_15min', self::CRON_HOOK);
        }
        if (!wp_next_scheduled(self::CRON_HOOK_FAST)) {
            wp_schedule_event(time(), 'pie_every_1min', self::CRON_HOOK_FAST);
        }
    }

    // -------------------------------------------------------------------------
    // ساخت جداول database
    // -------------------------------------------------------------------------

    /**
     * ایجاد جداول در زمان فعال‌سازی پلاگین
     */
    public static function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $map     = $wpdb->prefix . self::TABLE_MAP;
        $queue   = $wpdb->prefix . self::TABLE_QUEUE;

        $sql_map = "CREATE TABLE IF NOT EXISTS {$map} (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            s1_product_id       BIGINT UNSIGNED NOT NULL COMMENT 'Product ID in Site 1',
            s2_product_id       BIGINT UNSIGNED NOT NULL COMMENT 'Product ID in Site 2',
            s1_variation_id     BIGINT UNSIGNED DEFAULT NULL COMMENT 'Variation ID in Site 1 (null = simple)',
            s2_variation_id     BIGINT UNSIGNED DEFAULT NULL COMMENT 'Variation ID in Site 2 (null = simple)',
            product_name        VARCHAR(255)    DEFAULT '' COMMENT 'Product name for display',
            variation_attrs     VARCHAR(500)    DEFAULT '' COMMENT 'e.g. رنگ:قرمز|سایز:40',
            is_locked           TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'Lock during push to prevent Ping-Pong',
            locked_until        DATETIME        DEFAULT NULL,
            last_sync_direction VARCHAR(20)     DEFAULT NULL COMMENT 'Direction of last successful push (s1_to_s2 or s2_to_s1)',
            last_synced_stock   BIGINT          DEFAULT NULL COMMENT 'Last confirmed synced stock',
            last_sync_at        DATETIME        DEFAULT NULL,
            created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_s1_product    (s1_product_id),
            INDEX idx_s1_variation  (s1_variation_id),
            INDEX idx_s2_product    (s2_product_id),
            INDEX idx_s2_variation  (s2_variation_id)
        ) {$charset};";

        $sql_queue = "CREATE TABLE IF NOT EXISTS {$queue} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            map_id          BIGINT UNSIGNED NOT NULL COMMENT 'FK to pie_stock_map',
            new_stock       BIGINT          NOT NULL COMMENT 'New stock quantity to set',
            direction       ENUM('s1_to_s2','s2_to_s1') NOT NULL COMMENT 'Which site pushes to which',
            status          ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            retry_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
            retry_after     DATETIME        DEFAULT NULL COMMENT 'Earliest time for next retry',
            error_message   TEXT            DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_map_id        (map_id),
            INDEX idx_status_retry  (status, retry_after)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_map);
        dbDelta($sql_queue);
    }

    // -------------------------------------------------------------------------
    // Cron
    // -------------------------------------------------------------------------

    public function add_cron_interval($schedules) {
        $schedules['pie_every_15min'] = [
            'interval' => 900,
            'display'  => 'هر ۱۵ دقیقه (Pie Stock Sync)',
        ];
        $schedules['pie_every_1min'] = [
            'interval' => 60,
            'display'  => 'هر ۱ دقیقه (Pie Stock Sync - retry)',
        ];
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Hooks موجودی
    // -------------------------------------------------------------------------

    /**
     * هنگام کاهش موجودی ناشی از سفارش
     * $order شیء WC_Order است
     */
    public function on_order_stock_reduced($order) {
        if (!($order instanceof WC_Order)) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            if ($product->managing_stock()) {
                // شرط: اگر این سفارش ناشی از sync است (transient وجود دارد)، skip کن
                $pid = $product->get_parent_id() ?: $product->get_id();
                if (get_transient('pie_syncing_' . $pid) !== false) {
                    continue;
                }
                // cache WooCommerce را پاک کن تا موجودی جدید خوانده شود
                // نه فقط post cache، بلکه WC cache هم
                clean_post_cache($product->get_id());
                wp_cache_delete('wc_product_' . $product->get_id());
                
                $fresh_product = wc_get_product($product->get_id());
                if ($fresh_product) {
                    $fresh_product->read_meta_data(true); // force read تا موجودی fresh باشد
                    $this->schedule_stock_push($fresh_product);
                }
            } elseif ($product->get_parent_id()) {
                $parent_id = $product->get_parent_id();
                if (get_transient('pie_syncing_' . $parent_id) !== false) {
                    continue;
                }
                clean_post_cache($parent_id);
                wp_cache_delete('wc_product_' . $parent_id);
                
                $parent = wc_get_product($parent_id);
                if ($parent && $parent->managing_stock()) {
                    $parent->read_meta_data(true); // force read
                    $this->schedule_stock_push($parent);
                }
            }
        }
    }

    /**
     * هنگام تغییر دستی موجودی (ادمین)
     * $product شیء WC_Product یا WC_Product_Variation است
     */
    public function on_stock_changed($product) {
        if (!$product || !$product->managing_stock()) {
            return;
        }

        // اگر transient sync وجود داشت، این تغییر از خودمان (از طریق update_stock API) آمده
        // → نباید آن را به سایت مقابل برگردانیم (echo برگشتی)
        // این مطمئن‌ترین روش برای جلوگیری از Ping-Pong است و وابسته به lock نیست
        $product_id = $product->get_parent_id() ?: $product->get_id();
        $sync_key   = 'pie_syncing_' . $product_id;
        if (get_transient($sync_key) !== false) {
            return;
        }
        // برای variation هم چک کن
        if ($product->get_parent_id()) {
            $var_sync_key = 'pie_syncing_' . $product->get_id();
            if (get_transient($var_sync_key) !== false) {
                return;
            }
        }

        $this->schedule_stock_push($product);
    }

    /**
     * ایجاد یک push در صف
     * اگر صف قبلاً برای این map_id وجود داشت، آن را بروز کن (نه duplicate)
     */
    private function schedule_stock_push($product) {
        global $wpdb;

        $config = $this->settings->get_config();

        // تعیین direction بر اساس نقش این سایت
        if ($config['site_role'] === 'site1') {
            $direction        = 's1_to_s2';
            $local_product_id = $product->get_parent_id() ?: $product->get_id();
            $local_var_id     = $product->is_type('variation') ? $product->get_id() : null;
            $map_row = $this->get_map_by_s1($local_product_id, $local_var_id);
        } elseif ($config['site_role'] === 'site2') {
            $direction        = 's2_to_s1';
            $local_product_id = $product->get_parent_id() ?: $product->get_id();
            $local_var_id     = $product->is_type('variation') ? $product->get_id() : null;
            $map_row = $this->get_map_by_s2($local_product_id, $local_var_id);
        } else {
            return;
        }

        // ✅ مسئله ۲: بررسی جهت sync - اگر این جهت فعال نیست، skip کن
        $sync_direction = $config['sync_direction'] ?? 'bidirectional';
        if ($sync_direction === 's1_to_s2' && $direction === 's2_to_s1') {
            $this->logging->log('sync', 'debug', "Sync مسدود: تنظیمات فقط یک‌طرفه سایت ۱ → ۲ | {$direction}");
            return;
        } elseif ($sync_direction === 's2_to_s1' && $direction === 's1_to_s2') {
            $this->logging->log('sync', 'debug', "Sync مسدود: تنظیمات فقط یک‌طرفه سایت ۲ → ۱ | {$direction}");
            return;
        }

        if (!$map_row) {
            // این محصول در mapping نیست - نادیده بگیر
            return;
        }

        // بررسی lock برای جلوگیری از Ping-Pong:
        // اگر lock فعال بود یعنی این تغییر ناشی از sync سایت مقابل بوده.
        // اما اگر کاربر در همین لحظه خرید کرده (تغییر واقعی)، نباید آن را از دست بدهیم.
        // راه‌حل: در صف می‌گذاریم ولی retry_after را بعد از اتمام lock تنظیم می‌کنیم
        // تا Cron بعد از unlock آن را پردازش کند.
        $is_ping_pong_locked = ($map_row->is_locked && strtotime($map_row->locked_until) > time());

        // موجودی را از DB مستقیماً بخوان تا cache قدیمی مشکل نسازد
        $product->read_meta_data(true);
        $new_stock = $product->get_stock_quantity();
        // اگر managing_stock فعال اما مقدار null است (مثلاً parent variable)، skip
        if ($new_stock === null && !$product->managing_stock()) {
            return;
        }
        $new_stock = (int) $new_stock;

        $table_q   = $wpdb->prefix . self::TABLE_QUEUE;

        // اگر قبلاً در صف هست (pending یا failed)، موجودی را بروز کن
        // processing را دست نمی‌زنیم - آن در حال ارسال است و یک آیتم جدید می‌گذاریم
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_q}
             WHERE map_id = %d AND direction = %s AND status IN ('pending','failed')
             ORDER BY created_at DESC LIMIT 1",
            $map_row->id, $direction
        ));

        if ($existing) {
            $wpdb->update($table_q, [
                'new_stock'   => $new_stock,
                'retry_after' => null, // reset کن تا فوری پردازش شود
                'retry_count' => 0,    // یک تغییر واقعیِ جدید است → backoff را از صفر شروع کن
                'status'      => 'pending',
            ], ['id' => $existing->id]);
            $queue_id = $existing->id;
        } else {
            $wpdb->insert($table_q, [
                'map_id'      => $map_row->id,
                'new_stock'   => $new_stock,
                'direction'   => $direction,
                'status'      => 'pending',
                'retry_after' => null,
            ]);
            $queue_id = $wpdb->insert_id;
        }

        // همیشه فوری loopback بزن - حتی اگر lock فعال باشد.
        // process_single_queue_item ������ودش بررسی می‌کند:
        //   - اگر lock از جهت مخالف بود → 423 → چند ثانیه صبر + retry
        //   - اگر lock از همین جهت بود → echo برگشتی → نادیده بگیر
        //   - اگر lock نبود → فوری push کن
        if ($queue_id) {
            $this->trigger_async_processing($queue_id);
        }
    }

    /**
     * اجرای فوری پردازش صف بد����ن وابستگی به ترافیک سایت
     *
     * مشکل قبلی: wp_schedule_single_event فقط هنگام بازدید سایت اجرا می‌شد.
     * سایت ادمین (ترافیک کم) → push هرگز ارسال نمی‌شد.
     *
     * راه‌حل: یک loopback غیرمسدودکننده به endpoint داخلی می‌زنیم تا
     * پردازش بلافاصله در یک پروسه‌ی جدا شروع شود (مستقل از WP-Cron).
     * cron به عنوان fallback باقی می‌ماند.
     */
    /**
     * loopback مستقیم با تأخیر مشخص (بدون WP-Cron)
     * برای retry بعد از 423 Locked استفاده می‌شود
     * @param int $queue_id   شناسه آیتم صف
     * @param int $delay_sec  چند ثانیه تأخیر (max 30)
     */
    private function trigger_async_processing_delayed($queue_id, $delay_sec) {
        $delay_sec = max(1, min(30, (int) $delay_sec));
        $token = $this->get_loopback_token($queue_id); // HMAC ثابت - بدون race condition
        wp_remote_post(rest_url('pie/v1/process-queue-delayed'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode([
                'queue_id'  => $queue_id,
                'token'     => $token,
                'delay_sec' => $delay_sec,
            ]),
        ]);
    }

    private function trigger_async_processing($queue_id) {
        // از یک HMAC ثابت به جای transient token استفاده می‌کنیم
        // این race condition بین چند loopback همزمان را حل می‌کند:
        // هر دو loopback token یکسانی دارند پس هیچ‌کدام 403 نمی‌گیرد
        $token = $this->get_loopback_token($queue_id);

        wp_remote_post(rest_url('pie/v1/process-queue'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode([
                'queue_id' => $queue_id,
                'token'    => $token,
            ]),
        ]);

        // fallback: اگر loopback روی هاست مسدود بود، cron طی حداکثر ۱ دقیقه پردازش می‌کند
        wp_schedule_single_event(time() + 2, 'pie_async_process_queue_item', [$queue_id]);
    }

    /**
     * تولید token ثابت برای loopback با HMAC
     * بر خلاف transient، race condition ندارد چون همیشه یکسان است
     */
    private function get_loopback_token($queue_id) {
        $secret = wp_salt('auth'); // کلید مخفی سرور
        return hash_hmac('sha256', 'pie_queue_' . $queue_id, $secret);
    }

    // -------------------------------------------------------------------------
    // پردازش صف
    // -------------------------------------------------------------------------

    /**
     * پردازش صف retry (Cron)
     * فقط ردیف‌هایی که retry_after گذشته یا null است
     */
    public function process_retry_queue() {
        global $wpdb;

        $table_q = $wpdb->prefix . self::TABLE_QUEUE;
        $now     = current_time('mysql');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table_q}
             WHERE (
                 status IN ('pending','failed')
                 AND (retry_after IS NULL OR retry_after <= %s)
             ) OR (
                 -- آیتم‌های processing که بیش از ۲ دقیقه گیر کرده‌اند (orphaned)
                 status = 'processing'
                 AND updated_at <= DATE_SUB(%s, INTERVAL 120 SECOND)
             )
             ORDER BY created_at ASC
             LIMIT 50",
            $now, $now
        ));

        foreach ($rows as $row) {
            $this->process_single_queue_item($row->id);
        }
    }

    /**
     * callback برای wp_schedule_single_event - اجرای async
     * این متد توسط WordPress Cron در یک request جداگانه فراخوانی می‌شود
     */
    public function async_process_queue_item($queue_id) {
        $this->process_single_queue_item((int) $queue_id);
    }

    /**
     * پردازش یک آیتم از صف
     */
    private function process_single_queue_item($queue_id) {
        global $wpdb;

        $table_q   = $wpdb->prefix . self::TABLE_QUEUE;
        $table_map = $wpdb->prefix . self::TABLE_MAP;

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, m.s1_product_id, m.s2_product_id, m.s1_variation_id, m.s2_variation_id,
                    m.product_name, m.is_locked, m.locked_until, m.last_synced_stock
             FROM {$table_q} q
             JOIN {$table_map} m ON m.id = q.map_id
             WHERE q.id = %d",
            $queue_id
        ));

        if (!$item || $item->status === 'done') {
            return;
        }

        // اگر retry_after هنوز نرسیده، صبر کن - این آیتم هنوز آماده نیست
        if (!empty($item->retry_after) && strtotime($item->retry_after) > time()) {
            return;
        }

        // آیتم‌های processing گیرکرده (orphaned) را بعد از ۲ دقیقه reset کن
        // این حالتی است که loopback وسط کار کرش کر��ه و status = processing مانده
        if ($item->status === 'processing') {
            $updated_at = strtotime($item->updated_at ?? $item->created_at);
            if ((time() - $updated_at) < 120) {
                return; // هنوز در حال پردازش است
            }
            // بیش از ۲ دقیقه processing مانده = orphaned، reset کن
            $wpdb->update($table_q, ['status' => 'pending'], ['id' => $queue_id]);
            $item->status = 'pending';
        }

        // claim اتمیک: فقط اگر هنوز pending/failed است آن را به processing تبدیل کن
        // اگر همزمان loopback و cron fallback اجرا شوند، فقط یکی موفق به claim می‌شود
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_q} SET status = 'processing'
             WHERE id = %d AND status IN ('pending','failed')",
            $queue_id
        ));
        if (!$claimed) {
            // آیتم قبلاً توسط پروسه‌ی دیگری claim شده - رها کن
            return;
        }

        $config = $this->settings->get_config();
        $result = $this->push_stock_to_remote($item, $config);

        if ($result['success']) {
            // ثبت lock ��ر map برای جلوگیری از Ping-Pong
            // last_sync_direction برای تشخیص echo برگشتی از فروش واقع���� سایت مقابل ذخیره می‌شود
            $wpdb->update(
                $table_map,
                [
                    'is_locked'          => 1,
                    'locked_until'       => date('Y-m-d H:i:s', time() + self::LOCK_TTL),
                    'last_sync_direction' => $item->direction,
                    'last_synced_stock'  => $item->new_stock,
                    'last_sync_at'       => current_time('mysql'),
                ],
                ['id' => $item->map_id]
            );

            $wpdb->update($table_q, ['status' => 'done'], ['id' => $queue_id]);

            $this->logging->log('stock_sync', 'success',
                "موجودی '{$item->product_name}' به {$item->new_stock} sync شد (map_id: {$item->map_id})"
            );

        } elseif (!empty($result['conflict'])) {
            // conflict: موجودی سایت مقابل کمتر بود - آن را مرجع قرار بده
            // موجودی local را هم اصلاح کن
            $authoritative = $result['authoritative_stock'];

            // تعیین product/variation local
            if ($item->direction === 's1_to_s2') {
                $local_id = $item->s1_variation_id ?: $item->s1_product_id;
            } else {
                $local_id = $item->s2_variation_id ?: $item->s2_product_id;
            }

            $local_product = wc_get_product($local_id);
            if ($local_product) {
                // lock قبل از set تا hook on_stock_changed دوباره push نزند
                $wpdb->update($table_map, [
                    'is_locked'    => 1,
                    'locked_until' => date('Y-m-d H:i:s', time() + self::LOCK_TTL),
                ], ['id' => $item->map_id]);

                // transient set کن تا hook on_stock_changed نادیده بگیر این تغییر
                $var_id = $local_product->get_id();
                $sync_key = 'pie_syncing_' . $var_id;
                set_transient($sync_key, $authoritative, 5);

                wc_update_product_stock($local_product, $authoritative, 'set');
                // transient خود WordPress حذف می‌کند
            }

            $wpdb->update($table_q, [
                'status'        => 'done',
                'error_message' => $result['error'],
            ], ['id' => $queue_id]);

            // به‌روز کردن last_synced_stock
            $wpdb->update($table_map, [
                'last_synced_stock' => $authoritative,
                'last_sync_at'      => current_time('mysql'),
            ], ['id' => $item->map_id]);

            $this->logging->log('stock_sync', 'success',
                "Conflict resolved برای '{$item->product_name}': موجودی مرجع {$authoritative} در هر دو سایت اعمال شد"
            );

        } elseif (!empty($result['retry_locked'])) {
            // 423: سایت مقابل lock دارد (sync دیگری در جریان است)
            // آیتم را به pending برگردان و بلافاصله یک loopback با تأخیر کوتاه بزن
            // (نه Cron - loopback مستقیم و سریع)
            $unlock_in = max(1, $result['retry_after'] - time());
            $retry_after = date('Y-m-d H:i:s', time() + $unlock_in);

            $wpdb->update($table_q, [
                'status'        => 'pending',
                'retry_after'   => $retry_after,
                'error_message' => $result['error'],
            ], ['id' => $queue_id]);

            // loopback مستقیم با تأخیر unlock_in+1 ثانیه - کاملاً مستقل از Cron
            $this->trigger_async_processing_delayed($queue_id, $unlock_in + 1);

            $this->logging->log('stock_sync', 'retry',
                "sync موجودی '{$item->product_name}' - retry در {$unlock_in} ثانیه (lock سایت مقابل)"
            );

        } else {
            // ناموفق - برنامه‌ریزی retry با exponential backoff
            $retry_count = intval($item->retry_count) + 1;
            
            // محاسبه backoff exponential: 5m, 10m, 20m, 40m, 80m, 160m (3h)...
            // هر بار: min(5 * 2^(n-1), 180) دقیقه، حداکثر 3 ساعت
            $backoff_minutes = min(
                self::RETRY_DELAY_MINUTES * pow(2, $retry_count - 1),
                self::MAX_BACKOFF_MINUTES
            );
            $retry_after = date('Y-m-d H:i:s', time() + ($backoff_minutes * 60));
            
            // اگر تلاش‌ها به حداکثر رسیدند، آیتم را به عنوان "قطعی ناموفق" ثبت کن
            $final_failure = ($retry_count >= self::MAX_RETRY_ATTEMPTS);

            $wpdb->update($table_q, [
                'status'        => $final_failure ? 'failed' : 'pending', // pending تا retry زمان برسد
                'retry_count'   => $retry_count,
                'retry_after'   => $final_failure ? null : $retry_after,
                'error_message' => $result['error'],
            ], ['id' => $queue_id]);

            $log_message = "خطا در sync موجودی '{$item->product_name}': {$result['error']} (retry #{$retry_count}";
            if ($final_failure) {
                $log_message .= " - حداکثر تلاش رسیده‌است)";
            } else {
                $log_message .= " در {$backoff_minutes} دقیقه)";
            }

            $this->logging->log('stock_sync', 'failed',
                $log_message,
                [
                    'product_id'   => ($item->direction === 's1_to_s2' ? $item->s2_product_id : $item->s1_product_id),
                    'product_name' => $item->product_name,
                    'error_code'   => $result['error_code'] ?? 'sync_failed',
                    'details'      => [
                        'map_id'           => $item->map_id,
                        'direction'        => $item->direction,
                        'new_stock'        => $item->new_stock,
                        'retry_count'      => $retry_count,
                        'backoff_minutes'  => $backoff_minutes,
                        'retry_after'      => $final_failure ? 'جدید' : $retry_after,
                        'final_failure'    => $final_failure,
                        'http_code'        => $result['http_code'] ?? null,
                        'error'            => $result['error'] ?? '',
                    ],
                ]
            );
        }
    }

    /**
     * ارسال تغییر موجودی به سایت مقابل از طریق API
     * هنگام قطعی هاست: موجودی هر دو طرف خوانده شود، کمترین مرجع باشد
     */
    private function push_stock_to_remote($item, $config) {
        $remote_url    = rtrim($config['remote_site_url'], '/');
        $api_key       = $config['remote_api_key'];
        $api_secret    = $config['remote_api_secret'];

        if (empty($remote_url) || empty($api_key)) {
            return ['success' => false, 'error' => 'تنظیمات API ناقص است'];
        }

        // تعیین product_id و variation_id در سایت مقصد و محصول محلی (مبدأ)
        if ($item->direction === 's1_to_s2') {
            $remote_product_id   = $item->s2_product_id;
            $remote_variation_id = $item->s2_variation_id;
            $local_id            = $item->s1_variation_id ?: $item->s1_product_id;
        } else {
            $remote_product_id   = $item->s1_product_id;
            $remote_variation_id = $item->s1_variation_id;
            $local_id            = $item->s2_variation_id ?: $item->s2_product_id;
        }

        // ⭐ همیشه موجودی «زندهٔ فعلی» محصول محلی را در لحظهٔ ارسال بخوان،
        // نه مقدار ذخیره‌شده در صف (که ممکن است قدیمی باشد).
        // چرا این حیاتی است:
        //   - اگر هاست مقابل برای مدتی قطع باشد و در این فاصله موجودی محلی
        //     چند بار تغییر کند، یا برای یک محصول چند ردیف در صف ساخته شده باشد،
        //     هر retry که آخر اجرا شود مقدار درست و فعلی را می‌فرستد.
        //   - در نتیجه مقدار قدیمی هرگز رو�� مقدار جدید بازنویسی نمی‌شود و
        //     وضعیت نهایی هر دو سایت همیشه با آخرین تغییر هم‌گرا می‌شود.
        $local_product = wc_get_product($local_id);
        if ($local_product) {
            $local_product->read_meta_data(true);
            $live_stock = $local_product->get_stock_quantity();
            if ($live_stock !== null) {
                // $item->new_stock را به‌روز می‌کنیم تا success branch هم همین مقدار
                // را به‌عنوان last_synced_stock ذخیره کند (سازگاری کامل).
                $item->new_stock = (int) $live_stock;
            }
        }

        $endpoint = $remote_url . '/wp-json/pie/v1/update-stock';
        $payload  = [
            'product_id'   => $remote_product_id,
            'variation_id' => $remote_variation_id,
            'new_stock'    => intval($item->new_stock),
            'map_id'       => $item->map_id,
            'direction'    => $item->direction,
            // موجودی مشترک آخرین sync موفق - برای تشخیص conflict در قطعی هاست
            // اگر null باشد یعنی هنوز sync نشده و conflict بررسی نمی‌شود
            'prev_stock'   => isset($item->last_synced_stock) ? intval($item->last_synced_stock) : null,
        ];

        $response = wp_remote_post($endpoint, [
            // timeout: 60 ثانیه
            // دلایل: DNS resolution تا ۲۰ ثانیه + پردازش API سایت ۲
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode("{$api_key}:{$api_secret}"),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            // خطای شبکه/قطعی هاست مقابل - با کد خطا برای تشخیص دقیق در لاگ
            return [
                'success'    => false,
                'error'      => $response->get_error_message(),
                'error_code' => 'network_' . $response->get_error_code(),
                'http_code'  => 0,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['success'])) {
            return ['success' => true];
        }

        // conflict 409: سایت مقابل موجودی کمتری دارد (در زمان قطعی هاست فروش داشته)
        // موجودی کمتر را به عنوان مرجع می‌گیریم و local را هم اصلاح می‌کنیم
        if ($code === 409 && isset($body['current_stock'])) {
            $authoritative_stock = intval($body['current_stock']);
            return [
                'success'             => false,
                'conflict'            => true,
                'authoritative_stock' => $authoritative_stock,
                'error'               => "Conflict resolved: موجودی مرجع {$authoritative_stock}",
            ];
        }

        // 423 Locked: سایت مقابل در حال پردازش sync است و map قفل است
        // این یعنی سایت مقابل اخیراً یک push موفق انجام داده و lock گذاشته
        // → retry_locked برمی‌گردانیم تا caller بداند باید با delay retry کند
        if ($code === 423) {
            $retry_after = isset($body['retry_after']) ? intval($body['retry_after']) : (time() + self::LOCK_TTL);
            return [
                'success'      => false,
                'retry_locked' => true,
                'retry_after'  => $retry_after,
                'error'        => $body['message'] ?? "locked: سایت مقابل در حال sync است",
            ];
        }

        $error_msg = $body['message'] ?? "HTTP {$code}";
        return [
            'success'    => false,
            'error'      => $error_msg,
            'error_code' => "http_{$code}",
            'http_code'  => $code,
        ];
    }

    // -------------------------------------------------------------------------
    // Mapping helpers
    // -------------------------------------------------------------------------

    private function get_map_by_s1($product_id, $variation_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MAP;

        if ($variation_id) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE s1_product_id = %d AND s1_variation_id = %d LIMIT 1",
                $product_id, $variation_id
            ));
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE s1_product_id = %d AND s1_variation_id IS NULL LIMIT 1",
            $product_id
        ));
    }

    private function get_map_by_s2($product_id, $variation_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MAP;

        if ($variation_id) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE s2_product_id = %d AND s2_variation_id = %d LIMIT 1",
                $product_id, $variation_id
            ));
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE s2_product_id = %d AND s2_variation_id IS NULL LIMIT 1",
            $product_id
        ));
    }

    /**
     * ثبت خودکار mapping هنگام ارسال محصول از سایت ۱ به ۲
     * این متد از Transfer.php فراخوانی می‌شود
     *
     * $s1_product_id  : ID محصول در سایت ۱
     * $s2_product_id  : ID محصول تازه ایجاد شده در سایت ۲ (از response API)
     * $s1_variation_id: ID متغیر در سایت ۱ (null برای محصول ساده)
     * $s2_variation_id: ID متغیر در ��ایت ۲ (null برای محصول ساده)
     */
    public function register_mapping($s1_product_id, $s2_product_id, $s1_variation_id = null, $s2_variation_id = null, $product_name = '', $variation_attrs = '') {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MAP;
        
        // ✅ مسئله ۳: بررسی دقیق - آیا این mapping دقیقاً موجود است؟
        // استفاده از <=> operator برای null-safe comparison
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} 
             WHERE s1_product_id = %d AND s2_product_id = %d
             AND (s1_variation_id <=> %s) AND (s2_variation_id <=> %s)",
            $s1_product_id, $s2_product_id, $s1_variation_id, $s2_variation_id
        ));
        
        if ($existing) {
            // Idempotent: بروز رسانی نام/ویژگی فقط (s2 IDs قبلاً درست تنظیم شده‌اند)
            $wpdb->update($table, [
                'product_name'    => $product_name,
                'variation_attrs' => $variation_attrs,
            ], ['id' => $existing->id]);
            return $existing->id;
        }
        
        $wpdb->insert($table, [
            's1_product_id'   => $s1_product_id,
            's2_product_id'   => $s2_product_id,
            's1_variation_id' => $s1_variation_id,
            's2_variation_id' => $s2_variation_id,
            'product_name'    => $product_name,
            'variation_attrs' => $variation_attrs,
        ]);
        
        return $wpdb->insert_id;
    }

    /**
     * ✅ تابع جدید: ایجاد stock_map برای هماهنگ‌سازی دوطرفه موجودی
     * 
     * این تابع پس از آپلود دستی محصول در سایت ۲ فراخوانی می‌شود
     * تا جفت‌سازی موجودی‌ای دوطرفه ایجاد شود (نه یک‌طرفه).
     * 
     * @param int    $s1_product_id   Product ID in Site 1
     * @param int    $s2_product_id   Product ID in Site 2
     * @param int    $s1_variation_id Variation ID in Site 1 (null for simple)
     * @param int    $s2_variation_id Variation ID in Site 2 (null for simple)
     * @param string $product_name    Product name for logging
     * @param string $direction       Direction of sync (s1_to_s2 or s2_to_s1)
     */
    public static function create_stock_map($s1_product_id, $s2_product_id, $s1_variation_id = null, $s2_variation_id = null, $product_name = '', $direction = 's2_to_s1') {
        global $wpdb;
        
        $instance = self::get_instance();
        $table_map = $wpdb->prefix . self::TABLE_MAP;
        
        // بررسی اینکه آیا این mapping قبلاً وجود دارد
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_map} 
             WHERE s1_product_id = %d 
             AND s2_product_id = %d
             AND s1_variation_id <=> %s
             AND s2_variation_id <=> %s",
            $s1_product_id,
            $s2_product_id,
            $s1_variation_id,
            $s2_variation_id
        ));
        
        if ($existing) {
            error_log("[PIE create_stock_map] Mapping already exists (id={$existing->id})");
            return $existing->id;
        }
        
        // درج mapping جدید
        $result = $wpdb->insert($table_map, [
            's1_product_id'       => $s1_product_id,
            's2_product_id'       => $s2_product_id,
            's1_variation_id'     => $s1_variation_id,
            's2_variation_id'     => $s2_variation_id,
            'product_name'        => $product_name,
            'last_sync_direction' => $direction,
        ]);
        
        if ($result) {
            $map_id = $wpdb->insert_id;
            error_log("[PIE create_stock_map] ✓ Mapping created (id={$map_id}): s1_prod={$s1_product_id} s2_prod={$s2_product_id} direction={$direction}");
            return $map_id;
        } else {
            error_log("[PIE create_stock_map] ✗ Failed to insert mapping");
            return false;
        }
    }

    /**
     * بررسی و unlock کردن map هایی که lock منقضی شده
     */
    public function cleanup_expired_locks() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_MAP;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_locked=0, locked_until=NULL WHERE is_locked=1 AND locked_until < %s",
            current_time('mysql')
        ));
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * دریافت جدول mapping برای نمایش در پنل ادمین
     */
    public function ajax_get_map() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        global $wpdb;
        $table_map   = $wpdb->prefix . self::TABLE_MAP;
        $table_queue = $wpdb->prefix . self::TABLE_QUEUE;

        $rows = $wpdb->get_results(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM {$table_queue} q WHERE q.map_id=m.id AND q.status IN ('pending','failed')) as pending_count
             FROM {$table_map} m
             ORDER BY m.product_name ASC, m.variation_attrs ASC"
        );

        wp_send_json_success(['rows' => $rows]);
    }

    /**
     * افزودن دستی یک ردیف به mapping
     */
    public function ajax_add_map() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        // مقادیر ارسالی از فرم: «local» همان محصول این سایت است
        // (از لیست محلی انتخاب شده) و «remote» محصول سایت مقابل (از دریافت).
        $local_product_id   = intval($_POST['s1_product_id']   ?? 0);
        $remote_product_id  = intval($_POST['s2_product_id']   ?? 0);
        $local_variation_id = intval($_POST['s1_variation_id'] ?? 0) ?: null;
        $remote_variation_id = intval($_POST['s2_variation_id'] ?? 0) ?: null;

        if (!$local_product_id || !$remote_product_id) {
            wp_send_json_error('ID محصول الزامی است');
        }

        $config = $this->settings->get_config();

        // ⭐ جهت‌دهی بر اساس نقش این سایت:
        // ستون s1 همیشه باید محصول سایتِ نقش site1 و ستون s2 محصول سایتِ نقش site2 باشد.
        // به این ترتیب فارغ از اینکه جفت‌سازی روی کدام سایت انجام شود،
        // هر دو سایت ردیف یکسان و درست‌جهت را ذخیره می‌کنند و دیگر لازم نیست
        // کاربر در سایت مقابل هم به‌صورت دستی جفت‌سازی کند.
        if (($config['site_role'] ?? '') === 'site2') {
            // این سایت نقش site2 دارد → محصول محلی در ستون s2 و مقابل در s1
            $s1_product_id   = $remote_product_id;
            $s2_product_id   = $local_product_id;
            $s1_variation_id = $remote_variation_id;
            $s2_variation_id = $local_variation_id;
        } else {
            // پیش‌فرض / site1 → محصول محلی در ستون s1 و مقابل در s2
            $s1_product_id   = $local_product_id;
            $s2_product_id   = $remote_product_id;
            $s1_variation_id = $local_variation_id;
            $s2_variation_id = $remote_variation_id;
        }

        // نام و ویژگی‌ها را از محصول محلی می‌خوانیم (همیشه روی این سایت در دسترس است)
        $product      = wc_get_product($local_product_id);
        $product_name = $product ? $product->get_name() : "محصول #{$local_product_id}";

        $variation_attrs = '';
        if ($local_variation_id) {
            $variation = wc_get_product($local_variation_id);
            if ($variation) {
                $attrs = [];
                foreach ($variation->get_variation_attributes() as $attr => $val) {
                    // urldecode برای ذخیره صحیح مقادیر فارسی در دیتابیس
                    $decoded_val  = urldecode($val);
                    $decoded_attr = urldecode(str_replace('attribute_', '', $attr));
                    $attrs[] = wc_attribute_label($decoded_attr) . ':' . $decoded_val;
                }
                $variation_attrs = implode('|', $attrs);
            }
        }

        $id = $this->register_mapping($s1_product_id, $s2_product_id, $s1_variation_id, $s2_variation_id, $product_name, $variation_attrs);

        // ارسال mapping معکوس به سایت مقابل تا sync دو طرفه کار کند
        // سایت مقابل باید جدول خودش را داشته باشد تا بتواند تغییرات موجودیش را push کند
        // ($config بالاتر از روی نقش سایت خوانده شده است)
        $remote_url = rtrim($config['remote_site_url'], '/');
        $api_key    = $config['remote_api_key'];
        $api_secret = $config['remote_api_secret'];
        $remote_push_error = '';

        if (!empty($remote_url) && !empty($api_key)) {
            $endpoint = $remote_url . '/wp-json/pie/v1/register-map';
            $s1_site_url = get_site_url();
            $response = wp_remote_post($endpoint, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode("{$api_key}:{$api_secret}"),
                ],
                'body' => wp_json_encode([
                    's1_product_id'   => $s1_product_id,
                    's2_product_id'   => $s2_product_id,
                    's1_variation_id' => $s1_variation_id,
                    's2_variation_id' => $s2_variation_id,
                    'product_name'    => $product_name,
                    'variation_attrs' => $variation_attrs,
                    's1_site_url'     => $s1_site_url,
                ]),
            ]);

            if (is_wp_error($response)) {
                $remote_push_error = ' (هشدار: ثبت در سایت ۲ ناموفق بود: ' . $response->get_error_message() . ')';
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    $remote_push_error = ' (هشدار: ثبت در سایت ۲ ناموفق بود: HTTP ' . $code . ')';
                }
            }
        }

        wp_send_json_success(['id' => $id, 'message' => 'mapping ثبت شد' . $remote_push_error]);
    }

    /**
     * حذف یک ردیف از mapping
     */
    public function ajax_delete_map() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        $id = intval($_POST['map_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('ID نامعتبر');
        }

        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TABLE_MAP,   ['id' => $id]);
        $wpdb->delete($wpdb->prefix . self::TABLE_QUEUE, ['map_id' => $id]);

        wp_send_json_success(['message' => 'mapping حذف شد']);
    }

    /**
     * حذف تمام نگاشت‌های یک محصول (حذف گروهی)
     */
    public function ajax_delete_product_maps() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        $s1_product_id = intval($_POST['s1_product_id'] ?? 0);
        if (!$s1_product_id) {
            wp_send_json_error('s1_product_id نامعتبر');
        }

        global $wpdb;
        $table_map   = $wpdb->prefix . self::TABLE_MAP;
        $table_queue = $wpdb->prefix . self::TABLE_QUEUE;

        // پیدا کردن همه map_id های این محصول
        $map_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table_map} WHERE s1_product_id = %d",
            $s1_product_id
        ));

        if (empty($map_ids)) {
            wp_send_json_error('نگاشتی برای این محصول یافت نشد');
        }

        $ids_placeholder = implode(',', array_map('intval', $map_ids));

        // حذف از صف
        $wpdb->query("DELETE FROM {$table_queue} WHERE map_id IN ({$ids_placeholder})");
        // حذف از جدول map
        $wpdb->query("DELETE FROM {$table_map} WHERE id IN ({$ids_placeholder})");

        wp_send_json_success([
            'message' => count($map_ids) . ' نگاشت حذف شد',
            'deleted' => count($map_ids),
        ]);
    }

    /**
     * force sync دستی یک ردیف
     */
    public function ajax_force_sync() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        $map_id = intval($_POST['map_id'] ?? 0);
        if (!$map_id) {
            wp_send_json_error('map_id نامعتبر');
        }

        global $wpdb;
        $table_map = $wpdb->prefix . self::TABLE_MAP;
        $table_q   = $wpdb->prefix . self::TABLE_QUEUE;
        $map_row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_map} WHERE id=%d", $map_id));

        if (!$map_row) {
            wp_send_json_error('mapping پیدا نشد');
        }

        $config = $this->settings->get_config();

        // خواندن موجودی فعلی محصول local
        if ($config['site_role'] === 'site1') {
            $local_id  = $map_row->s1_variation_id ?: $map_row->s1_product_id;
            $direction = 's1_to_s2';
        } else {
            $local_id  = $map_row->s2_variation_id ?: $map_row->s2_product_id;
            $direction = 's2_to_s1';
        }

        $product = wc_get_product($local_id);
        if (!$product) {
            wp_send_json_error('مح��ول local پیدا نشد');
        }

        $new_stock = (int) $product->get_stock_quantity();

        // درج در صف و پردازش فوری
        $wpdb->insert($table_q, [
            'map_id'    => $map_id,
            'new_stock' => $new_stock,
            'direction' => $direction,
            'status'    => 'pending',
        ]);
        $queue_id = $wpdb->insert_id;
        $this->process_single_queue_item($queue_id);

        $item = $wpdb->get_row($wpdb->prepare("SELECT status, error_message FROM {$table_q} WHERE id=%d", $queue_id));

        if ($item->status === 'done') {
            wp_send_json_success(['message' => "sync موجودی {$new_stock} موفق بود"]);
        } else {
            wp_send_json_error($item->error_message ?? 'sync ناموفق بود');
        }
    }

    /**
     * دریافت لیست محصولات سایت مقابل از API (برای mapping دستی)
     */
    public function ajax_fetch_remote_products() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        $config = $this->settings->get_config();
        $remote_url = rtrim($config['remote_site_url'], '/');
        $api_key    = $config['remote_api_key'];
        $api_secret = $config['remote_api_secret'];

        if (empty($remote_url) || empty($api_key)) {
            wp_send_json_error('تنظیمات API ناقص است');
        }

        $endpoint = $remote_url . '/wp-json/pie/v1/list-products';
        $response = wp_remote_get($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$api_key}:{$api_secret}"),
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['products'])) {
            wp_send_json_error('خطا در دریافت از سایت مقابل: HTTP ' . $code);
        }

        wp_send_json_success(['products' => $body['products']]);
    }

    // -------------------------------------------------------------------------
    // رندر صفحه ادمین mapping
    // -------------------------------------------------------------------------

    public function render_mapping_page() {
        $config = $this->settings->get_config();

        // لیست محصولات local
        $local_products = $this->get_local_products_for_select();
        ?>
        <div class="wrap">
            <h1>هماهنگ‌سازی موجودی</h1>
            <p style="color:#555;">
                این صفحه محصولات سایت ۱ و سایت ۲ را به هم نگاشت می‌کند.
                هر بار که موجودی در یک سایت تغییر کند، سایت مقابل به‌روز می‌شود.
            </p>

            <!-- افزودن mapping دستی -->
            <div style="border:1px solid #ddd;padding:20px;border-radius:5px;margin-bottom:25px;background:#fff;">
                <h2 style="margin-top:0;">افزودن نگاشت دستی</h2>
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="padding:8px;width:30%;">
                            <label><strong>محصول / متغیر - این سایت</strong></label><br>
                            <select id="pie-s1-product" style="width:100%;margin-top:4px;">
                                <option value="">انتخاب محصول...</option>
                                <?php foreach ($local_products as $p): ?>
                                    <option value="<?php echo esc_attr($p['product_id']); ?>"
                                            data-type="<?php echo esc_attr($p['type']); ?>">
                                        <?php echo esc_html($p['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="pie-s1-variation" style="width:100%;margin-top:6px;display:none;">
                                <option value="">انتخاب متغیر...</option>
                            </select>
                        </td>
                        <td style="padding:8px;width:10%;text-align:center;font-size:22px;color:#999;">
                            &#8644;
                        </td>
                        <td style="padding:8px;width:30%;">
                            <label><strong>محصول / متغیر - سایت مقابل</strong></label><br>
                            <select id="pie-s2-product" style="width:100%;margin-top:4px;">
                                <option value="">ابتدا دریافت کنید...</option>
                            </select>
                            <select id="pie-s2-variation" style="width:100%;margin-top:6px;display:none;">
                                <option value="">انتخاب متغیر...</option>
                            </select>
                            <button id="pie-fetch-remote-btn" class="button" style="margin-top:6px;">
                                دریافت محصولات سایت ۲
                            </button>
                        </td>
                        <td style="padding:8px;width:30%;text-align:center;">
                            <button id="pie-add-map-btn" class="button button-primary" style="padding:8px 20px;">
                                ثبت نگاشت
                            </button>
                            <span id="pie-add-map-status" style="display:block;margin-top:8px;"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- جدول mapping‌های موجود -->
            <div style="border:1px solid #ddd;padding:20px;border-radius:5px;background:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                    <h2 style="margin:0;">جدول نگاشت‌ها</h2>
                    <button id="pie-reload-map-btn" class="button">بارگذاری مجدد</button>
                </div>
                <div id="pie-map-table-wrapper">
                    <p style="color:#999;">در حال بارگذاری...</p>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            const nonce = '<?php echo wp_create_nonce('pie_nonce'); ?>';

            // --- بارگذاری جدول mapping (گروه‌بندی شده بر اساس محصول) ---
            /**
             * تابع هوشمند برای تازه‌کردن فقط داده‌های جدول بدون بسته شدن accordion
             * بجای rebuild کامل، فقط موجودی و sync time را به‌روز می‌کند
             * اگر موجودی تغییر کرده، آن را با رنگ سبز نمایش می‌دهد
             */
            function refreshMapTableData() {
                $.post(ajaxurl, { action: 'pie_stock_get_map', nonce }, function(res) {
                    if (!res.success || !res.data.rows.length) return;
                    
                    const rows = res.data.rows;
                    rows.forEach(function(newRow) {
                        // برای هر ردیف، ردیف موجود را پیدا کن و موجودی را به‌روز کن
                        const $existingRow = $('tr[data-map-id="' + newRow.id + '"]');
                        if ($existingRow.length) {
                            // ستون موجودی (ستون ۵)
                            const stockCell = $existingRow.find('td:nth-child(5)');
                            const oldStock = stockCell.text();
                            const newStock = newRow.last_synced_stock !== null ? newRow.last_synced_stock.toString() : oldStock;
                            
                            // اگر موجودی تغییر کرده
                            if (oldStock !== newStock) {
                                stockCell.css('background-color', '#c8e6c9');
                                stockCell.text(newStock);
                                
                                // انیمیشن fade out
                                setTimeout(() => {
                                    stockCell.css('transition', 'background-color 1s ease');
                                    stockCell.css('background-color', 'transparent');
                                }, 500);
                            }
                            
                            // آپدیت sync time اگر تغییر کرده
                            const syncCell = $existingRow.find('td:nth-child(4)');
                            const newSyncAt = newRow.last_sync_at ? newRow.last_sync_at.substring(0, 16) : '-';
                            const oldSyncAt = syncCell.text();
                            if (oldSyncAt !== newSyncAt) {
                                syncCell.css('background-color', '#c8e6c9');
                                syncCell.text(newSyncAt);
                                
                                setTimeout(() => {
                                    syncCell.css('transition', 'background-color 1s ease');
                                    syncCell.css('background-color', 'transparent');
                                }, 500);
                            }
                        }
                    });
                });
            }

            // ذخیره state باز/بسته accordion ها
            let openGroups = {};

            function loadMapTable() {
                $('#pie-map-table-wrapper').html('<p style="color:#999;">در حال بارگذاری...</p>');
                $.post(ajaxurl, { action: 'pie_stock_get_map', nonce }, function(res) {
                    if (!res.success) {
                        $('#pie-map-table-wrapper').html('<p style="color:red;">خطا در بارگذاری</p>');
                        return;
                    }
                    const rows = res.data.rows;
                    if (!rows.length) {
                        $('#pie-map-table-wrapper').html('<p style="color:#999;">هیچ نگاشتی ثبت نشده است.</p>');
                        return;
                    }

                    // گروه‌بندی ردیف‌ها بر اساس s1_product_id
                    const groups = {};
                    rows.forEach(function(r) {
                        const key = r.s1_product_id;
                        if (!groups[key]) {
                            groups[key] = { name: r.product_name || ('محصول #' + key), rows: [] };
                        }
                        groups[key].rows.push(r);
                    });

                    let html = '';
                    Object.keys(groups).forEach(function(pid) {
                        const g        = groups[pid];
                        const rowCount = g.rows.length;
                        // مجموع صف‌های pending این ��حصول
                        const totalPending = g.rows.reduce((s, r) => s + (parseInt(r.pending_count) || 0), 0);
                        const pendingBadge = totalPending > 0
                            ? `<span style="background:#ff9800;color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;margin-right:8px;">${totalPending} در صف</span>`
                            : '';
                        const groupId = 'pie-group-' + pid;
                        // بررسی آیا این گروه باید باز باشد
                        const shouldBeOpen = openGroups[pid] === true ? 'block' : 'none';

                        html += `
                        <div style="border:1px solid #e0e0e0;border-radius:5px;margin-bottom:8px;overflow:hidden;">
                            <!-- هدر محصول - کلیک برای باز/بسته شدن -->
                            <div style="display:flex;justify-content:space-between;align-items:center;
                                        padding:12px 16px;background:#f8f9fa;
                                        border-bottom:1px solid #e0e0e0;">
                                <div class="pie-group-header" data-target="${groupId}" data-pid="${pid}"
                                     style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;flex:1;">
                                    <span class="pie-chevron" style="display:inline-block;transition:transform 0.2s;font-size:14px;color:#666;${shouldBeOpen === 'block' ? 'transform:rotate(90deg)' : ''}">&#9654;</span>
                                    <strong style="font-size:14px;">${g.name}</strong>
                                    ${pendingBadge}
                                    <span style="font-size:12px;color:#888;">${rowCount} نگاشت</span>
                                </div>
                                <button class="button button-small pie-delete-product-btn"
                                        data-pid="${pid}"
                                        style="color:#c62828;border-color:#c62828;white-space:nowrap;">
                                    حذف همه
                                </button>
                            </div>

                            <!-- محتوای متغیرها -->
                            <div id="${groupId}" style="display:${shouldBeOpen};">
                                <table class="wp-list-table widefat" style="border-collapse:collapse;border:0;margin:0;">
                                    <thead>
                                        <tr style="background:#fafafa;">
                                            <th style="padding:8px 16px;font-size:12px;font-weight:600;color:#555;">متغیر</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;">سایت ۱ ID</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;">سایت ۲ ID</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;">آخرین sync</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;text-align:center;">موجودی</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;text-align:center;">تنظیم موجودی</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;text-align:center;">صف</th>
                                            <th style="padding:8px 12px;font-size:12px;font-weight:600;color:#555;text-align:center;">عملیات</th>
                                        </tr>
                                    </thead>
                                    <tbody>`;

                        g.rows.forEach(function(r) {
                            const isVar       = r.s1_variation_id;
                            const syncAt      = r.last_sync_at ? r.last_sync_at.substring(0,16) : '-';
                            const varLabel    = r.variation_attrs || (isVar ? 'متغیر #' + r.s1_variation_id : 'ساده');
                            const s1Id        = r.s1_product_id + (isVar ? ' / ' + r.s1_variation_id : '');
                            const s2Id        = r.s2_product_id + (r.s2_variation_id ? ' / ' + r.s2_variation_id : '');
                            const rowPending  = parseInt(r.pending_count) || 0;
                            const rowBadge    = rowPending > 0
                                ? `<span style="background:#ff9800;color:#fff;padding:2px 6px;border-radius:10px;font-size:11px;">${rowPending}</span>`
                                : '<span style="color:#ccc;font-size:11px;">-</span>';

                            html += `
                                        <tr style="border-top:1px solid #f0f0f0;" data-map-id="${r.id}">
                                            <td style="padding:9px 16px;font-size:13px;">${varLabel}</td>
                                            <td style="padding:9px 12px;font-size:12px;color:#555;">${s1Id}</td>
                                            <td style="padding:9px 12px;font-size:12px;color:#555;">${s2Id}</td>
                                            <td style="padding:9px 12px;font-size:12px;color:#777;">${syncAt}</td>
                                            <td style="padding:9px 12px;text-align:center;font-size:12px;">${r.last_synced_stock !== null ? r.last_synced_stock : '-'}</td>
                                            <td style="padding:9px 12px;text-align:center;">
                                                <div style="display:flex;align-items:center;justify-content:center;gap:4px;">
                                                    <button class="button button-small pie-adjust-stock-btn" data-id="${r.id}" data-action="decrease" title="کاهش موجودی" style="padding:2px 6px;font-size:11px;">-</button>
                                                    <input type="number" class="pie-adjust-input" data-id="${r.id}" value="1" min="1" max="9999" style="width:40px;padding:3px;font-size:11px;text-align:center;">
                                                    <button class="button button-small pie-adjust-stock-btn" data-id="${r.id}" data-action="increase" title="افزایش موجودی" style="padding:2px 6px;font-size:11px;">+</button>
                                                </div>
                                            </td>
                                            <td style="padding:9px 12px;text-align:center;">${rowBadge}</td>
                                            <td style="padding:9px 12px;text-align:center;white-space:nowrap;">
                                                <button class="button button-small pie-force-sync-btn" data-id="${r.id}" style="margin-left:4px;">sync</button>
                                                <button class="button button-small pie-delete-map-btn" data-id="${r.id}" style="color:#c62828;border-color:#c62828;">حذف</button>
                                            </td>
                                        </tr>`;
                        });

                        html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>`;
                    });

                    $('#pie-map-table-wrapper').html(html);

                    // رویداد toggle برای هر هدر - بدون استفاده از slideToggle
                    $(document).on('click', '.pie-group-header', function(e) {
                        e.preventDefault();
                        const pid = $(this).data('pid');
                        const targetId = $(this).data('target');
                        const $content = $('#' + targetId);
                        const $chevron = $(this).find('.pie-chevron');
                        const isOpen   = $content.is(':visible');
                        
                        // تنظیم مستقیم display بجای slideToggle
                        $content.css('display', isOpen ? 'none' : 'block');
                        $chevron.css('transform', isOpen ? 'rotate(0deg)' : 'rotate(90deg)');
                        
                        // ذخیره state
                        openGroups[pid] = !isOpen;
                    });
                });
            }
            loadMapTable();
            $('#pie-reload-map-btn').on('click', function() {
                openGroups = {}; // بازنشانی state هنگام کلیک دکمه reload
                loadMapTable();
            });

            // --- دریافت متغیرهای محصول سای�� ۱ ---
            $('#pie-s1-product').on('change', function() {
                const pid = $(this).val();
                const type = $(this).find(':selected').data('type');
                $('#pie-s1-variation').hide().html('<option value="">انتخاب متغیر...</option>');
                if (!pid || type !== 'variable') return;
                $.post(ajaxurl, { action: 'pie_get_variations', product_id: pid, nonce }, function(res) {
                    if (!res.success) return;
                    res.data.variations.forEach(function(v) {
                        $('#pie-s1-variation').append(`<option value="${v.id}">${v.label}</option>`);
                    });
                    $('#pie-s1-variation').show();
                });
            });

            // --- دریافت محصولات سایت ۲ ---
            $('#pie-fetch-remote-btn').on('click', function() {
                $(this).prop('disabled', true).text('در حال دریافت...');
                $.post(ajaxurl, { action: 'pie_stock_fetch_remote', nonce }, function(res) {
                    $('#pie-fetch-remote-btn').prop('disabled', false).text('دریافت محصولات سایت ۲');
                    if (!res.success) { alert('خطا: ' + res.data); return; }
                    const products = res.data.products;
                    $('#pie-s2-product').html('<option value="">انتخاب محصول...</option>');
                    products.forEach(function(p) {
                        $('#pie-s2-product').append(`<option value="${p.product_id}" data-type="${p.type}">${p.label}</option>`);
                    });
                    // ذخیره در حافظه برای دسترسی به متغیرها
                    window._pie_remote_products = products;
                });
            });

            // --- نمایش متغیرهای سایت ۲ ---
            $('#pie-s2-product').on('change', function() {
                const pid = parseInt($(this).val());
                const type = $(this).find(':selected').data('type');
                $('#pie-s2-variation').hide().html('<option value="">انتخاب متغیر...</option>');
                if (!pid || type !== 'variable') return;
                const remoteProducts = window._pie_remote_products || [];
                const product = remoteProducts.find(p => p.product_id == pid);
                if (product && product.variations) {
                    product.variations.forEach(function(v) {
                        $('#pie-s2-variation').append(`<option value="${v.id}">${v.label}</option>`);
                    });
                    $('#pie-s2-variation').show();
                }
            });

            // --- ثبت mapping ---
            $('#pie-add-map-btn').on('click', function() {
                const s1_pid = $('#pie-s1-product').val();
                const s1_vid = $('#pie-s1-variation').is(':visible') ? $('#pie-s1-variation').val() : '';
                const s2_pid = $('#pie-s2-product').val();
                const s2_vid = $('#pie-s2-variation').is(':visible') ? $('#pie-s2-variation').val() : '';

                if (!s1_pid || !s2_pid) { alert('لطفا محصول هر دو سایت را انتخاب کنید'); return; }

                $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'pie_stock_add_map', nonce,
                    s1_product_id: s1_pid, s1_variation_id: s1_vid,
                    s2_product_id: s2_pid, s2_variation_id: s2_vid,
                }, function(res) {
                    $('#pie-add-map-btn').prop('disabled', false);
                    if (res.success) {
                        $('#pie-add-map-status').html('<span style="color:green;">نگاشت ثبت شد</span>');
                        loadMapTable();
                    } else {
                        $('#pie-add-map-status').html('<span style="color:red;">' + res.data + '</span>');
                    }
                });
            });

            // --- force sync ---
            $(document).on('click', '.pie-force-sync-btn', function(e) {
                e.stopPropagation();
                const id = $(this).data('id');
                $(this).prop('disabled', true).text('...');
                $.post(ajaxurl, { action: 'pie_stock_force_sync', map_id: id, nonce }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                    } else {
                        alert('خطا: ' + res.data);
                    }
                    loadMapTable();
                });
            });

            // --- حذف یک mapping ---
            $(document).on('click', '.pie-delete-map-btn', function(e) {
                e.stopPropagation();
                if (!confirm('این نگاشت و صف مرتبط حذف شود؟')) return;
                const id = $(this).data('id');
                $.post(ajaxurl, { action: 'pie_stock_delete_map', map_id: id, nonce }, function(res) {
                    if (res.success) {
                        loadMapTable();
                    } else {
                        alert('خطا: ' + res.data);
                    }
                });
            });

            // --- حذف همه نگاشت‌های یک محصول ---
            $(document).on('click', '.pie-delete-product-btn', function(e) {
                e.stopPropagation(); // از باز/بسته شدن accordion جلوگیری کن
                const pid  = $(this).data('pid');
                const name = $(this).closest('div').find('strong').first().text().trim();
                if (!confirm('تمام نگاشت‌های "' + name + '" حذف شود؟')) return;
                const $btn = $(this).prop('disabled', true).text('در حال حذف...');
                $.post(ajaxurl, { action: 'pie_stock_delete_product_maps', s1_product_id: pid, nonce }, function(res) {
                    $btn.prop('disabled', false).text('حذف همه');
                    if (res.success) {
                        loadMapTable();
                    } else {
                        alert('خطا: ' + res.data);
                    }
                });
            });

            // --- تنظیم موجودی دستی ---
            $(document).on('click', '.pie-adjust-stock-btn', function(e) {
                e.stopPropagation(); // جلوگیری از باز/بسته شدن accordion
                
                const mapId = $(this).data('id');
                const action = $(this).data('action');
                const $input = $(this).closest('div').find('.pie-adjust-input[data-id="' + mapId + '"]');
                const value = parseInt($input.val()) || 1;
                
                const adjustment = action === 'increase' ? value : -value;
                const $btn = $(this);
                const $row = $btn.closest('tr');
                
                $btn.prop('disabled', true).text('...');
                
                $.ajax({
                    type: 'POST',
                    url: ajaxurl,
                    data: {
                        action: 'pie_adjust_stock',
                        map_id: mapId,
                        adjustment: adjustment,
                        nonce: nonce
                    },
                    success: function(res) {
                        $btn.prop('disabled', false).text(action === 'increase' ? '+' : '-');
                        if (res.success) {
                            // نمایش پیام موفقیت بدون refresh صفحه
                            const statusEl = $('<span style="color:green;font-size:12px;margin-right:8px;">✓ ' + res.data.message + '</span>');
                            $btn.closest('div').after(statusEl);
                            setTimeout(() => statusEl.fadeOut(500, function() { $(this).remove(); }), 2000);
                            
                            // به‌روز رسانی موجودی محلی بدون refresh
                            // نمایش موجودی جدید بدون منتظر کردن sync
                            const newStock = res.data.new_stock;
                            const stockCell = $row.find('td:nth-child(5)');
                            
                            // انیمیشن تغییر رنگ برای نشان دادن به‌روز شدن
                            stockCell.css('background-color', '#fff9e6').text(newStock);
                            setTimeout(() => {
                                stockCell.css('background-color', 'transparent');
                            }, 1500);
                            
                            // ۲-۳ ثانیه بعد از تغییر موجودی، موجودی‌های remote را refresh کن
                            // فقط داده‌ها را تازه کن، accordion state حفظ شود
                            setTimeout(() => {
                                // ابتدا موجودی‌های remote را refresh کن
                                $.ajax({
                                    type: 'POST',
                                    url: ajaxurl,
                                    data: {
                                        action: 'pie_refresh_all_stocks',
                                        nonce: nonce
                                    },
                                    success: function() {
                                        // سپس فقط داده‌ها را تازه کن بدون بسته/باز شدن accordion
                                        refreshMapTableData();
                                    }
                                });
                            }, 2500);
                        } else {
                            alert('خطا: ' + res.data);
                            $btn.prop('disabled', false).text(action === 'increase' ? '+' : '-');
                        }
                    },
                    error: function() {
                        alert('خطای شبکه');
                        $btn.prop('disabled', false).text(action === 'increase' ? '+' : '-');
                    }
                });
            });

            // --- تغییر موجودی با فشار Enter در input ---
            $(document).on('keypress', '.pie-adjust-input', function(e) {
                if (e.which === 13) { // Enter key
                    e.stopPropagation();
                    const mapId = $(this).data('id');
                    $(this).closest('div').find('.pie-adjust-stock-btn[data-action="increase"]').click();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: بازخوانی موجودی تمام mappings
     * بعد از تغییر موجودی، این تابع آخرین موجودی‌های remote را دریافت و جدول را به‌روز می‌کند
     */
    public function ajax_refresh_all_stocks() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        global $wpdb;
        
        $table_map = $wpdb->prefix . self::TABLE_MAP;
        $config = $this->settings->get_config();
        $local_role = $config['site_role'] ?? 'site1';

        // دریافت تمام mappings
        $all_maps = $wpdb->get_results("SELECT * FROM {$table_map}");
        if (!$all_maps) {
            wp_send_json_success(['updated' => []]);
            return;
        }

        $updated = [];

        foreach ($all_maps as $map) {
            try {
                // تعیین کدام side local و کدام remote
                $local_product_id = ($local_role === 'site1') ? $map->s1_product_id : $map->s2_product_id;
                $local_var_id = ($local_role === 'site1') ? $map->s1_variation_id : $map->s2_variation_id;

                $remote_product_id = ($local_role === 'site1') ? $map->s2_product_id : $map->s1_product_id;
                $remote_var_id = ($local_role === 'site1') ? $map->s2_variation_id : $map->s1_variation_id;

                // موجودی محلی
                $local_product = wc_get_product($local_var_id ?: $local_product_id);
                $local_stock = $local_product ? (int) $local_product->get_stock_quantity() : 0;

                // موجودی remote را از API دریافت کن
                $remote_stock = $this->get_remote_product_stock($remote_product_id, $remote_var_id);

                // آخرین موجودی sync شده را بررسی کن
                $last_synced = (int) $map->last_synced_stock;

                // اگر remote تغییر کرده، آن را نیز ثبت کن
                if ($remote_stock !== null && $remote_stock !== $last_synced) {
                    // بروزرسانی database
                    $wpdb->update(
                        $table_map,
                        ['last_synced_stock' => $remote_stock],
                        ['id' => $map->id],
                        ['%d'],
                        ['%d']
                    );

                    $updated[] = [
                        'id' => $map->id,
                        'local_stock' => $local_stock,
                        'remote_stock' => $remote_stock,
                        'changed' => true
                    ];
                }

            } catch (Exception $e) {
                error_log('[PIE Stock Sync] خطا در بازخوانی موجودی: ' . $e->getMessage());
            }
        }

        wp_send_json_success(['updated' => $updated, 'count' => count($updated)]);
    }

    /**
     * دریافت متغیرهای یک محصول variable برای dropdown
     */
    public function ajax_get_variations() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $product    = wc_get_product($product_id);

        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error('محصول متغیر پیدا نشد');
        }

        $variations = [];
        foreach ($product->get_children() as $var_id) {
            $variation = wc_get_product($var_id);
            if (!$variation) continue;

            $attrs = [];
            foreach ($variation->get_variation_attributes() as $attr => $val) {
                // urldecode برای نمایش صحیح مقادیر فارسی (مثل %d8%a2%d8%a8%db%8c → آبی)
                $decoded_val  = urldecode($val);
                $decoded_attr = urldecode(str_replace('attribute_', '', $attr));
                $attrs[] = wc_attribute_label($decoded_attr) . ':' . $decoded_val;
            }

            $variations[] = [
                'id'    => $var_id,
                'label' => implode(' | ', $attrs) ?: "متغیر #{$var_id}",
                'stock' => $variation->get_stock_quantity(),
            ];
        }

        wp_send_json_success(['variations' => $variations]);
    }

    /**
     * لیست محصولات local برای dropdown
     */
    /**
     * AJAX: تغییر موجودی دستی برای یک جفت mapping
     * میتوان مقدار را افزایش یا کاهش داد و در هر دو سایت به‌روز می‌شود
     */
    public function ajax_adjust_stock() {
        check_ajax_referer('pie_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }

        global $wpdb;
        
        $map_id = intval($_POST['map_id'] ?? 0);
        $adjustment = intval($_POST['adjustment'] ?? 0); // میتواند مثبت یا منفی باشد
        
        if (!$map_id) {
            wp_send_json_error('ID نگاشت نامعتبر است');
        }
        
        if ($adjustment == 0) {
            wp_send_json_error('مقدار تغییر نمی‌تواند صفر باشد');
        }

        $table_map = $wpdb->prefix . self::TABLE_MAP;
        
        // دریافت معلومات mapping
        $map_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_map} WHERE id = %d",
            $map_id
        ));
        
        if (!$map_row) {
            wp_send_json_error('نگاشت پیدا نشد');
        }

        $config = $this->settings->get_config();
        $local_role = $config['site_role'] ?? 'site1';

        try {
            // تعیین کدام سایت local و کدام remote است
            if ($local_role === 'site1') {
                $local_product_id = $map_row->s1_product_id;
                $local_var_id = $map_row->s1_variation_id;
            } else {
                $local_product_id = $map_row->s2_product_id;
                $local_var_id = $map_row->s2_variation_id;
            }

            // بدست آوردن موجودی فعلی
            $local_product = wc_get_product($local_var_id ?: $local_product_id);
            if (!$local_product) {
                wp_send_json_error('محصول محلی پیدا نشد');
            }

            $current_stock = (int) $local_product->get_stock_quantity();
            $new_stock = max(0, $current_stock + $adjustment); // موجودی نمی‌تواند منفی باشد

            // تنظیم موجودی
            $local_product->set_stock_quantity($new_stock);
            $local_product->save();

            // trigger موجودی برای شروع sync
            $this->schedule_stock_push($local_product);

            wp_send_json_success([
                'message' => sprintf(
                    'موجودی تغییر یافت: %d → %d',
                    $current_stock,
                    $new_stock
                ),
                'old_stock' => $current_stock,
                'new_stock' => $new_stock,
                'adjustment' => $adjustment
            ]);

        } catch (Exception $e) {
            wp_send_json_error('خطا: ' . $e->getMessage());
        }
    }

    /**
     * دریافت موجودی یک محصول از سایت remote
     * از طریق WooCommerce REST API
     */
    private function get_remote_product_stock($product_id, $variation_id = 0) {
        try {
            $config = $this->settings->get_config();
            $remote_site_url = ($config['site_role'] === 'site1') ? $config['site2_url'] : $config['site1_url'];
            $remote_auth = ($config['site_role'] === 'site1') ? $config['site2_auth'] : $config['site1_auth'];

            if (!$remote_site_url || !$remote_auth) {
                return null;
            }

            // استفاده از product ID یا variation ID
            $resource_id = $variation_id ?: $product_id;
            $endpoint = $variation_id ? 
                "/wp-json/wc/v3/products/{$product_id}/variations/{$resource_id}" :
                "/wp-json/wc/v3/products/{$resource_id}";

            $url = rtrim($remote_site_url, '/') . $endpoint;

            $response = wp_remote_get($url, [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($remote_auth),
                ],
            ]);

            if (is_wp_error($response)) {
                return null;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            // برگرداندن موجودی
            return isset($body['stock_quantity']) ? (int) $body['stock_quantity'] : null;

        } catch (Exception $e) {
            error_log('[PIE Stock Sync] خطا در fetch remote stock: ' . $e->getMessage());
            return null;
        }
    }

    private function get_local_products_for_select() {
        $args = [
            'status'   => 'publish',
            'limit'    => 200,
            'type'     => ['simple', 'variable'],
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];
        $products = wc_get_products($args);
        $result   = [];

        foreach ($products as $product) {
            $result[] = [
                'product_id' => $product->get_id(),
                'type'       => $product->get_type(),
                'label'      => "#{$product->get_id()} - {$product->get_name()} ({$product->get_type()})",
            ];
        }

        return $result;
    }
}

// فعال‌سازی
PIE_StockSync::get_instance();
