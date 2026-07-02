<?php
/**
 * تنظیمات پلاگین - انتقال مستقیم بین سایت‌ها
 * Site Configuration & API Keys Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIE_Settings {
    
    private static $instance = null;
    private $option_key = 'pie_site_config';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_pie_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_pie_generate_api_key', [$this, 'ajax_generate_api_key']);
        add_action('wp_ajax_pie_delete_api_key', [$this, 'ajax_delete_api_key']);
        add_action('wp_ajax_pie_get_pending_products', [$this, 'ajax_get_pending_products']);
        add_action('wp_ajax_pie_approve_pending_products', [$this, 'ajax_approve_pending_products']);
        // ✅ تغییر قیمت محصولات موجود
        add_action('wp_ajax_pie_update_existing_product_prices', [$this, 'ajax_update_existing_product_prices']);
    }
    
    /**
     * AJAX: حذف کلید API
     */
    public function ajax_delete_api_key() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        $key_encoded = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        $key = base64_decode($key_encoded);
        
        if (empty($key)) {
            wp_send_json_error('کلید نامعتبر است');
        }
        
        $api_manager = PIE_APIKeyManager::get_instance();
        $api_manager->delete_key($key);
        
        wp_send_json_success(['message' => 'کلید حذف شد']);
    }
    
    /**
     * AJAX: دریافت محصولات منتظر تأیید
     */
    public function ajax_get_pending_products() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        $pending = get_transient('pie_pending_products') ?: [];
        $products = array_values($pending);
        
        wp_send_json_success(['products' => $products]);
    }
    
    /**
     * AJAX: تأیید و آپلود محصولات - دقیق مثل آپلود فایل
     */
    public function ajax_approve_pending_products() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        $pending = get_transient('pie_pending_products') ?: [];
        
        if (empty($pending)) {
            wp_send_json_error('محصولی موجود نیست');
        }
        
        $transfer = PIE_Transfer::get_instance();
        $logging = PIE_Logging::get_instance();
        
        $uploaded_count = 0;
        $failed_count = 0;
        $errors = [];
        
        foreach ($pending as $key => $product_data) {
            try {
                // استفاده از همان متد Transfer برای ایجاد محصول
                // این متد دقیقاً محصول را ایجاد می‌کند مثل JSON دانلودی
                $result = $transfer->create_product_from_json($product_data);
                
                if ($result['success']) {
                    $uploaded_count++;
                    $logging->log('upload', 'success', "محصول '{$product_data['name']}' آپلود شد (از JSON دریافتی)", [
                        'product_name' => $product_data['name'],
                        'product_id' => $result['product_id'],
                        'type' => $product_data['type'] ?? 'simple'
                    ]);
                    // حذف از pending بعد از آپلود موفق
                    unset($pending[$key]);
                } else {
                    $failed_count++;
                    $errors[] = $product_data['name'] . ': ' . $result['error'];
                    $logging->log('upload', 'failed', "خطا در آپلود '{$product_data['name']}': {$result['error']}", [
                        'product_name' => $product_data['name'],
                        'error_code' => 'UPLOAD_FAILED'
                    ]);
                }
            } catch (Exception $e) {
                error_log("[PIE] Error uploading product: " . $e->getMessage());
                $failed_count++;
                $errors[] = ($product_data['name'] ?? 'Unknown') . ': ' . $e->getMessage();
                $logging->log('upload', 'failed', "خطا: {$e->getMessage()}", [
                    'product_name' => $product_data['name'] ?? 'unknown'
                ]);
            }
        }
        
        // ذخیره pending محدث شده (بدون محصولات آپلود شده)
        if (!empty($pending)) {
            set_transient('pie_pending_products', $pending, WEEK_IN_SECONDS);
        } else {
            delete_transient('pie_pending_products');
        }
        
        $message = "{$uploaded_count} محصول آپلود شد";
        if ($failed_count > 0) {
            $message .= ", {$failed_count} ناموفق";
        }
        
        wp_send_json_success([
            'message' => $message,
            'uploaded' => $uploaded_count,
            'failed' => $failed_count,
            'errors' => $errors
        ]);
    }
    
    /**
     * AJAX: تولید کلید API
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        $config = $this->get_config();
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : 'API Key ' . date('Y-m-d H:i');
        
        $api_manager = PIE_APIKeyManager::get_instance();
        $new_key = $api_manager->create_key($name);
        
        wp_send_json_success([
            'key' => $new_key['key'],
            'secret' => $new_key['secret'],
            'name' => $new_key['name']
        ]);
    }
    
    /**
     * AJAX: تغییر قیمت محصولات موجود بر اساس افزایش درصدی جدید
     * 
     * @since 1.5.0
     */
    public function ajax_update_existing_product_prices() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید']);
        }
        
        $config = $this->get_config();
        $markup_percent = intval($_POST['markup_percent'] ?? 0);
        $product_ids = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : [];
        $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
        
        // اگر نه product_ids نه category_ids وارد شود، تمام محصولات منتقل‌شده را update کن
        $args = [
            'post_type' => ['product'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_s2_id',  // محصولاتی که به سایت ۲ منتقل شده‌اند
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        
        // اگر category_ids وارد شده باشد
        if (!empty($category_ids)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => array_map('intval', $category_ids)
                ]
            ];
        }
        
        // اگر product_ids وارد شده باشد (اولویت دارد)
        if (!empty($product_ids)) {
            $args['post__in'] = array_map('intval', $product_ids);
            unset($args['meta_query']); // حذف meta_query اگر product_ids وجود داشته باشد
            // حذف category_ids filter اگر product_ids وجود داشته باشد
            if (isset($args['tax_query'])) {
                unset($args['tax_query']);
            }
        }
        
        $query = new WP_Query($args);
        $product_ids_to_update = $query->posts;
        
        if (empty($product_ids_to_update)) {
            wp_send_json_error([
                'message' => 'محصولی برای تغییر قیمت پیدا نشد',
                'count' => 0
            ]);
        }
        
        $transfer = PIE_Transfer::get_instance();
        $updated_count = 0;
        $failed_count = 0;
        $errors = [];
        
        foreach ($product_ids_to_update as $product_id) {
            try {
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    $failed_count++;
                    continue;
                }
                
                // ساخت آرایه اطلاعات محصول
                $product_data = [
                    'name' => $product->get_name(),
                    'type' => $product->get_type(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price()
                ];
                
                // برای محصولات متغیر
                if ($product->is_type('variable')) {
                    $variations = [];
                    foreach ($product->get_children() as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            $variations[] = [
                                'id' => $variation_id,
                                'regular_price' => $variation->get_regular_price(),
                                'sale_price' => $variation->get_sale_price()
                            ];
                        }
                    }
                    $product_data['variations'] = $variations;
                }
                
                // اعمال افزایش قیمت
                $updated_data = $transfer->apply_price_markup($product_data, $markup_percent);
                
                // به‌روزرسانی قیمت اصلی
                if (isset($updated_data['regular_price']) && $updated_data['regular_price'] != $product->get_regular_price()) {
                    $product->set_regular_price($updated_data['regular_price']);
                }
                
                // به‌روزرسانی قیمت فروش
                if (isset($updated_data['sale_price']) && $updated_data['sale_price'] != $product->get_sale_price()) {
                    $product->set_sale_price($updated_data['sale_price']);
                }
                
                $product->save();
                
                // برای محصولات متغیر
                if ($product->is_type('variable') && isset($updated_data['variations'])) {
                    foreach ($updated_data['variations'] as $variation_update) {
                        $variation = wc_get_product($variation_update['id']);
                        if ($variation) {
                            if (isset($variation_update['regular_price'])) {
                                $variation->set_regular_price($variation_update['regular_price']);
                            }
                            if (isset($variation_update['sale_price'])) {
                                $variation->set_sale_price($variation_update['sale_price']);
                            }
                            $variation->save();
                        }
                    }
                }
                
                $updated_count++;
                error_log("[PIE] Updated price for product ID $product_id with $markup_percent% markup");
                
            } catch (Exception $e) {
                $failed_count++;
                $errors[] = "محصول ID $product_id: " . $e->getMessage();
                error_log("[PIE] Error updating price for product ID $product_id: " . $e->getMessage());
            }
        }
        
        wp_send_json_success([
            'message' => "{$updated_count} محصول به‌روزرسانی شد" . ($failed_count > 0 ? ", {$failed_count} ناموفق" : ''),
            'updated' => $updated_count,
            'failed' => $failed_count,
            'errors' => $errors
        ]);
    }
    
    /**
     * ذخیره تنظیمات از طریق AJAX (برای مطمئن بودن)
     */
    public function ajax_save_settings() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'دسترسی ندارید']);
        }
        
        error_log("[PIE AJAX] POST data received: " . wp_json_encode($_POST));
        
        // ✅ مرحله ۱: بدست آوردن تنظیمات موجود
        $config = $this->get_config();
        
        // ✅ مرحله ۲: به‌روزرسانی تنظیمات جدید
        // داده‌ها از JavaScript به صورت flat object ارسال می‌شوند، نه nested
        if (isset($_POST['site_role'])) {
            $config['site_role'] = sanitize_text_field($_POST['site_role']);
        }
        if (isset($_POST['remote_site_url'])) {
            $config['remote_site_url'] = esc_url_raw($_POST['remote_site_url']);
        }
        if (isset($_POST['remote_api_key'])) {
            $config['remote_api_key'] = sanitize_text_field($_POST['remote_api_key']);
        }
        if (isset($_POST['remote_api_secret'])) {
            $config['remote_api_secret'] = sanitize_text_field($_POST['remote_api_secret']);
        }
        if (isset($_POST['api_consumer_key'])) {
            $config['api_consumer_key'] = sanitize_text_field($_POST['api_consumer_key']);
        }
        if (isset($_POST['api_consumer_secret'])) {
            $config['api_consumer_secret'] = sanitize_text_field($_POST['api_consumer_secret']);
        }
        $config['auto_upload'] = isset($_POST['auto_upload']) ? 1 : 0;
        if (isset($_POST['sync_direction'])) {
            $config['sync_direction'] = sanitize_text_field($_POST['sync_direction']);
        }
        
        // ✅ افزایش قیمت درصدی
        $config['price_markup_enabled'] = isset($_POST['price_markup_enabled']) && $_POST['price_markup_enabled'] ? 1 : 0;
        if (isset($_POST['price_markup_percent'])) {
            $config['price_markup_percent'] = intval($_POST['price_markup_percent']);
        }
        
        // ✅ نوع سایت برای اعمال markup
        if (isset($_POST['price_markup_site'])) {
            $config['price_markup_site'] = sanitize_text_field($_POST['price_markup_site']);
        }
        
        error_log("[PIE AJAX] Config before save: " . wp_json_encode($config));
        
        // ✅ مرحله ۳: ذخیره در دیتابیس
        $result = update_option($this->option_key, $config);
        
        // ✅ مرحله ۴: تأیید ذخیره
        $saved = get_option($this->option_key);
        
        error_log("[PIE AJAX] Result: " . ($result ? 'updated' : 'same value'));
        error_log("[PIE AJAX] Saved (read back): " . wp_json_encode($saved));
        
        // تأیید اینکه تنظیمات درست ذخیره شدند (حداقل یکی از فیلدها باید مطابق باشد)
        if (is_array($saved)) {
            // بررسی اینکه اساسی ترین فیلدها ذخیره شده‌اند
            $basic_ok = isset($saved['site_role']);
            
            // تنظیمات markup
            $markup_enabled_ok = isset($saved['price_markup_enabled']);
            $markup_percent_ok = isset($saved['price_markup_percent']);
            $markup_site_ok = isset($saved['price_markup_site']);
            
            if ($basic_ok && $markup_enabled_ok && $markup_percent_ok && $markup_site_ok) {
                error_log("[PIE AJAX] Settings saved successfully");
                wp_send_json_success([
                    'message' => 'تنظیمات ذخیره شدند',
                    'config' => [
                        'price_markup_enabled' => $saved['price_markup_enabled'],
                        'price_markup_percent' => $saved['price_markup_percent'],
                        'price_markup_site' => $saved['price_markup_site']
                    ]
                ]);
            } else {
                error_log("[PIE AJAX] Some fields missing. basic_ok=$basic_ok, markup_enabled=$markup_enabled_ok, markup_percent=$markup_percent_ok, markup_site=$markup_site_ok");
                error_log("[PIE AJAX] Saved data: " . wp_json_encode($saved));
                wp_send_json_error([
                    'message' => 'بعضی تنظیمات ذخیره نشدند.'
                ]);
            }
        } else {
            error_log("[PIE AJAX] get_option returned non-array: " . gettype($saved));
            wp_send_json_error([
                'message' => 'خطا در بازیابی تنظیمات.'
            ]);
        }
    }
    
    /**
     * ��بت تنظیمات WordPress
     */
    public function register_settings() {
        register_setting('pie_site_settings', $this->option_key, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'show_in_rest' => false
        ]);
        
        // اضافه کردن فیلدها
        add_settings_section(
            'pie_settings_section',
            'تنظیمات انتقال محصول',
            function() {},
            'pie_site_settings'
        );
    }
    
    /**
     * ایجاد تب تنظیمات
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('سازی دسترسی ندارید');
        }
        
        $config = $this->get_config();
        ?>
        <div class="wrap">
            <h1>⚙️ تنظیمات انتقال محصولات</h1>
            
            <div style="max-width: 900px; margin-top: 20px;">
                <div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; padding: 20px;">
                    
                    <form id="pie-settings-form" method="post">
                        <?php wp_nonce_field('pie_nonce', 'pie_nonce'); ?>
                        
                        <table class="form-table" style="width: 100%;">
                            <tbody>
                                
                                <!-- نوع سایت فعلی -->
                                <tr>
                                    <th scope="row" style="width: 200px;">
                                        <label for="site_role">🌐 نوع سایت فعلی</label>
                                    </th>
                                    <td>
                                        <fieldset>
                                            <label style="margin-right: 20px;">
                                                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[site_role]" 
                                                       value="site1" 
                                                       <?php checked($config['site_role'], 'site1'); ?>>
                                                <strong>سایت ۱ (ارسال‌کننده)</strong> - محصولات از اینجا ارسال می‌شود
                                            </label>
                                            <br><br>
                                            <label>
                                                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[site_role]" 
                                                       value="site2"
                                                       <?php checked($config['site_role'], 'site2'); ?>>
                                                <strong>سایت ۲ (دریافت‌کننده)</strong> - محصولات در اینجا دریافت می‌شود
                                            </label>
                                        </fieldset>
                                        <p class="description" style="margin-top: 10px;">
                                            این انتخاب مشخص می‌کند که این پلاگین در کدام سایت فعال است.
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr style="border-top: 2px solid #ddd;">
                                    <th colspan="2" style="padding: 20px 0 10px;">
                                        <h3 style="margin: 0;">📡 اطلاعات سایت مقابل</h3>
                                    </th>
                                </tr>
                                
                                <!-- URL سایت دیگر -->
                                <tr>
                                    <th scope="row">
                                        <label for="remote_site_url">آدرس URL سایت مقابل</label>
                                    </th>
                                    <td>
                                        <input type="url" 
                                               id="remote_site_url"
                                               name="<?php echo esc_attr($this->option_key); ?>[remote_site_url]" 
                                               value="<?php echo esc_attr($config['remote_site_url']); ?>"
                                               class="regular-text"
                                               placeholder="https://example2.com"
                                               style="direction: ltr; width: 100%; max-width: 400px;">
                                        <p class="description">
                                            برای سایت ۱: URL سایت ۲ را وارد کنید<br>
                                            برای سایت ۲: URL سایت ۱ را وارد کنید
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- کلیدهای API اختصاصی -->
                                <tr>
                                    <th scope="row">
                                        <label for="api_consumer_key">🔑 کلیدهای API</label>
                                    </th>
                                    <td>
                                        <div id="api-keys-section" style="margin-bottom: 15px;">
                                            <?php
                                            $api_manager = PIE_APIKeyManager::get_instance();
                                            $keys = $api_manager->get_keys();
                                            
                                            if (empty($keys)) {
                                                echo '<div style="background: #ffebee; border: 1px solid #ef5350; padding: 15px; border-radius: 5px; color: #c62828;">';
                                                echo '<p style="margin: 0;"><strong>⚠️ هنوز کلید API ایجاد نشده است</strong></p>';
                                                echo '<p style="margin: 5px 0 0 0; font-size: 13px;">دکمه زیر را کلیک کنید تا یک کلید API جدید تولید شود</p>';
                                                echo '</div>';
                                            } else {
                                                echo '<div style="overflow-x: auto;">';
                                                echo '<table style="width: 100%; border-collapse: collapse; margin-bottom: 15px; background: #fff;">';
                                                echo '<thead>';
                                                echo '<tr style="background: #2e7d32; color: white;">';
                                                echo '<th style="padding: 12px; text-align: right; border: 1px solid #ddd;">نام</th>';
                                                echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">وضعیت</th>';
                                                echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">تاریخ ایجاد</th>';
                                                echo '<th style="padding: 12px; text-align: center; border: 1px solid #ddd;">Key (اول)</th>';
                                                echo '</tr>';
                                                echo '</thead>';
                                                echo '<tbody>';
                                                foreach ($keys as $key) {
                                                    $status = $key['active'] ? '✓ فع��ل' : '✗ غیرفعال';
                                                    $status_color = $key['active'] ? '#4caf50' : '#999';
                                                    $key_preview = substr($key['key'], 0, 15) . '...';
                                                    $date = date('Y-m-d H:i', strtotime($key['created_at']));
                                                    $key_encoded = base64_encode($key['key']);
                                                    
                                                    echo "<tr style='border-bottom: 1px solid #ddd; background: " . ($key['active'] ? '#f1f8e9' : '#f5f5f5') . ";'>";
                                                    echo "<td style='padding: 12px; border: 1px solid #ddd; font-weight: 500;'>{$key['name']}</td>";
                                                    echo "<td style='padding: 12px; text-align: center; border: 1px solid #ddd; color: {$status_color}; font-weight: bold;'>{$status}</td>";
                                                    echo "<td style='padding: 12px; text-align: center; border: 1px solid #ddd; font-size: 12px;'>{$date}</td>";
                                                    echo "<td style='padding: 12px; text-align: center; border: 1px solid #ddd; font-family: monospace; font-size: 11px; background: #f9f9f9;'>{$key_preview}</td>";
                                                    echo "<td style='padding: 12px; text-align: center; border: 1px solid #ddd;'>";
                                                    echo "<button type='button' class='delete-api-key-btn button button-small' data-key='{$key_encoded}' style='color: #d32f2f; border-color: #d32f2f;'>حذف</button>";
                                                    echo "</td>";
                                                    echo '</tr>';
                                                }
                                                echo '</tbody>';
                                                echo '</table>';
                                                echo '</div>';
                                            }
                                            ?>
                                        </div>
                                        <button type="button" id="generate-api-key-btn" class="button button-primary" style="margin-top: 15px; padding: 10px 20px; font-size: 14px; background: #2e7d32; border-color: #1b5e20;">
                                            ➕ تولید کلید API جدید
                                        </button>
                                        <p class="description" style="margin-top: 10px;">
                                            برای سایت ۲: یک کلید API جدید تولید کنید و آن را برای سایت ۱ بدهید<br>
                                            برای سایت ۱: کلید API را که از سایت ۲ دریافت کردید در قسمت زیر وارد کنید
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- کلید سایت مقابل -->
                                <tr>
                                    <th scope="row">
                                        <label for="remote_api_key">کلید API سایت مقابل</label>
                                    </th>
                                    <td>
                                        <input type="text" 
                                               id="remote_api_key"
                                               name="<?php echo esc_attr($this->option_key); ?>[remote_api_key]" 
                                               value="<?php echo esc_attr($config['remote_api_key'] ?? ''); ?>"
                                               class="regular-text"
                                               style="width: 100%; max-width: 600px; font-family: monospace;"
                                               placeholder="pie_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                        <p class="description">
                                            کلید API که از سایت مقابل دریافت کردید
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- Secret سایت مقابل -->
                                <tr>
                                    <th scope="row">
                                        <label for="remote_api_secret">Secret API سایت مقابل</label>
                                    </th>
                                    <td>
                                        <input type="password" 
                                               id="remote_api_secret"
                                               name="<?php echo esc_attr($this->option_key); ?>[remote_api_secret]" 
                                               value="<?php echo esc_attr($config['remote_api_secret'] ?? ''); ?>"
                                               class="regular-text"
                                               style="width: 100%; max-width: 600px; font-family: monospace;"
                                               placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                        <p class="description">
                                            Secret API که از سایت مقابل دریافت کردید
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- ✅ مسئله ۲: جهت sync -->
                                <tr style="border-top: 2px solid #ddd;">
                                    <th colspan="2" style="padding: 20px 0 10px;">
                                        <h3 style="margin: 0;">🔄 تنظیمات هماهنگ‌سازی موجودی</h3>
                                    </th>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="sync_direction">جهت هماهنگ‌سازی موجودی</label>
                                    </th>
                                    <td>
                                        <fieldset>
                                            <label style="display: block; margin-bottom: 12px;">
                                                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[sync_direction]" 
                                                       value="bidirectional" 
                                                       <?php checked($config['sync_direction'] ?? 'bidirectional', 'bidirectional'); ?>>
                                                <strong>دوطرفه (پیش‌فرض)</strong>
                                                <span style="display: block; margin-right: 26px; color: #666; font-size: 12px;">
                                                    موجودی از هر دو سایت به یکدیگر اعمال می‌شود
                                                </span>
                                            </label>
                                            <label style="display: block; margin-bottom: 12px;">
                                                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[sync_direction]" 
                                                       value="s1_to_s2"
                                                       <?php checked($config['sync_direction'] ?? 'bidirectional', 's1_to_s2'); ?>>
                                                <strong>فق�� سایت ۱ → سایت ۲</strong>
                                                <span style="display: block; margin-right: 26px; color: #666; font-size: 12px;">
                                                    تغییرات موجودی تنها از سایت ۱ به ۲ اعمال می‌شود
                                                </span>
                                            </label>
                                            <label style="display: block; margin-bottom: 12px;">
                                                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[sync_direction]" 
                                                       value="s2_to_s1"
                                                       <?php checked($config['sync_direction'] ?? 'bidirectional', 's2_to_s1'); ?>>
                                                <strong>فقط سایت ۲ → سایت ۱</strong>
                                                <span style="display: block; margin-right: 26px; color: #666; font-size: 12px;">
                                                    تغییرات موجودی تنها از سایت ۲ به ۱ اعمال می‌شود
                                                </span>
                                            </label>
                                        </fieldset>
                                        <p class="description" style="margin-top: 10px;">
                                            این تنظیم مشخص می‌کند که موجودی در کدام جهت(ها) هماهنگ ��ود.
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- ✅ افزایش قیمت درصدی -->
                                <tr style="border-top: 2px solid #ddd;">
                                    <th colspan="2" style="padding: 20px 0 10px;">
                                        <h3 style="margin: 0;">💰 تنظیمات افزایش قیمت</h3>
                                    </th>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="price_markup_enabled">فعال‌سازی افزایش قیمت</label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" 
                                                   name="<?php echo esc_attr($this->option_key); ?>[price_markup_enabled]" 
                                                   value="1" 
                                                   <?php checked($config['price_markup_enabled'], 1); ?>>
                                            <strong>فعال کردن افزایش قیمت درصدی هنگام انتقال محصول</strong>
                                        </label>
                                        <p class="description" style="margin-top: 10px;">
                                            وقتی فعال باشد، قیمت محصولات در هنگام انتقال از سایت ۱ به سایت ۲ افزایش خواهد یافت.
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="price_markup_percent">درصد افزایش قیمت</label>
                                    </th>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <input type="number" 
                                                   id="price_markup_percent"
                                                   name="<?php echo esc_attr($this->option_key); ?>[price_markup_percent]" 
                                                   value="<?php echo intval($config['price_markup_percent']); ?>"
                                                   class="small-text"
                                                   placeholder="0"
                                                   min="0"
                                                   max="1000"
                                                   style="width: 120px; padding: 8px;">
                                            <span style="color: #666;">درصد</span>
                                        </div>
                                        <p class="description" style="margin-top: 10px;">
                                            <strong>مثال:</strong><br>
                                            اگر 20 را وارد کنید: قیمت $100 به $120 تبدیل می‌شود<br>
                                            این درصد به قیمت اصلی، قیمت فروش، و تمام variations اعمال می‌شود.
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="price_markup_site">اعمال افزایش قیمت در کدام سایت؟</label>
                                    </th>
                                    <td>
                                        <fieldset>
                                            <label style="display: block; margin-bottom: 10px;">
                                                <input type="radio" 
                                                       name="<?php echo esc_attr($this->option_key); ?>[price_markup_site]" 
                                                       value="site1"
                                                       <?php checked(($config['price_markup_site'] ?? 'site2'), 'site1'); ?>>
                                                <strong>سایت ۱</strong> - وقتی محصول از سایت ۱ ارسال می‌شود، قیمت افزایش یابد
                                            </label>
                                            <label style="display: block; margin-bottom: 10px;">
                                                <input type="radio" 
                                                       name="<?php echo esc_attr($this->option_key); ?>[price_markup_site]" 
                                                       value="site2"
                                                       <?php checked(($config['price_markup_site'] ?? 'site2'), 'site2'); ?>>
                                                <strong>سایت ۲</strong> - وقتی محصول به سایت ۲ منتقل می‌شود، قیمت افزایش یابد
                                            </label>
                                        </fieldset>
                                        <p class="description" style="margin-top: 10px;">
                                            اگر سایت ۱ انتخاب کنید: قیمت قبل از ارسال افزایش می‌یابد<br>
                                            اگر سایت ۲ انتخاب کنید: قیمت هنگام دریافت در سایت ۲ افزایش می‌یابد (توصیه‌شده)
                                        </p>
                                    </td>
                                </tr>
                                
                                <tr style="border-top: 2px solid #ddd;">
                                    <th colspan="2" style="padding: 20px 0 10px;">
                                        <h3 style="margin: 0;">⚡ تنظیمات خودکار</h3>
                                    </th>
                                </tr>
                                
                                <!-- آپلود خودکار -->
                                <tr>
                                    <th scope="row">
                                        <label for="auto_upload">🚀 آپلود خودکار در سایت ۲</label>
                                    </th>
                                    <td>
                                        <fieldset>
                                            <input type="hidden" 
                                                   name="<?php echo esc_attr($this->option_key); ?>[auto_upload]" 
                                                   value="0">
                                            <label>
                                                <input type="checkbox" 
                                                       id="auto_upload"
                                                       name="<?php echo esc_attr($this->option_key); ?>[auto_upload]" 
                                                       value="1"
                                                       <?php checked($config['auto_upload'], 1); ?>>
                                                <strong>فعال</strong> - محصولات بعد از ارسال خودکار آپلود شوند
                                            </label>
                                        </fieldset>
                                        <p class="description" style="margin-top: 10px; color: #d63031;">
                                            ⚠️ اگر فعال نباشد، محصولات منتظر تأیید شما می‌مانند
                                        </p>
                                    </td>
                                </tr>
                                
                                <!-- ✅ تغییر قیمت محصولات موجود -->
                                <tr style="border-top: 2px solid #ddd;">
                                    <th colspan="2" style="padding: 20px 0 10px;">
                                        <h3 style="margin: 0;">🔄 تغییر قیمت محصولات موجود</h3>
                                    </th>
                                </tr>
                                
                                <tr>
                                    <th scope="row">
                                        <label for="update_markup_percent">اعمال افزایش قیمت جدید</label>
                                    </th>
                                    <td>
                                        <div style="background: #f0f8ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                                            <p style="margin: 0 0 15px 0;">
                                                <strong>⚡ می‌خواهید قیمت محصولاتی که قبلاً به سایت ۲ منتقل شده‌اند را تغییر دهید؟</strong>
                                            </p>
                                            
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                                <!-- درصد افزایش -->
                                                <div>
                                                    <label style="display: block; margin-bottom: 5px;">
                                                        <strong>درصد افزایش قیمت:</strong>
                                                    </label>
                                                    <input type="number" 
                                                           id="update_markup_percent"
                                                           placeholder="مثال: 20"
                                                           value="<?php echo intval($config['price_markup_percent']); ?>"
                                                           min="0"
                                                           max="1000"
                                                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                                </div>
                                                
                                                <!-- نوع انتخاب -->
                                                <div>
                                                    <label style="display: block; margin-bottom: 5px;">
                                                        <strong>محصولات کدام برای تغییر؟</strong>
                                                    </label>
                                                    <select id="update_selection_type" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                                        <option value="all">تمام محصولات منتقل‌شده</option>
                                                        <option value="category">محصولات یک دسته‌بندی</option>
                                                        <option value="manual">انتخاب دستی</option>
                                                    </select>
                                                </div>
                                            </div>
                                            
                                            <!-- نمایش پویا برای دسته‌بندی -->
                                            <div id="category_selector" style="display: none; margin-bottom: 15px;">
                                                <label style="display: block; margin-bottom: 5px;"><strong>دسته‌بندی را انتخاب کنید:</strong></label>
                                                <div id="category_list" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 4px; background: white;">
                                                    <?php
                                                    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                                                    if (!empty($categories)) {
                                                        foreach ($categories as $cat) {
                                                            echo '<label style="display: block; margin-bottom: 8px;">';
                                                            echo '<input type="checkbox" class="category-checkbox" value="' . $cat->term_id . '">';
                                                            echo ' ' . esc_html($cat->name);
                                                            echo '</label>';
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            
                                            <button type="button" id="update-prices-btn" class="button button-primary" style="padding: 10px 20px; font-size: 14px;">
                                                💾 اعمال تغییر قیمت
                                            </button>
                                            <span id="update-status" style="margin-right: 15px; display: none;"></span>
                                        </div>
                                    </td>
                                </tr>
                                
                            </tbody>
                        </table>
                        
                        <div style="margin-top: 20px;">
                            <button type="button" id="save-settings-btn" class="button button-primary" style="padding: 8px 20px; font-size: 14px;">💾 ذخیره تنظیمات</button>
                            <button type="button" id="test-connection-btn" class="button" style="margin-right: 10px; padding: 8px 20px; font-size: 14px;">🔗 تست اتصال</button>
                            <button type="button" id="test-send-btn" class="button" style="margin-right: 10px; padding: 8px 20px; font-size: 14px;">📤 تست ارسال</button>
                            <span id="test-status" style="margin-right: 10px; display: none;"></span>
                        </div>
                    </form>
                    
                </div>
                
                <!-- معلومات مفید -->
                <div style="margin-top: 30px; background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; border-radius: 3px;">
                    <h3>📘 ��حوه استخراج API Keys از WooCommerce</h3>
                    <ol style="margin: 10px 0; padding-right: 20px;">
                        <li>به پنل مدیریت سایت مقابل رفته، <strong>WooCommerce → Settings → Advanced → REST API</strong> بروید</li>
                        <li>بر روی <strong>Create an API key</strong> کلیک کنید</li>
                        <li><strong>Description</strong>: "انتقال محصول"</li>
                        <li><strong>Permissions</strong>: "Read/Write" (حداقل)</li>
                        <li>کلیدهای <strong>Consumer Key</strong> و <strong>Consumer Secret</strong> را کپی کرده اینجا بسازید</li>
                    </ol>
                </div>
                
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // تولید کلید API جدید
            $('#generate-api-key-btn').on('click', function(e) {
                e.preventDefault();
                
                const $btn = $(this);
                $btn.prop('disabled', true).text('⏳ درحال تولید...');
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_generate_api_key',
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>',
                        name: 'API Key - ' + new Date().toLocaleString('fa-IR')
                    },
                    success: function(response) {
                        if (response.success) {
                            const key = response.data.key;
                            const secret = response.data.secret;
                            const name = response.data.name;
                            
                            let html = '<div style="background: #e8f5e9; border: 2px solid #4caf50; padding: 20px; border-radius: 8px; margin: 20px 0; direction: ltr;">';
                            html += '<h3 style="color: #2e7d32; margin: 0 0 15px 0;">✓ کلید API جدید تولید شد!</h3>';
                            
                            html += '<div style="background: #fff; padding: 15px; border-radius: 5px; margin: 10px 0; border-right: 4px solid #2e7d32;">';
                            html += '<p style="margin: 5px 0;"><strong style="color: #2e7d32;">نام:</strong></p>';
                            html += '<p style="margin: 5px 0; word-break: break-all;">' + name + '</p>';
                            html += '</div>';
                            
                            html += '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #ddd;">';
                            html += '<p style="margin: 5px 0;"><strong>🔑 Key:</strong></p>';
                            html += '<code style="display: block; background: #fff; padding: 12px; border-radius: 3px; word-break: break-all; margin: 8px 0; border: 1px solid #ddd; font-family: monospace; font-size: 12px; color: #2c3e50;">' + key + '</code>';
                            html += '<button type="button" class="copy-key-btn button" data-value="' + key + '" style="margin-top: 5px;">📋 کپی</button>';
                            html += '</div>';
                            
                            html += '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0; border: 1px solid #ddd;">';
                            html += '<p style="margin: 5px 0;"><strong>🔐 Secret:</strong></p>';
                            html += '<code style="display: block; background: #fff; padding: 12px; border-radius: 3px; word-break: break-all; margin: 8px 0; border: 1px solid #ddd; font-family: monospace; font-size: 12px; color: #2c3e50;">' + secret + '</code>';
                            html += '<button type="button" class="copy-secret-btn button" data-value="' + secret + '" style="margin-top: 5px;">📋 کپی</button>';
                            html += '</div>';
                            
                            html += '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 10px 0;">';
                            html += '<p style="margin: 0; color: #856404;">⚠️ <strong>اهم:</strong></p>';
                            html += '<ul style="margin: 5px 0; padding-right: 20px;">';
                            html += '<li>این کلیدها و Secret را کپی کنید</li>';
                            html += '<li>آنها را محفوظ نگهدارید</li>';
                            html += '<li>سایت مقابل را به آنها معرفی کنید</li>';
                            html += '</ul>';
                            html += '</div>';
                            
                            html += '</div>';
                            
                            // حذف موجود
                            $('#api-keys-section').empty().html(html);
                            
                            // دکمه‌های کپی
                            $('.copy-key-btn').on('click', function(e) {
                                e.preventDefault();
                                const value = $(this).attr('data-value');
                                navigator.clipboard.writeText(value).then(() => {
                                    const $btn = $(this);
                                    $btn.text('✓ کپی شد').css('background', '#4caf50').css('color', 'white');
                                    setTimeout(() => {
                                        $btn.text('📋 کپی').css('background', '').css('color', '');
                                    }, 6000);
                                });
                            });
                            
                            $('.copy-secret-btn').on('click', function(e) {
                                e.preventDefault();
                                const value = $(this).attr('data-value');
                                navigator.clipboard.writeText(value).then(() => {
                                    const $btn = $(this);
                                    $btn.text('✓ کپی شد').css('background', '#4caf50').css('color', 'white');
                                    setTimeout(() => {
                                        $btn.text('📋 کپی').css('background', '').css('color', '');
                                    }, 6000);
                                });
                            });
                            
                            // حذف کلید API
                            $(document).on('click', '.delete-api-key-btn', function(e) {
                                e.preventDefault();
                                if (!confirm('آیا مطمئن هستید؟ این کلید حذف خواهد شد')) {
                                    return;
                                }
                                
                                const $btn = $(this);
                                const keyEncoded = $btn.attr('data-key');
                                
                                $btn.prop('disabled', true).text('حذف...');
                                
                                $.ajax({
                                    type: 'POST',
                                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                    data: {
                                        action: 'pie_delete_api_key',
                                        key: keyEncoded,
                                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            alert('کلید API حذف شد');
                                            location.reload();
                                        } else {
                                            alert('خطا: ' + response.data);
                                            $btn.prop('disabled', false).text('حذف');
                                        }
                                    },
                                    error: function() {
                                        alert('خطای شبکه');
                                        $btn.prop('disabled', false).text('حذف');
                                    }
                                });
                            });
                            
                            // بارگزاری مجدد بعد از 5 ثانیه
                            setTimeout(() => {
                                location.reload();
                            }, 5000);
                        } else {
                            alert('خطا: ' + response.data);
                            $btn.prop('disabled', false).text('➕ تولید کلید API جدید');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error:', error);
                        alert('خطای شبکه: ' + error);
                        $btn.prop('disabled', false).text('➕ تولید کلید API جدید');
                    }
                });
            });
            
            // ذخیره تنظیمات
            $('#save-settings-btn').on('click', function() {
                $(this).prop('disabled', true).text('⏳ درحال ذخیره...');
                
                const data = {
                    action: 'pie_save_settings',
                    site_role: $('input[name="pie_site_config[site_role]"]:checked').val(),
                    remote_site_url: $('[name="pie_site_config[remote_site_url]"]').val(),
                    remote_api_key: $('[name="pie_site_config[remote_api_key]"]').val(),
                    remote_api_secret: $('[name="pie_site_config[remote_api_secret]"]').val(),
                    api_consumer_key: $('[name="pie_site_config[api_consumer_key]"]').val(),
                    api_consumer_secret: $('[name="pie_site_config[api_consumer_secret]"]').val(),
                    auto_upload: $('[name="pie_site_config[auto_upload]"]').is(':checked') ? 1 : 0,
                    sync_direction: $('input[name="pie_site_config[sync_direction]"]:checked').val() || 'bidirectional',
                    // ✅ افزایش قیمت درصدی
                    price_markup_enabled: $('[name="pie_site_config[price_markup_enabled]"]').is(':checked') ? 1 : 0,
                    price_markup_percent: parseInt($('[name="pie_site_config[price_markup_percent]"]').val()) || 0,
                    price_markup_site: $('input[name="pie_site_config[price_markup_site]"]:checked').val() || 'site2',
                    nonce: $('[name="pie_nonce"]').val()
                };
                
                console.log('[PIE Settings] Sending data:', data);
                console.log('[PIE Settings] price_markup_enabled checked?', $('[name="pie_site_config[price_markup_enabled]"]').is(':checked'));
                console.log('[PIE Settings] price_markup_percent value:', $('[name="pie_site_config[price_markup_percent]"]').val());
                console.log('[PIE Settings] price_markup_site checked:', $('input[name="pie_site_config[price_markup_site]"]:checked').val());
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: data,
                    success: function(response) {
                        $('#save-settings-btn').prop('disabled', false).text('💾 ذخیره تنظیمات');
                        if (response.success) {
                            alert('✓ تنظیمات با موفقیت ذخیره شدند!');
                            location.reload();
                        } else {
                            // response.data میتواند string یا object باشد
                            let errorMsg = response.data;
                            if (typeof response.data === 'object' && response.data.message) {
                                errorMsg = response.data.message;
                            } else if (typeof response.data === 'object') {
                                errorMsg = JSON.stringify(response.data);
                            }
                            console.error('[PIE Settings Error]', response.data);
                            alert('✗ خطا: ' + errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#save-settings-btn').prop('disabled', false).text('💾 ذخیره تنظیمات');
                        console.error('[PIE AJAX Error]', xhr.responseText);
                        alert('✗ خطای شبکه: ' + error);
                    }
                });
            });
            
            // تست اتصال
            $('#test-connection-btn').on('click', function() {
                const url = jQuery('[name="pie_site_config[remote_site_url]"]').val();
                const key = jQuery('[name="pie_site_config[remote_api_key]"]').val();
                const secret = jQuery('[name="pie_site_config[remote_api_secret]"]').val();
                
                if (!url || !key || !secret) {
                    jQuery('#test-status').html('<span style="color: #f44336;">❌ لطفا تمام فیلد‌ها را پر کنید</span>').show();
                    return;
                }
                
                jQuery('#test-status').html('<span style="color: #2196f3;">⏳ درحال تست...</span>').show();
                
                $.ajax({
                    type: 'GET',
                    url: url.replace(/\/$/, '') + '/wp-json/pie/v1/health-check',
                    headers: {
                        'Authorization': 'Basic ' + btoa(key + ':' + secret)
                    },
                    success: function(response) {
                        jQuery('#test-status').html('<span style="color: #4caf50;">✓ اتصال موفق! (سایت دیگر: ' + response.config.site_role + ')</span>').show();
                    },
                    error: function(xhr) {
                        let msg = 'ارتباط ناموفق';
                        if (xhr.status === 401) {
                            msg = 'API Key نامعتبر است';
                        } else if (xhr.status === 0) {
                            msg = 'نمی‌توان سایت دیگر را دسترسی پیدا کرد (CORS یا خرابی سرور)';
                        }
                        jQuery('#test-status').html('<span style="color: #f44336;">✗ خطا: ' + msg + '</span>').show();
                    }
                });
            });
            
            // تست ارسال
            $('#test-send-btn').on('click', function() {
                jQuery('#test-status').html('<span style="color: #2196f3;">⏳ درحال تست ارسال...</span>').show();
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_test_send',
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            let html = '<div style="background: #e8f5e9; border: 1px solid #4caf50; padding: 15px; border-radius: 5px; text-align: right;">';
                            html += '<h4 style="color: #2e7d32; margin-top: 0;">تست موفق بود!</h4>';
                            html += '<pre style="background: #fff; padding: 10px; border-radius: 3px; overflow-x: auto; font-size: 12px;">';
                            html += JSON.stringify(response.data, null, 2);
                            html += '</pre></div>';
                            jQuery('#test-status').html(html).show();
                        } else {
                            jQuery('#test-status').html('<span style="color: #f44336;">✗ خطا: ' + response.data + '</span>').show();
                        }
                    },
                    error: function() {
                        jQuery('#test-status').html('<span style="color: #f44336;">✗ خطای شبکه</span>').show();
                    }
                });
            });
            
            // ✅ تغییر قیمت محصولات موجود
            $('#update_selection_type').on('change', function() {
                if ($(this).val() === 'category') {
                    $('#category_selector').show();
                } else {
                    $('#category_selector').hide();
                }
            });
            
            $('#update-prices-btn').on('click', function() {
                const markup_percent = parseInt($('#update_markup_percent').val()) || 0;
                const selection_type = $('#update_selection_type').val();
                
                if (markup_percent < 0 || markup_percent > 1000) {
                    alert('درصد باید بین 0 و 1000 باشد');
                    return;
                }
                
                if (markup_percent === 0) {
                    alert('درصد افزایش را وارد کنید (حداقل 0 یا بالاتر)');
                    return;
                }
                
                let category_ids = [];
                if (selection_type === 'category') {
                    category_ids = $('.category-checkbox:checked').map(function() {
                        return $(this).val();
                    }).get();
                    
                    if (category_ids.length === 0) {
                        alert('حداقل یک دسته‌بندی را انتخاب کنید');
                        return;
                    }
                }
                
                const $btn = $(this);
                $btn.prop('disabled', true).text('⏳ درحال تغییر قیمت‌ها...');
                
                const data = {
                    action: 'pie_update_existing_product_prices',
                    markup_percent: markup_percent,
                    category_ids: category_ids,
                    nonce: $('[name="pie_nonce"]').val()
                };
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: data,
                    success: function(response) {
                        $btn.prop('disabled', false).text('💾 ا��مال تغییر قیمت');
                        
                        if (response.success) {
                            let msg = response.data.message;
                            if (response.data.errors && response.data.errors.length > 0) {
                                msg += '\n\nخطاها:\n' + response.data.errors.join('\n');
                            }
                            alert('✓ ' + msg);
                            // بارگزاری مجدد صفحه
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            alert('✗ خطا: ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $btn.prop('disabled', false).text('💾 اعمال تغییر قیمت');
                        console.error('Error:', error);
                        alert('خطای شبکه: ' + error);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * دریافت تنظیمات
     */
    public function get_config() {
        $default = [
            'site_role' => 'site1',
            'remote_site_url' => '',
            'remote_api_key' => '',
            'remote_api_secret' => '',
            'api_consumer_key' => '',
            'api_consumer_secret' => '',
            'auto_upload' => 0,
            // ✅ مسئله ۲: جهت sync پیش‌فرض
            'sync_direction' => 'bidirectional',
            // ✅ افزایش قیمت درصدی - پیش‌فرض disabled
            'price_markup_enabled' => 0,
            'price_markup_percent' => 0,
            'price_markup_site' => 'site2'
        ];
        
        $config = get_option($this->option_key, []);
        return wp_parse_args($config, $default);
    }
    
    /**
     * پاکسازی تنظیمات
     */
    public function sanitize_settings($input) {
        if (!is_array($input)) {
            return $this->get_config();
        }
        
        // دریافت تنظیمات فعلی
        $current = $this->get_config();
        
        $sanitized = [
            'site_role' => in_array($input['site_role'] ?? '', ['site1', 'site2']) ? $input['site_role'] : $current['site_role'],
            'remote_site_url' => !empty($input['remote_site_url']) ? esc_url_raw($input['remote_site_url']) : $current['remote_site_url'],
            'remote_api_key' => !empty($input['remote_api_key']) ? sanitize_text_field($input['remote_api_key']) : $current['remote_api_key'],
            'remote_api_secret' => !empty($input['remote_api_secret']) ? sanitize_text_field($input['remote_api_secret']) : $current['remote_api_secret'],
            'api_consumer_key' => !empty($input['api_consumer_key']) ? sanitize_text_field($input['api_consumer_key']) : $current['api_consumer_key'],
            'api_consumer_secret' => !empty($input['api_consumer_secret']) ? sanitize_text_field($input['api_consumer_secret']) : $current['api_consumer_secret'],
            'auto_upload' => isset($input['auto_upload']) ? 1 : 0,
            'sync_direction' => in_array($input['sync_direction'] ?? '', ['bidirectional', 's1_to_s2', 's2_to_s1']) ? $input['sync_direction'] : ($current['sync_direction'] ?? 'bidirectional'),
        ];
        
        error_log("[PIE] Settings saved: " . wp_json_encode($sanitized));
        
        return $sanitized;
    }
    
    /**
     * بررسی اتصال به سایت مقابل
     */
    public static function test_connection() {
        $config = self::get_instance()->get_config();
        
        if (empty($config['remote_site_url']) || empty($config['api_consumer_key'])) {
            return [
                'success' => false,
                'message' => 'تنظیمات ناقص هستند'
            ];
        }
        
        // تست API connection
        $response = wp_remote_get(
            $config['remote_site_url'] . '/wp-json/wc/v3/products',
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($config['api_consumer_key'] . ':' . $config['api_consumer_secret'])
                ],
                'timeout' => 5
            ]
        );
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'خطا در اتصال: ' . $response->get_error_message()
            ];
        }
        
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200 && $status !== 201) {
            return [
                'success' => false,
                'message' => 'کد خطا: ' . $status
            ];
        }
        
        return [
            'success' => true,
            'message' => 'اتصال موفق!'
        ];
    }
}

// فعال‌سازی
PIE_Settings::get_instance();
