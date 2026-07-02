<?php
/**
 * Plugin Name: محصول Import/Export (با دسته‌بندی و ویژگی‌ها)
 * Plugin URI: https://example.com/product-import-export
 * Description: دانلود و آپلود سریع محصولات ساده و متغیر - با ایجاد خودکار دسته‌بندی‌ها و ویژگی‌ها
 * Version: 2.0.0
 * Author: شما
 * License: GPL v2 or later
 * WC tested up to: 8.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PIE_VERSION', '3.0.0');
define('PIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIE_PLUGIN_URL', plugin_dir_url(__FILE__));

// فایل‌های جدید
require_once PIE_PLUGIN_DIR . 'includes/APIKeyManager.php';
require_once PIE_PLUGIN_DIR . 'includes/Settings.php';
require_once PIE_PLUGIN_DIR . 'includes/Logging.php';
require_once PIE_PLUGIN_DIR . 'includes/Transfer.php';
require_once PIE_PLUGIN_DIR . 'includes/API.php';
require_once PIE_PLUGIN_DIR . 'includes/TestHelper.php';
require_once PIE_PLUGIN_DIR . 'includes/StockSync.php';

// ساخت جداول هنگام فعال‌سازی
register_activation_hook(__FILE__, function() {
    PIE_StockSync::create_tables();
});

// اگر جداول هنوز ساخته نشده یا آپدیت نیاز دارند، آنها را بساز/آپدیت کن
add_action('plugins_loaded', function() {
    $version_key = 'pie_stock_sync_db_version';
    // نسخه 1.1: افزودن ستون last_sync_direction برای تشخیص echo برگشتی از فروش واقعی
    $current     = '1.1';
    if (get_option($version_key) !== $current) {
        PIE_StockSync::create_tables();
        // migration دستی برای افزودن ستون به جدول موجود (dbDelta ستون جدید را اضافه می‌کند)
        global $wpdb;
        $table = $wpdb->prefix . 'pie_stock_map';
        $col_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'last_sync_direction'");
        if (empty($col_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_sync_direction VARCHAR(20) DEFAULT NULL COMMENT 'Direction of last successful push' AFTER locked_until");
        }
        update_option($version_key, $current);
    }
}, 5); // priority 5 - قبل از init

class Product_Import_Export {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('wp_ajax_pie_download_products', [$this, 'handle_download']);
        add_action('wp_ajax_pie_upload_products', [$this, 'handle_upload']);
        add_action('wp_ajax_pie_get_products_list', [$this, 'handle_get_products_list']);
        add_action('wp_ajax_pie_validate_file', [$this, 'handle_validate_file']);
        
        // AJAX handlers برای انتقال مستقیم (در فایل Transfer.php)
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Product Import/Export نیاز به WooCommerce دارد!</p></div>';
            });
            return;
        }
    }
    
    public function add_menu() {
        // منو اصلی مستقل (جدا از WooCommerce)
        add_menu_page(
            'محصول Import/Export',
            'محصول Import/Export',
            'manage_woocommerce',
            'pie-main',
            [$this, 'render_page'],
            'dashicons-migrate',
            58
        );
        
        // تب تنظیمات
        add_submenu_page(
            'pie-main',
            'تنظیمات انتقال',
            'تنظیمات انتقال',
            'manage_woocommerce',
            'pie-settings',
            [PIE_Settings::get_instance(), 'render_settings_page']
        );
        
        // تب لاگ‌ها
        add_submenu_page(
            'pie-main',
            'لاگ‌های عملیات',
            'لاگ‌های عملیات',
            'manage_woocommerce',
            'pie-logs',
            [PIE_Logging::get_instance(), 'render_logs_page']
        );

        // تب هماهنگ‌سازی موجودی
        add_submenu_page(
            'pie-main',
            'هماهنگ‌سازی موجودی',
            'هماهنگ‌سازی موجودی',
            'manage_woocommerce',
            'pie-stock-sync',
            [PIE_StockSync::get_instance(), 'render_mapping_page']
        );
    }
    
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Import/Export محصولات</h1>
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
                
                <div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px;">
                    <h2>دانلود محصولات</h2>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: inline-block; margin-right: 20px;">
                            <input type="checkbox" id="select-all"> انتخاب همه
                        </label>
                        <label style="display: inline-block; margin-right: 20px;">
                            <input type="radio" name="filter" value="all" checked> همه
                        </label>
                        <label style="display: inline-block; margin-right: 20px;">
                            <input type="radio" name="filter" value="simple"> ساده
                        </label>
                        <label style="display: inline-block;">
                            <input type="radio" name="filter" value="variable"> متغیر
                        </label>
                    </div>
                    
                    <div id="products-loading" style="text-align: center; padding: 20px; display: none;">
                        <p>در حال بارگذاری محصولات...</p>
                    </div>
                    
                    <table id="products-table" style="width: 100%; border-collapse: collapse; display: none;">
                        <thead>
                            <tr style="background: #f9f9f9;">
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd; width: 40px;"></th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd;">نام محصول</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd;">کد (SKU)</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd;">قیمت</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd;">موجودی</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #ddd;">نوع</th>
                            </tr>
                        </thead>
                        <tbody id="products-tbody"></tbody>
                    </table>
                    
                    <div id="products-empty" style="text-align: center; padding: 20px; color: #999;">
                        محصولی موجود نیست
                    </div>
                    
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <p>تعداد انتخاب شده: <strong id="selected-count">0</strong></p>
                    </div>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- بخش ارسال مستقیم (برای سایت ۱) -->
                    <div id="direct-transfer-section" style="border: 1px solid #2196f3; padding: 20px; border-radius: 5px; background: #e3f2fd; display: none;">
                        <h3 style="margin-top: 0; color: #1976d2;">📤 ارسال مستقیم</h3>
                        <p style="color: #555; margin: 10px 0;">محصولات انتخاب‌شده را مستقیم به سایت ۲ بفرستید</p>
                        <button type="button" id="send-direct-btn" class="button button-primary" style="width: 100%; padding: 10px; font-size: 14px;">
                            🚀 ارسال محصولات
                        </button>
                        <span id="send-direct-status" style="display: block; margin-top: 10px; text-align: center; font-size: 13px;"></span>
                    </div>
                    
                    <div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: #fafafa;">
                        <h3>تنظ��������مات دانلود</h3>
                        <form id="download-form">
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 10px; font-weight: bold;">فرمت:</label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="radio" name="format" value="json" checked> JSON
                                </label>
                                <label style="display: block;">
                                    <input type="radio" name="format" value="csv"> CSV
                                </label>
                            </div>
                            <button type="submit" class="button button-primary" style="width: 100%; padding: 10px;">
                                دانلود
                            </button>
                            <span id="download-status" style="display: block; margin-top: 10px; text-align: center;"></span>
                        </form>
                    </div>
                    
                    <!-- محصولات دریافتی (منتظر تأیید) -->
                    <div id="pending-products-section" style="border: 1px solid #ff9800; padding: 20px; border-radius: 5px; background: #fff3e0; display: none;">
                        <h3 style="color: #e65100; margin-top: 0;">📥 محصولات دریافتی (منتظر تأیید)</h3>
                        <div id="pending-products-list" style="margin: 15px 0; max-height: 300px; overflow-y: auto;"></div>
                        <button id="approve-pending-btn" class="button button-primary" style="width: 100%; padding: 10px; background: #ff9800; border-color: #f57c00;">
                            ✓ تأیید و آپلود محصولات دریافتی
                        </button>
                        <span id="pending-status" style="display: block; margin-top: 10px; text-align: center;"></span>
                    </div>
                    
                    <div style="border: 1px solid #ddd; padding: 20px; border-radius: 5px; background: #fafafa;">
                        <h3>آپلود محصولات</h3>
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: bold;">انتخاب فایل:</label>
                            <input type="file" id="upload-file" accept=".json,.csv" style="display: block; margin-bottom: 15px; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;">
                        </div>
                        
                        <button id="validate-btn" class="button button-secondary" style="width: 100%; padding: 10px; margin-bottom: 10px;">
                            ✓ بررسی فایل
                        </button>
                        
                        <div id="validation-results" style="display: none; margin-bottom: 15px; padding: 12px; border-radius: 3px; max-height: 250px; overflow-y: auto; background: #f5f5f5; border: 1px solid #ddd;">
                            <div id="validation-content"></div>
                        </div>
                        
                        <button id="upload-btn" class="button button-primary" style="width: 100%; padding: 10px; margin-bottom: 10px; display: none;">
                            → آپلود محصولات
                        </button>
                        
                        <span id="upload-status" style="display: block; margin-top: 10px; text-align: center;"></span>
                        
                        <div id="upload-progress" style="margin-top: 10px; display: none;">
                            <div style="background: #f0f0f0; border-radius: 3px; overflow: hidden;">
                                <div id="progress-bar" style="height: 20px; background: #0073aa; width: 0%;"></div>
                            </div>
                            <span id="progress-text">0%</span>
                        </div>
                        
                        <div id="upload-messages" style="margin-top: 10px; display: none; background: #e8f5e9; padding: 10px; border-radius: 3px;"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let allProducts = [];
            
            function loadProductsList() {
                $('#products-loading').show();
                $('#products-table').hide();
                $('#products-empty').hide();
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_get_products_list',
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                    },
                    success: function(response) {
                        $('#products-loading').hide();
                        if (response.success && response.data.length > 0) {
                            allProducts = response.data;
                            renderProductsTable(allProducts);
                            $('#products-table').show();
                        } else {
                            $('#products-empty').show();
                        }
                    }
                });
            }
            
            function renderProductsTable(products) {
                const tbody = $('#products-tbody');
                tbody.html('');
                
                products.forEach(function(product) {
                    const row = $('<tr>').css('border-bottom', '1px solid #eee');
                    row.html(`
                        <td style="padding: 10px;">
                            <input type="checkbox" class="product-checkbox" value="${product.id}">
                        </td>
                        <td style="padding: 10px; text-align: right;"><strong>${product.name}</strong></td>
                        <td style="padding: 10px; text-align: right;">${product.sku || '-'}</td>
                        <td style="padding: 10px; text-align: right;">${product.price || '-'}</td>
                        <td style="padding: 10px; text-align: right;">${product.stock_quantity !== null ? product.stock_quantity : '-'}</td>
                        <td style="padding: 10px; text-align: right;">
                            <span style="background: ${product.type === 'simple' ? '#e8f5e9' : '#e3f2fd'}; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                ${product.type === 'simple' ? 'ساده' : 'متغیر'}
                            </span>
                        </td>
                    `);
                    tbody.append(row);
                });
            }
            
            $('#select-all').on('change', function() {
                $('.product-checkbox').prop('checked', $(this).prop('checked'));
                updateSelectedCount();
            });
            
            $('input[name="filter"]').on('change', function() {
                const filter = $(this).val();
                let filtered = allProducts;
                
                if (filter === 'simple') {
                    filtered = allProducts.filter(p => p.type === 'simple');
                } else if (filter === 'variable') {
                    filtered = allProducts.filter(p => p.type === 'variable');
                }
                
                renderProductsTable(filtered);
                updateSelectedCount();
            });
            
            $(document).on('change', '.product-checkbox', updateSelectedCount);
            
            // بررسی نوع سایت
            $.ajax({
                type: 'GET',
                url: '<?php echo rest_url('pie/v1/health-check'); ?>',
                success: function(response) {
                    if (response.config && response.config.site_role === 'site1') {
                        // سایت ۱: فقط بخش ارسال مستقیم نمایش داده می‌شود
                        $('#direct-transfer-section').show();
                    } else if (response.config && response.config.site_role === 'site2') {
                        // سایت ۲: بخش ارسال پنهان�� محصولات منتظر تأیید بارگذاری می‌شوند
                        $('#direct-transfer-section').hide();
                        loadPendingProducts();
                    }
                }
            });
            
            // بارگذاری محصولات دریافتی
            function loadPendingProducts() {
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_get_pending_products',
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data.products.length > 0) {
                            renderPendingProducts(response.data.products);
                            $('#pending-products-section').show();
                        }
                    }
                });
            }
            
            // نمایش محصولات دریافتی
            function renderPendingProducts(products) {
                let html = '';
                products.forEach((product, index) => {
                    html += `
                        <div style="background: #fff; padding: 12px; margin-bottom: 10px; border-radius: 3px; border-right: 4px solid #ff9800;">
                            <div style="font-weight: bold; color: #2c3e50;">${product.name}</div>
                            <div style="font-size: 12px; color: #7f8c8d; margin-top: 3px;">
                                قیمت: ${product.regular_price || '-'} | موجودی: ${product.stock_quantity || '-'}
                            </div>
                        </div>
                    `;
                });
                $('#pending-products-list').html(html);
            }
            
            // تأیید و آپلود محصولات دریافتی
            $('#approve-pending-btn').on('click', function() {
                $(this).prop('disabled', true).text('⏳ در حال آپلود...');
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_approve_pending_products',
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#pending-status').html('<span style="color: #4caf50;">✓ ' + response.data.message + '</span>');
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#pending-status').html('<span style="color: #f44336;">✗ خطا: ' + response.data + '</span>');
                            $('#approve-pending-btn').prop('disabled', false).text('✓ تأیید و آپلود محصولات دریافتی');
                        }
                    },
                    error: function() {
                        $('#pending-status').html('<span style="color: #f44336;">✗ خطای شبکه</span>');
                        $('#approve-pending-btn').prop('disabled', false).text('✓ تأیید و آپلود محصولات دریافتی');
                    }
                });
            });
            
            // دکمه ارسال مستقیم - سایت ۱ محصول را به صف تأیید سایت ۲ می‌فرستد
            $('#send-direct-btn').on('click', function() {
                const selectedIds = [];
                $('.product-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                });
                
                if (selectedIds.length === 0) {
                    alert('لطفا حداقل یک محصول را انتخاب کنید');
                    return;
                }
                
                $(this).prop('disabled', true);

                const total = selectedIds.length;
                let completed = 0;
                let failed = 0;
                const failedIds = [];

                const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                const nonce = '<?php echo wp_create_nonce('pie_nonce'); ?>';

                function renderProgress(currentIndex) {
                    $('#send-direct-status').html(
                        '<span style="color: #1976d2;">در حال ارسال محصول ' + currentIndex + ' از ' + total +
                        '... (موفق: ' + completed + (failed > 0 ? '، ناموفق: ' + failed : '') + ')</span>'
                    );
                }

                // ارسال یک محصول با حداکثر دو تلاش (تلاش مجدد در صورت خطای شبکه)
                function sendOne(productId, attempt) {
                    attempt = attempt || 1;
                    return $.ajax({
                        type: 'POST',
                        url: ajaxUrl,
                        // هر محصول ممکن است تا ۱۲۰ ثانیه در سرور طول بکشد (عکس/متغیر سنگین)
                        timeout: 130000,
                        data: {
                            action: 'pie_send_products',
                            product_ids: productId,
                            nonce: nonce
                        }
                    }).then(function(response) {
                        if (response && response.success) {
                            return true;
                        }
                        return false;
                    }, function() {
                        // خطای شبکه/تایم‌اوت → یک بار دیگر تلاش کن
                        if (attempt < 2) {
                            return sendOne(productId, attempt + 1);
                        }
                        return false;
                    });
                }

                // ارسال پشت‌سرهم (نه موازی) تا سرور و همگام‌سازی موجودی دچار اختلال نشود
                function processNext(i) {
                    if (i >= total) {
                        $('#send-direct-btn').prop('disabled', false);
                        if (failed === 0) {
                            $('#send-direct-status').html('<span style="color: #4caf50;">✓ همه ' + completed + ' محصول ارسال و جفت‌سازی شد. برای تأیید به سایت ۲ مراجعه کنید.</span>');
                        } else {
                            $('#send-direct-status').html('<span style="color: #f44336;">✗ ' + completed + ' موفق، ' + failed + ' ناموفق (IDها: ' + failedIds.join(', ') + ')</span>');
                        }
                        return;
                    }

                    renderProgress(i + 1);

                    sendOne(selectedIds[i]).then(function(ok) {
                        if (ok) {
                            completed++;
                        } else {
                            failed++;
                            failedIds.push(selectedIds[i]);
                        }
                        // مکث کوتاه بین هر ارسال تا فشار روی هاست کم شود
                        setTimeout(function() { processNext(i + 1); }, 400);
                    });
                }

                processNext(0);
            });
            
            function updateSelectedCount() {
                $('#selected-count').text($('.product-checkbox:checked').length);
            }
            
            $('#download-form').on('submit', function(e) {
                e.preventDefault();
                const selectedIds = [];
                $('.product-checkbox:checked').each(function() {
                    selectedIds.push($(this).val());
                });
                
                if (selectedIds.length === 0) {
                    alert('لطفا حداقل یک محصول را انتخاب کنید');
                    return;
                }
                
                const format = $('input[name="format"]:checked').val();
                $('#download-status').text('در حال دانلود...');
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'pie_download_products',
                        format: format,
                        product_ids: selectedIds.join(','),
                        nonce: '<?php echo wp_create_nonce('pie_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            const link = document.createElement('a');
                            link.href = 'data:' + (format === 'json' ? 'application/json' : 'text/csv') + ';charset=utf-8,' + encodeURIComponent(response.data);
                            link.download = 'products-' + new Date().getTime() + '.' + format;
                            link.click();
                            $('#download-status').html('<span style="color: green;">✓ تکمیل شد</span>');
                        } else {
                            $('#download-status').html('<span style="color: red;">✗ خطا: ' + response.data + '</span>');
                        }
                    }
                });
            });
            
            // بررسی فایل
            $('#validate-btn').on('click', function() {
                const file = $('#upload-file')[0].files[0];
                if (!file) {
                    alert('لطفا فایل را انت����اب کنید');
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const content = e.target.result;
                        let products = [];
                        
                        if (file.name.endsWith('.json')) {
                            products = JSON.parse(content);
                        } else if (file.name.endsWith('.csv')) {
                            products = parseCSV(content);
                        } else {
                            alert('فرمت فایل غیرمعتبر است');
                            return;
                        }
                        
                        validateProducts(products);
                    } catch (err) {
                        showValidationError('خطا در پارسینگ فایل: ' + err.message);
                    }
                };
                reader.readAsText(file);
            });
            
            function parseCSV(content) {
                const lines = content.trim().split('\n');
                if (lines.length < 2) return [];
                
                const headers = lines[0].split(',').map(h => h.trim());
                const products = [];
                
                for (let i = 1; i < lines.length; i++) {
                    const values = lines[i].split(',').map(v => v.trim());
                    if (values.length !== headers.length) continue;
                    
                    const product = {};
                    headers.forEach((h, idx) => {
                        product[h] = values[idx];
                    });
                    products.push(product);
                }
                
                return products;
            }
            
            function validateProducts(products) {
                if (!Array.isArray(products) || products.length === 0) {
                    showValidationError('فایل محصول ندارد');
                    return;
                }
                
                let errors = [];
                let warnings = [];
                let validCount = 0;
                
                products.forEach((product, idx) => {
                    const productNum = idx + 1;
                    
                    // بررسی نام
                    if (!product.name) {
                        errors.push(`محصول ${productNum}: نام موجود نیست`);
                        return;
                    }
                    
                    // بررسی قیمت برای محصولات ساده
                    if (product.type === 'simple' && !product.price) {
                        errors.push(`محصول "${product.name}": قیمت موجود نیست`);
                        return;
                    }
                    
                    // بررسی محصولات متغیر
                    if (product.type === 'variable') {
                        if (!product.attributes || Object.keys(product.attributes).length === 0) {
                            errors.push(`محصول "${product.name}": ویژگی‌ها موجود نیست`);
                            return;
                        }
                        
                        if (!product.variations || product.variations.length === 0) {
                            errors.push(`محصول "${product.name}": متغیرات موجود نیست`);
                            return;
                        }
                        
                        // بررسی هر متغیر
                        product.variations.forEach((v, vIdx) => {
                            if (!v.price) {
                                warnings.push(`محصول "${product.name}" - متغیر ${vIdx + 1}: قیمت موجود نیست`);
                            }
                            if (!v.attributes || Object.keys(v.attributes).length === 0) {
                                errors.push(`محصول "${product.name}" - متغیر ${vIdx + 1}: ویژگی‌های متغیر خالی است`);
                                return;
                            }
                        });
                    }
                    
                    validCount++;
                });
                
                showValidationResults(validCount, errors, warnings, products.length);
            }
            
            function showValidationResults(valid, errors, warnings, total) {
                let html = '<div style="padding: 0;">';
                
                // خلاصه
                html += `<div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                    <strong>خلاصه:</strong><br>
                    ✓ ${valid}/${total} محصول معتبر
                </div>`;
                
                // خطاها
                if (errors.length > 0) {
                    html += '<div style="margin-bottom: 10px;">';
                    html += '<strong style="color: red;">❌ خطاها (' + errors.length + '):</strong><br>';
                    errors.slice(0, 8).forEach(err => {
                        html += '• ' + err + '<br>';
                    });
                    if (errors.length > 8) {
                        html += '• ... و ' + (errors.length - 8) + ' خطای دیگر<br>';
                    }
                    html += '</div>';
                }
                
                // هشدارها
                if (warnings.length > 0) {
                    html += '<div style="margin-bottom: 10px;">';
                    html += '<strong style="color: orange;">⚠️ هشدارها (' + warnings.length + '):</strong><br>';
                    warnings.slice(0, 5).forEach(warn => {
                        html += '• ' + warn + '<br>';
                    });
                    if (warnings.length > 5) {
                        html += '• ... و ' + (warnings.length - 5) + ' هشدار دیگر<br>';
                    }
                    html += '</div>';
                }
                
                // پیام نتیجه
                if (errors.length === 0) {
                    html += '<div style="padding: 10px; background: #e8f5e9; border-radius: 3px; color: green;">';
                    html += '<strong>✓ فایل معتبر است! می‌توانید اپلود کنید</strong>';
                    html += '</div>';
                    $('#upload-btn').show();
                } else {
                    html += '<div style="padding: 10px; background: #ffebee; border-radius: 3px; color: red;">';
                    html += '<strong>✗ فایل دارای خطا است. لطفا اصلاح کنید</strong>';
                    html += '</div>';
                    $('#upload-btn').hide();
                }
                
                html += '</div>';
                
                $('#validation-content').html(html);
                $('#validation-results').show();
            }
            
            function showValidationError(message) {
                $('#validation-content').html(`<div style="padding: 10px; background: #ffebee; border-radius: 3px; color: red;">✗ ${message}</div>`);
                $('#validation-results').show();
                $('#upload-btn').hide();
            }
            
            // آپلود پس از تأیید
            $('#upload-btn').on('click', function() {
                const file = $('#upload-file')[0].files[0];
                if (!file) {
                    alert('لطفا فایل را انتخاب کنید');
                    return;
                }
                
                const formData = new FormData();
                formData.append('file', file);
                formData.append('action', 'pie_upload_products');
                formData.append('nonce', '<?php echo wp_create_nonce('pie_nonce'); ?>');
                
                $('#upload-progress').show();
                $('#upload-messages').hide().html('');
                $('#upload-status').text('در حال آپلود...');
                
                $.ajax({
                    type: 'POST',
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        $('#upload-progress').hide();
                        if (response.success) {
                            $('#upload-messages').show().html(response.data);
                            $('#upload-status').html('<span style="color: green;">✓ تکمیل شد</span>');
                            $('#validate-btn').text('✓ بررسی دوباره');
                            setTimeout(() => loadProductsList(), 1000);
                        } else {
                            $('#upload-messages').show().html('<span style="color: red;">خطا: ' + response.data + '</span>');
                            $('#upload-status').html('<span style="color: red;">✗ ناموفق</span>');
                        }
                    },
                    error: function() {
                        $('#upload-progress').hide();
                        $('#upload-messages').show().html('<span style="color: red;">خطای ارتباطی</span>');
                        $('#upload-status').html('<span style="color: red;">✗ خطا</span>');
                    }
                });
            });
            
            loadProductsList();
        });
        </script>
        <?php
    }
    
    public function handle_get_products_list() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی رد شد');
        }
        
        $products = [];
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ];
        
        $query = new WP_Query($args);
        
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            
            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => wc_price($product->get_price()),
                'stock_quantity' => $product->get_stock_quantity(),
                'type' => $product->get_type(),
            ];
        }
        
        wp_reset_postdata();
        wp_send_json_success($products);
    }
    
    public function handle_download() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی رد شد');
        }
        
        $format = sanitize_text_field($_POST['format'] ?? 'json');
        $product_ids = sanitize_text_field($_POST['product_ids'] ?? '');
        
        if (empty($product_ids)) {
            wp_send_json_error('محصولی انتخاب نشده');
        }
        
        $ids_array = array_map('intval', explode(',', $product_ids));
        $products_data = $this->get_products_for_export($ids_array);
        
        if (empty($products_data)) {
            wp_send_json_error('محصولی یافت نشد');
        }
        
        if ($format === 'json') {
            $content = json_encode($products_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $content = $this->convert_to_csv($products_data);
        }
        
        wp_send_json_success($content);
    }
    
    public function get_products_for_export_public($product_ids) {
        return $this->get_products_for_export($product_ids);
    }
    
    private function get_products_for_export($product_ids) {
        $products = [];
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            $product_data = [
                '_s1_id'          => $product->get_id(), // ID محصول در سایت ۱ — برای auto-mapping دقیق هنگام آپلود دستی
                'name'            => $product->get_name(),
                'sku'             => $product->get_sku(),
                'description'     => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'price'           => $product->get_price(),
                'regular_price'   => $product->get_regular_price(),
                'sale_price'      => $product->get_sale_price(),
                'stock_quantity'  => $product->get_stock_quantity(),
                'manage_stock'    => $product->get_manage_stock(),
                'type'            => $product->get_type(),
                'categories'      => [],
                'tags'            => [],
                'images'          => [],
                'attributes'      => [],
                'variations'      => []
            ];
            
            // دسته‌بندی‌ها - فقط نام‌ها (ساختار API)
            $category_terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
            if (!is_wp_error($category_terms)) {
                $product_data['categories'] = $category_terms;
            }
            
            // برچسب‌ها - فقط نام‌ها
            $tag_terms = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
            if (!is_wp_error($tag_terms)) {
                $product_data['tags'] = $tag_terms;
            }
            
            // عکس‌ها - ساختار دقیق API
            if ($product->get_image_id()) {
                $url = wp_get_attachment_image_url($product->get_image_id(), 'full');
                $alt = get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true);
                if ($url) {
                    $product_data['images'][] = [
                        'url' => $url,
                        'alt' => $alt ?: ''
                    ];
                }
            }
            
            if ($product->get_gallery_image_ids()) {
                foreach ($product->get_gallery_image_ids() as $id) {
                    $url = wp_get_attachment_image_url($id, 'full');
                    $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                    if ($url) {
                        $product_data['images'][] = [
                            'url' => $url,
                            'alt' => $alt ?: ''
                        ];
                    }
                }
            }
            
            // برای محصولات متغیر
            if ($product->is_type('variable')) {
                $attributes = $product->get_attributes();
                foreach ($attributes as $attr_key => $attr) {
                    if (!$attr->is_taxonomy()) continue;
                    
                    // استخراج نام ویژگی (بدون پیشوند pa_)
                    $attr_name = str_replace('pa_', '', $attr_key);
                    $terms = $attr->get_options();
                    $values = [];
                    
                    foreach ($terms as $term_id) {
                        $term = get_term($term_id);
                        if ($term && !is_wp_error($term)) {
                            $values[] = [
                                'name' => $term->name,
                                'slug' => $term->slug,
                            ];
                        }
                    }
                    
                    $product_data['attributes'][$attr_name] = [
                        'values' => $values,
                        'visible' => $attr->get_visible(),
                    ];
                }
                
                // متغیرات
                $variations = $product->get_available_variations('objects');
                
                if (empty($variations)) {
                    // محصولات قدیمی: query direct
                    $variations = wc_get_products([
                        'type' => 'variation',
                        'parent' => $product->get_id(),
                        'limit' => -1,
                        'return' => 'objects'
                    ]);
                }
                
                foreach ($variations as $variation) {
                    if (!is_object($variation)) {
                        $variation = new WC_Product_Variation($variation);
                    }
                    
                    $var_data = [
                        '_s1_id'         => $variation->get_id(), // ID سایت ۱ — برای auto-mapping دقیق
                        'sku'            => $variation->get_sku() ?: '',
                        'price'          => $variation->get_price() ? (string)$variation->get_price() : '0',
                        'regular_price'  => $variation->get_regular_price() ? (string)$variation->get_regular_price() : '0',
                        'stock_quantity' => (int)($variation->get_stock_quantity() ?: 0),
                        'attributes'     => [],
                    ];
                    
                    foreach ($variation->get_attributes() as $attr_key => $attr_value) {
                        // استخراج نام ویژگی (بدون pa_ و attribute_)
                        $attr_name = str_replace(['attribute_', 'pa_'], '', $attr_key);
                        $var_data['attributes'][$attr_name] = $attr_value;
                    }
                    
                    $product_data['variations'][] = $var_data;
                }
            }
            
            $products[] = $product_data;
        }
        
        return $products;
    }
    
    public function handle_validate_file() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی رد ش��');
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error('فایلی انتخاب نشده');
        }
        
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if (!in_array($ext, ['json', 'csv'])) {
            wp_send_json_error('فقط JSON و CSV پذیرفته می‌شود');
        }
        
        $content = file_get_contents($file['tmp_name']);
        
        if ($ext === 'json') {
            $products = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('فایل JSON معتبر نیست: ' . json_last_error_msg());
            }
        } else {
            $products = $this->parse_csv($content);
        }
        
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('محصول یافت نشد');
        }
        
        // ب��رسی محصولات
        $validation = $this->validate_products($products);
        wp_send_json_success($validation);
    }
    
    private function validate_products($products) {
        $errors = [];
        $warnings = [];
        $valid_count = 0;
        
        foreach ($products as $idx => $product) {
            $product_num = $idx + 1;
            
            // بررسی نام
            if (empty($product['name'])) {
                $errors[] = "محصول {$product_num}: نام موجود نیست";
                continue;
            }
            
            $product_name = $product['name'];
            
            // بررسی قیمت برای محصولات ساده
            $type = $product['type'] ?? 'simple';
            if ($type === 'simple' && empty($product['price'])) {
                $errors[] = "محصول \"{$product_name}\": قیمت موجود نیست";
                continue;
            }
            
            // بررسی محصولات متغیر
            if ($type === 'variable') {
                if (empty($product['attributes'])) {
                    $errors[] = "محصول \"{$product_name}\": ویژگی‌ها موجود نیست";
                    continue;
                }
                
                if (empty($product['variations'])) {
                    $errors[] = "محصول \"{$product_name}\": متغیرات موجود نیست";
                    continue;
                }
                
                // بررسی هر متغیر - اما فقط warning نه error
                // محصولات قدیمی ممکن است متغیرات حذف شده داشته باشند
                foreach ($product['variations'] as $v_idx => $variation) {
                    if (empty($variation['price'])) {
                        $warnings[] = "محصول \"{$product_name}\" - م��غیر " . ($v_idx + 1) . ": قیمت موجود نیست (قیمت محصول استفاده می‌شود)";
                    }
                    // فقط warning برای attributes خالی (برای محصولات قدیمی)
                    if (empty($variation['attributes'])) {
                        $warnings[] = "محصول \"{$product_name}\" - متغیر " . ($v_idx + 1) . ": ویژگی‌های متغیر خالی است (فقط ایجاد می‌شود)";
                    }
                }
            }
            
            $valid_count++;
        }
        
        return [
            'valid' => $valid_count,
            'total' => count($products),
            'errors' => $errors,
            'warnings' => $warnings,
            'can_upload' => empty($errors)
        ];
    }
    
    public function handle_upload() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی رد شد');
        }
        
        if (empty($_FILES['file'])) {
            wp_send_json_error('فایلی انتخاب نشده');
        }
        
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if (!in_array($ext, ['json', 'csv'])) {
            wp_send_json_error('فقط JSON و CSV پذیرفته می‌شود');
        }
        
        $content = file_get_contents($file['tmp_name']);
        
        if ($ext === 'json') {
            $products = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('فایل JSON معتبر نیست');
            }
        } else {
            $products = $this->parse_csv($content);
        }
        
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('محصول یافت نشد');
        }
        
        $result = $this->import_products($products);

        // جفت‌سازی خودکار موجودی: دقیقاً مثل ارسال مستقیم
        // هر محصول import‌شده را با s1_product_id موجود در JSON جفت می‌کنیم
        $this->auto_pair_imported_products($products, $result);

        wp_send_json_success($result['message']);
    }

    /**
     * جفت‌سازی خودکار موجودی پس از آپلود دستی JSON
     *
     * رویکرد:
     *  - از $import_result['variation_ids'] که import_products پر کرده استفاده می‌کند.
     *    این آرایه دقیقاً نگاشت [ 'id_<s1_var_id>' => <s2_var_id> ] است.
     *  - برای s1_product_id: ابتدا از فیلد _s1_id سطح محصول در JSON می‌خواند.
     *    اگر نبود (فایل قدیمی) از endpoint سایت ۱ می‌پرسد.
     *    اگر remote در دسترس نبود، از اولین s1_var_id به عنوان fallback استفاده می‌کند.
     *  - نیازی به جستجوی مجدد با SKU یا attribute نیست — همه اطلاعات در $import_result موجود است.
     */
    private function auto_pair_imported_products($products_json, $import_result) {
        $settings = PIE_Settings::get_instance();
        $config   = $settings->get_config();

        error_log("[PIE auto_pair] ===== شروع جفت‌سازی خودکار پس از آپلود دستی =====");
        error_log("[PIE auto_pair] site_role=" . ($config['site_role'] ?? 'N/A'));
        error_log("[PIE auto_pair] تعداد محصولات JSON=" . count($products_json));
        error_log("[PIE auto_pair] import_result product_id=" . ($import_result['product_id'] ?? 'null'));
        error_log("[PIE auto_pair] import_result variation_ids=" . wp_json_encode($import_result['variation_ids'] ?? []));
        error_log("[PIE auto_pair] remote_site_url=" . ($config['remote_site_url'] ?? 'خالی'));
        error_log("[PIE auto_pair] remote_api_key=" . (empty($config['remote_api_key']) ? 'خالی' : 'موجود'));

        if ($config['site_role'] !== 'site2') {
            error_log("[PIE auto_pair] این سایت site2 نیست — جفت‌سازی انجام نمی‌شود (role={$config['site_role']})");
            return;
        }

        // variation_ids که import_products برگردانده: [ 'id_<s1_var_id>' => <s2_var_id>, ... ]
        // این مستقیم‌ترین و دقیق‌ترین منبع اطلاعات است — نیازی به جستجوی مجدد نیست
        $variation_map = $import_result['variation_ids'] ?? [];
        // s2_product_id که در این import ایجاد شده
        $s2_product_id_from_result = intval($import_result['product_id'] ?? 0);

        error_log("[PIE auto_pair] variation_map دارای " . count($variation_map) . " ردیف | s2_product_id از result=" . $s2_product_id_from_result);

        if (empty($variation_map) && $s2_product_id_from_result === 0) {
            error_log("[PIE auto_pair] import_result خالی است — جفت‌سازی امکان‌پذیر نیست");
            return;
        }

        $stock_sync = PIE_StockSync::get_instance();

        foreach ($products_json as $prod_idx => $product_json) {
            $product_type = $product_json['type'] ?? 'simple';
            $product_name = $product_json['name'] ?? '';
            $product_sku  = $product_json['sku'] ?? '';

            error_log("[PIE auto_pair] --- محصول #{$prod_idx}: '{$product_name}' | type={$product_type} | sku='{$product_sku}'");

            // ---- s2_product_id: مستقیم از import_result ----
            // چون فعلاً import_products فقط یک product_id ذخیره می‌کند (اولین محصول)،
            // برای چند محصول باید product_id را از variation پیدا کنیم
            $s2_product_id = $s2_product_id_from_result;

            // اگر چند محصول import شده، باید s2_product_id هر محصول را از variation‌هایش پیدا کنیم
            if ($product_type === 'variable' && !empty($product_json['variations'])) {
                foreach ($product_json['variations'] as $v) {
                    $s1_var_id_check = intval($v['_s1_id'] ?? 0);
                    if ($s1_var_id_check && isset($variation_map['id_' . $s1_var_id_check])) {
                        $s2_var_check = intval($variation_map['id_' . $s1_var_id_check]);
                        $s2_var_obj   = wc_get_product($s2_var_check);
                        if ($s2_var_obj && $s2_var_obj->get_parent_id()) {
                            $s2_product_id = $s2_var_obj->get_parent_id();
                            error_log("[PIE auto_pair] s2_product_id={$s2_product_id} از parent variation {$s2_var_check}");
                            break;
                        }
                    }
                }
            }

            if (!$s2_product_id) {
                error_log("[PIE auto_pair] s2_product_id پیدا نشد برای '{$product_name}' — رد شد");
                continue;
            }

            // ---- s1_product_id ----
            // روش ۱: فیلد _s1_id در سطح محصول (فایل‌های صادرشده با نسخه جدید)
            $s1_product_id = intval($product_json['_s1_id'] ?? 0);
            error_log("[PIE auto_pair] _s1_id در سطح محصول: " . ($s1_product_id ?: 'ندارد'));

            // روش ۲: اگر فایل قدیمی بود، از API سایت ۱ parent variation را بپرس
            if (!$s1_product_id && !empty($config['remote_site_url']) && !empty($config['remote_api_key'])) {
                // اولین variation که _s1_id دارد را پیدا کن
                $first_s1_var_id = 0;
                foreach ($product_json['variations'] as $v) {
                    $first_s1_var_id = intval($v['_s1_id'] ?? 0);
                    if ($first_s1_var_id) break;
                }

                if ($first_s1_var_id) {
                    $remote_url = rtrim($config['remote_site_url'], '/');
                    $resp = wp_remote_get($remote_url . '/wp-json/pie/v1/product-parent/' . $first_s1_var_id, [
                        'timeout' => 10,
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode($config['remote_api_key'] . ':' . ($config['remote_api_secret'] ?? '')),
                        ],
                    ]);
                    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                        $body = json_decode(wp_remote_retrieve_body($resp), true);
                        if (!empty($body['parent_id'])) {
                            $s1_product_id = intval($body['parent_id']);
                            error_log("[PIE auto_pair] s1_product_id={$s1_product_id} از API سایت ۱ (parent of var {$first_s1_var_id})");
                        }
                    } else {
                        $err = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
                        error_log("[PIE auto_pair] خطا در product-parent API: {$err}");
                    }
                }
            }

            // روش ۳: fallback — اگر remote در دسترس نبود، از اولین _s1_id variation
            // این کمتر دقیق است اما جفت‌سازی را ممکن می‌سازد
            if (!$s1_product_id && !empty($product_json['variations'])) {
                foreach ($product_json['variations'] as $v) {
                    $fb_id = intval($v['_s1_id'] ?? 0);
                    if ($fb_id) {
                        $s1_product_id = $fb_id;
                        error_log("[PIE auto_pair] fallback s1_product_id={$s1_product_id} (از اولین variation _s1_id — کمتر دقیق)");
                        break;
                    }
                }
            }

            if (!$s1_product_id) {
                error_log("[PIE auto_pair] s1_product_id پیدا نشد برای '{$product_name}' — رد شد");
                continue;
            }

            error_log("[PIE auto_pair] s1_product_id={$s1_product_id} | s2_product_id={$s2_product_id}");

            $mappings_to_push = [];

            // ========= محصول متغیر =========
            if ($product_type === 'variable' && !empty($product_json['variations'])) {

                foreach ($product_json['variations'] as $var_idx => $var_json) {
                    $s1_var_id = intval($var_json['_s1_id'] ?? 0);
                    $var_attrs = $var_json['attributes'] ?? [];

                    error_log("[PIE auto_pair] variation #{$var_idx}: s1_var_id={$s1_var_id} | attrs=" . wp_json_encode($var_attrs));

                    if (!$s1_var_id) {
                        error_log("[PIE auto_pair] variation #{$var_idx} فاقد _s1_id — رد شد");
                        continue;
                    }

                    // s2_var_id مستقیم از variation_map که import_products ساخته
                    $map_key   = 'id_' . $s1_var_id;
                    $s2_var_id = isset($variation_map[$map_key]) ? intval($variation_map[$map_key]) : null;

                    if (!$s2_var_id) {
                        error_log("[PIE auto_pair] FAIL: کلید '{$map_key}' در variation_map نیست | variation_map keys: " . implode(', ', array_keys($variation_map)));
                        continue;
                    }

                    error_log("[PIE auto_pair] OK: s1_var={$s1_var_id} <-> s2_var={$s2_var_id} (از variation_map مستقیم)");

                    $attr_parts = [];
                    foreach ($var_attrs as $ak => $av) {
                        $attr_parts[] = urldecode((string)$ak) . ':' . urldecode((string)$av);
                    }
                    $attr_str = implode('|', $attr_parts);

                    $stock_sync->register_mapping(
                        $s1_product_id,
                        $s2_product_id,
                        $s1_var_id,
                        $s2_var_id,
                        $product_name,
                        $attr_str
                    );
                    error_log("[PIE auto_pair] register_mapping ثبت شد: s1_prod={$s1_product_id} s1_var={$s1_var_id} s2_prod={$s2_product_id} s2_var={$s2_var_id}");

                    // ✅ اضافه: ایجاد stock_map برای هماهنگ‌سازی دوطرفه
                    PIE_StockSync::create_stock_map(
                        $s1_product_id,
                        $s2_product_id,
                        $s1_var_id,
                        $s2_var_id,
                        $product_name,
                        's2_to_s1'  // جهت صحیح: آپلود دستی از سایت ۲ شروع می‌شود
                    );
                    error_log("[PIE auto_pair] create_stock_map فراخوانی شد برای variation: s1_prod={$s1_product_id} s1_var={$s1_var_id} s2_prod={$s2_product_id} s2_var={$s2_var_id}");

                    $mappings_to_push[] = [
                        's1_product_id'   => $s1_product_id,
                        's2_product_id'   => $s2_product_id,
                        's1_variation_id' => $s1_var_id,
                        's2_variation_id' => $s2_var_id,
                        'product_name'    => $product_name,
                        'variation_attrs' => $attr_str,
                    ];
                }

            // ========= محصول ساده =========
            } else {
                $stock_sync->register_mapping(
                    $s1_product_id,
                    $s2_product_id,
                    null,
                    null,
                    $product_name
                );
                error_log("[PIE auto_pair] محصول ساده register_mapping: s1={$s1_product_id} s2={$s2_product_id}");

                // ✅ اضافه: ایجاد stock_map برای هماهنگ‌سازی دوطرفه
                PIE_StockSync::create_stock_map(
                    $s1_product_id,
                    $s2_product_id,
                    null,
                    null,
                    $product_name,
                    's2_to_s1'  // جهت صحیح: آپلود دستی از سایت ۲ شروع می‌شود
                );
                error_log("[PIE auto_pair] create_stock_map فراخوانی شد برای محصول ساده: s1={$s1_product_id} s2={$s2_product_id}");

                $mappings_to_push[] = [
                    's1_product_id'   => $s1_product_id,
                    's2_product_id'   => $s2_product_id,
                    's1_variation_id' => null,
                    's2_variation_id' => null,
                    'product_name'    => $product_name,
                    'variation_attrs' => '',
                ];
            }

            // ---- ارسال mapping معکوس به سایت ۱ برای sync دوطرفه ----
            if (empty($mappings_to_push)) {
                error_log("[PIE auto_pair] هیچ mapping‌ای برای ارسال به سایت ۱ ساخته نشد");
                continue;
            }

            if (empty($config['remote_site_url']) || empty($config['remote_api_key'])) {
                error_log("[PIE auto_pair] remote_site_url یا remote_api_key خالی — ارسال به سایت ۱ انجام نمی‌شود (جفت‌سازی محلی ثبت شد)");
                continue;
            }

            $remote_url  = rtrim($config['remote_site_url'], '/');
            $api_key     = $config['remote_api_key'];
            $api_secret  = $config['remote_api_secret'] ?? '';
            $s2_site_url = get_site_url();

            error_log("[PIE auto_pair] ارسال " . count($mappings_to_push) . " mapping به سایت ۱: {$remote_url}");

            foreach ($mappings_to_push as $map_data) {
                $map_data['s1_site_url'] = $s2_site_url;
                $push_result = wp_remote_post($remote_url . '/wp-json/pie/v1/register-map', [
                    'timeout' => 15,
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Basic ' . base64_encode("{$api_key}:{$api_secret}"),
                    ],
                    'body' => wp_json_encode($map_data),
                ]);

                if (is_wp_error($push_result)) {
                    error_log("[PIE auto_pair] FAIL ارسال به سایت ۱: " . $push_result->get_error_message());
                } else {
                    $resp_code = wp_remote_retrieve_response_code($push_result);
                    $resp_body = wp_remote_retrieve_body($push_result);
                    error_log("[PIE auto_pair] پاسخ سایت ۱: HTTP {$resp_code} | " . substr($resp_body, 0, 300));
                }
            }
        }

        error_log("[PIE auto_pair] ===== پایان جفت‌سازی خودکار =====");
    }
    
    public function import_products_public($products) {
        return $this->import_products($products);
    }
    
    private function import_products($products) {
        $result = [
            'created'       => 0,
            'categories'    => 0,
            'attributes'    => 0,
            'variations'    => 0,
            'images'        => 0,
            'errors'        => [],
            'message'       => '',
            // برای auto-mapping موجودی: ID‌های ایجادشده در این سایت
            'product_id'    => null,
            'variation_ids' => [], // [ 'id_<s1_var_id>' => s2_var_id, ... ] — همه محصولات flat
            // per-product mapping: [ ['s1_id'=>X, 's2_id'=>Y, 'type'=>'variable', 'variation_ids'=>[...], 'name'=>'...'], ... ]
            'product_map'   => [],
        ];
        
        foreach ($products as $idx => $product_data) {
            try {
                $name = $product_data['name'] ?? '';
                if (!$name) {
                    $result['errors'][] = "محصول شماره " . ($idx + 1) . ": نام ندارد";
                    continue;
                }
                
                // بررسی ویژگی‌های متغیر
                if (($product_data['type'] ?? 'simple') === 'variable') {
                    if (empty($product_data['attributes'])) {
                        $result['errors'][] = "محصول '$name': ویژگی (attributes) ندارد";
                        continue;
                    }
                    if (empty($product_data['variations'])) {
                        $result['errors'][] = "محصول '$name': متغیر (variations) ندارد";
                        continue;
                    }
                    
                    // بررسی متغیرات - قیمت می‌تواند در price یا regular_price باشد
                    foreach ($product_data['variations'] as $v_idx => $variation) {
                        if (empty($variation['attributes'])) {
                            $result['errors'][] = "محصول '$name' - متغیر " . ($v_idx + 1) . ": ویژگی‌های متغیر خالی است";
                            continue;
                        }
                        $var_price = $variation['regular_price'] ?? $variation['price'] ?? '';
                        if (empty($var_price)) {
                            $result['errors'][] = "محصول '$name' - متغیر " . ($v_idx + 1) . ": قیمت ندارد";
                            continue;
                        }
                    }
                }
                
                // دسته‌بندی��ها
                $category_ids = [];
                if (!empty($product_data['categories'])) {
                    foreach ($product_data['categories'] as $cat) {
                        // دسته‌بندی می‌تواند نام ساده یا array ب��شد
                        if (is_string($cat)) {
                            // نام ساده - پیدا یا ایجاد کن
                            $cat_term = get_term_by('name', $cat, 'product_cat');
                            if (!$cat_term) {
                                $cat_term = wp_insert_term($cat, 'product_cat');
                                if (is_wp_error($cat_term)) continue;
                            }
                            $category_ids[] = is_array($cat_term) ? $cat_term['term_id'] : $cat_term->term_id;
                            $result['categories']++;
                        } else {
                            // ساختار کامل
                            $cat_id = $this->sync_category($cat);
                            if ($cat_id) {
                                $category_ids[] = $cat_id;
                                $result['categories']++;
                            }
                        }
                    }
                }
                
                // محصول ایجاد کن
                $post_id = wp_insert_post([
                    'post_title' => $name,
                    'post_content' => $product_data['description'] ?? '',
                    'post_excerpt' => $product_data['short_description'] ?? '',
                    'post_type' => 'product',
                    'post_status' => 'publish',
                ]);
                
                if (!$post_id) continue;
                
                // نوع محصول را مشخص کن
                $product_type = $product_data['type'] ?? 'simple';
                
                // ⭐ set product_type term BEFORE wc_get_product so the right class loads
                // wp_set_object_terms accepts term slug OR term name — 'variable' is the slug
                $set_type_result = wp_set_object_terms($post_id, $product_type, 'product_type');
                if (is_wp_error($set_type_result)) {
                    // fallback: term might need to be looked up
                    $type_term = get_term_by('slug', $product_type, 'product_type');
                    if ($type_term) {
                        wp_set_object_terms($post_id, $type_term->term_id, 'product_type');
                    }
                }
                
                // clear the product cache so wc_get_product reloads with the new type
                clean_post_cache($post_id);
                WC_Cache_Helper::invalidate_cache_group('product_' . $post_id);
                
                // حالا product ب�� نوع صحیح بارگذاری می‌شود
                $product = wc_get_product($post_id);
                if (!$product) continue;
                
                // داده‌های پایه
                $product->set_sku($product_data['sku'] ?? '');
                if ($product_type !== 'variable') {
                    // برای محصول ساده قیمت مستقیم ست می‌شود
                    $price = $product_data['regular_price'] ?? $product_data['price'] ?? 0;
                    $product->set_regular_price($price);
                    $product->set_price($price);
                    if (!empty($product_data['sale_price'])) {
                        $product->set_sale_price($product_data['sale_price']);
                    }
                    $product->set_stock_quantity($product_data['stock_quantity'] ?? 0);
                    $product->set_manage_stock(true);
                }
                
                if (!empty($category_ids)) {
                    wp_set_post_terms($post_id, $category_ids, 'product_cat');
                }
                
                // برچسب‌ها
                if (!empty($product_data['tags'])) {
                    $tag_ids = [];
                    foreach ($product_data['tags'] as $tag) {
                        $tag_term = get_term_by('name', $tag, 'product_tag');
                        if (!$tag_term) {
                            $tag_term = wp_insert_term($tag, 'product_tag');
                            if (is_wp_error($tag_term)) continue;
                        }
                        $tag_ids[] = is_array($tag_term) ? $tag_term['term_id'] : $tag_term->term_id;
                    }
                    if (!empty($tag_ids)) {
                        wp_set_post_terms($post_id, $tag_ids, 'product_tag');
                    }
                }
                
                // عکس‌ها - جدید: ساختار [{'url': '...', 'alt': '...'}] یا قدیمی: ['url1', 'url2']
                if (!empty($product_data['images'])) {
                    $gallery = [];
                    foreach ($product_data['images'] as $idx => $image_item) {
                        $image_url = is_array($image_item) ? ($image_item['url'] ?? $image_item) : $image_item;
                        $image_alt = is_array($image_item) ? ($image_item['alt'] ?? '') : '';
                        
                        if (empty($image_url)) continue;
                        
                        $img_id = $this->download_image($image_url, $post_id);
                        if ($img_id) {
                            if ($idx === 0) {
                                $product->set_image_id($img_id);
                            } else {
                                $gallery[] = $img_id;
                            }
                            if ($image_alt) {
                                update_post_meta($img_id, '_wp_attachment_image_alt', $image_alt);
                            }
                            $result['images']++;
                        }
                    }
                    if (!empty($gallery)) {
                        $product->set_gallery_image_ids($gallery);
                    }
                }
                
                // برای محصولات متغیر
                if ($product_type === 'variable' && !empty($product_data['attributes'])) {
                    $attr_ids      = [];
                    $wc_attributes = [];
                    $position      = 0;
                    
                    foreach ($product_data['attributes'] as $attr_key => $attr_data) {
                        $attr_name = $attr_key;
                        if (strpos($attr_name, '%') !== false) {
                            $attr_name = urldecode($attr_name);
                        }
                        
                        $values_list = $attr_data['values'] ?? [];
                        
                        // ⭐ sync_attribute حالا taxonomy را در همین request نیز register می‌کند
                        $attr_id = $this->sync_attribute($attr_name, $values_list);
                        if ($attr_id) {
                            $attr_ids[$attr_key] = $attr_id;
                            $result['attributes']++;
                        }
                        
                        $clean_attr_slug = sanitize_title($attr_name);
                        $taxonomy        = 'pa_' . $clean_attr_slug;
                        
                        // جمع‌آوری slug های term های موجود
                        $term_slugs = [];
                        foreach ($values_list as $v) {
                            $v_name = $v['name'] ?? '';
                            $v_slug = $v['slug'] ?? '';
                            if (strpos($v_name, '%') !== false) $v_name = urldecode($v_name);
                            if (strpos($v_slug, '%') !== false) $v_slug = urldecode($v_slug);
                            $v_slug = $v_slug ?: sanitize_title($v_name);
                            
                            // اگر term هنوز وجود نداشت (بعد از sync_attribute) بساز
                            if (!term_exists($v_slug, $taxonomy)) {
                                wp_insert_term($v_name, $taxonomy, ['slug' => $v_slug]);
                            }
                            $term_slugs[] = $v_slug;
                        }
                        
                        // ست کردن post terms روی محصول برای این taxonomy
                        if (!empty($term_slugs)) {
                            wp_set_post_terms($post_id, $term_slugs, $taxonomy);
                        }
                        
                        // ⭐ ساخت WC_Product_Attribute با ID صحیح
                        // اگر attr_id=null بود (��لاک شد)، از wc_attribute_taxonomy_id_by_name بگیر
                        $resolved_id = $attr_id ?: wc_attribute_taxonomy_id_by_name($clean_attr_slug);
                        
                        $wc_attr = new WC_Product_Attribute();
                        $wc_attr->set_id((int) $resolved_id);
                        $wc_attr->set_name($taxonomy);
                        $wc_attr->set_options($term_slugs);
                        $wc_attr->set_position($position++);
                        $wc_attr->set_visible(true);
                        $wc_attr->set_variation(true);
                        $wc_attributes[$taxonomy] = $wc_attr;
                    }
                    
                    // ست کردن attributes و save محصول
                    $product->set_attributes($wc_attributes);
                    $product->save();
                    
                    // ⭐ ایجاد متغیرات بعد از save محصول
                    if (!empty($product_data['variations'])) {
                        foreach ($product_data['variations'] as $variation_data) {
                            $var_created = $this->create_variation($post_id, $variation_data, $attr_ids);
                            if ($var_created) {
                                $result['variations']++;
                                $v_id    = $var_created['id']   ?? 0;
                                $v_s1_id = $var_created['s1_id'] ?? 0; // ID متغیر در سایت ۱
                                $v_sku   = $var_created['sku']  ?? '';
                                if ($v_id) {
                                    if ($v_s1_id) {
                                        // کلید اصلی: s1_variation_id — دقیق و بدون ��شتباه
                                        $result['variation_ids']['id_' . $v_s1_id] = $v_id;
                                    } elseif ($v_sku) {
                                        // fallback: SKU (برای محصولاتی که بدون _s1_id ارسال شده‌اند)
                                        $result['variation_ids'][$v_sku] = $v_id;
                                    }
                                }
                            }
                        }
                    }

                    // sync قیمت محصول متغیر با کمترین قیمت متغیرها
                    WC_Product_Variable::sync($post_id);

                } else {
                    // محصول ساده را save کن
                    $product->save();
                }

                $result['created']++;
                // ذخیره product_id برای auto-mapping (اولین محصول import شده)
                if ($result['product_id'] === null) {
                    $result['product_id'] = $post_id;
                }
                
            } catch (Exception $e) {
                error_log('PIE Import Error: ' . $e->getMessage());
            }
        }
        
        $result['message'] = sprintf(
            '✓ محصولات: %d | دسته‌بندی‌ها: %d | ویژگی‌ها: %d | متغیرات: %d | عکس‌ها: %d',
            $result['created'],
            $result['categories'],
            $result['attributes'],
            $result['variations'],
            $result['images']
        );
        
        if (!empty($result['errors'])) {
            $result['message'] .= '<br><br><strong>خطاها:</strong><br>';
            foreach (array_slice($result['errors'], 0, 10) as $error) {
                $result['message'] .= '• ' . $error . '<br>';
            }
            if (count($result['errors']) > 10) {
                $result['message'] .= '• ... و ' . (count($result['errors']) - 10) . ' خطای دیگر';
            }
        }
        
        return $result;
    }
    
    private function sync_category($cat_data) {
        try {
            $parent_id = 0;
            
            // پدر دسته‌بندی را پردازش کنید اگر موجود باشد
            if (!empty($cat_data['parent_id']) && $cat_data['parent_id'] > 0) {
                // اول پدر را بررسی کنید
                $parent_term = get_term($cat_data['parent_id'], 'product_cat');
                if ($parent_term && !is_wp_error($parent_term)) {
                    $parent_id = $parent_term->term_id;
                }
            }
            
            // URL-decode slug و نام اگر لازم باشد
            $slug = $cat_data['slug'] ?? '';
            if (strpos($slug, '%') !== false) {
                $slug = urldecode($slug);
                $slug = sanitize_title($slug);
            }
            
            $name = $cat_data['name'] ?? '';
            if (strpos($name, '%') !== false) {
                $name = urldecode($name);
            }
            
            // دسته‌بندی را ایجاد یا دریافت کن
            $term = term_exists($slug, 'product_cat');
            
            if ($term) {
                return is_array($term) ? $term['term_id'] : $term;
            }
            
            $term = wp_insert_term(
                $name,
                'product_cat',
                [
                    'slug' => $slug ?: sanitize_title($name),
                    'parent' => $parent_id,
                ]
            );
            
            return is_array($term) ? $term['term_id'] : $term;
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function sync_attribute($attr_name, $values) {
        try {
            // URL-decode نام ویژگی
            if (strpos($attr_name, '%') !== false) {
                $attr_name = urldecode($attr_name);
            }
            
            $attr_slug = sanitize_title($attr_name);
            $taxonomy   = wc_attribute_taxonomy_name($attr_slug); // pa_color, pa_size, ...
            
            $attr_id = wc_attribute_taxonomy_id_by_name($attr_slug);
            
            if (!$attr_id) {
                // ویژگی جدید بساز
                $attr_id = wc_create_attribute([
                    'name'        => $attr_name,
                    'slug'        => $attr_slug,
                    'type'        => 'select',
                    'order_by'    => 'menu_order',
                    'has_archives'=> false,
                ]);
                
                if (is_wp_error($attr_id)) {
                    error_log('PIE wc_create_attribute error: ' . $attr_id->get_error_message());
                    return null;
                }
            }
            
            // ⭐ همیشه taxonomy را register کن — چه attribute جدید باشد چه از قبل موجود
            // wc_create_attribute و WC init در همان request taxonomy را اضافه نمی‌کنند
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, ['product', 'product_variation'], [
                    'hierarchical'      => false,
                    'show_ui'           => false,
                    'query_var'         => true,
                    'rewrite'           => false,
                    'public'            => false,
                    'show_in_nav_menus' => false,
                ]);
            }
            
            // مقادیر را اضافه/اطمینان کن
            if (!empty($values)) {
                foreach ($values as $value) {
                    $value_name = $value['name'] ?? '';
                    $value_slug = $value['slug'] ?? '';
                    
                    if (strpos($value_name, '%') !== false) $value_name = urldecode($value_name);
                    if (strpos($value_slug, '%') !== false) $value_slug = urldecode($value_slug);
                    
                    $term_slug = $value_slug ?: sanitize_title($value_name);
                    
                    if (!term_exists($term_slug, $taxonomy)) {
                        wp_insert_term($value_name, $taxonomy, ['slug' => $term_slug]);
                    }
                }
            }
            
            return $attr_id;
        } catch (Exception $e) {
            error_log('PIE Attribute sync error: ' . $e->getMessage());
            return null;
        }
    }
    
    private function create_variation($product_id, $variation_data, $attr_ids) {
        try {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            
            // ویژگی‌های متغیر — ساختار JSON: "attributes": {"color": "%d8...", "size": "40"}
            $attributes = [];
            if (!empty($variation_data['attributes'])) {
                foreach ($variation_data['attributes'] as $attr_key => $attr_value) {
                    // URL-decode کلید و مقدار
                    if (strpos($attr_key, '%') !== false) {
                        $attr_key = urldecode($attr_key);
                    }
                    if (strpos($attr_value, '%') !== false) {
                        $attr_value = urldecode($attr_value);
                    }
                    
                    $clean_attr_slug = sanitize_title($attr_key);
                    $taxonomy        = 'pa_' . $clean_attr_slug;
                    
                    // ⭐ اطمینان از register بودن taxonomy در این request
                    if (!taxonomy_exists($taxonomy)) {
                        register_taxonomy($taxonomy, 'product', [
                            'hierarchical' => false,
                            'show_ui'      => false,
                            'query_var'    => true,
                            'rewrite'      => false,
                        ]);
                    }
                    
                    // ⭐ جستجو با slug اصلی (فارسی decode شده → sanitize_title)
                    $value_slug = sanitize_title($attr_value);
                    $term       = get_term_by('slug', $value_slug, $taxonomy);
                    
                    // اگر پیدا نشد با نام جستجو کن
                    if (!$term) {
                        $term = get_term_by('name', $attr_value, $taxonomy);
                    }
                    
                    // اگر هنوز پیدا نشد بساز
                    if (!$term) {
                        $inserted = wp_insert_term($attr_value, $taxonomy, ['slug' => $value_slug]);
                        if (!is_wp_error($inserted)) {
                            $term = get_term($inserted['term_id'], $taxonomy);
                        }
                    }
                    
                    if ($term && !is_wp_error($term)) {
                        $attributes[$taxonomy] = $term->slug;
                    }
                }
            }
            
            if (empty($attributes)) {
                error_log('PIE create_variation: no attributes resolved for product_id=' . $product_id);
                return false;
            }
            
            $variation->set_attributes($attributes);
            
            // قیمت — پشتیبانی از هر دو فیلد price و regular_price
            $price = $variation_data['regular_price'] ?? $variation_data['price'] ?? '';
            if ($price !== '' && $price !== null) {
                $variation->set_regular_price((string) $price);
                $variation->set_price((string) $price);
            }
            if (!empty($variation_data['sale_price'])) {
                $variation->set_sale_price((string) $variation_data['sale_price']);
            }
            
            // موجودی
            $stock = intval($variation_data['stock_quantity'] ?? 0);
            $variation->set_stock_quantity($stock);
            $variation->set_manage_stock(true);
            $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            
            // ⭐ SKU — اگر SKU تکراری باشد آن را خالی بگذار تا WooCommerce exception نیاندازد
            $sku = $variation_data['sku'] ?? '';
            if (!empty($sku)) {
                $existing = wc_get_product_id_by_sku($sku);
                if (!$existing || $existing == $product_id) {
                    // SKU موجود نیست یا متعلق به همین محصول است — قابل استفاده
                    try {
                        $variation->set_sku($sku);
                    } catch (Exception $sku_e) {
                        // SKU تکراری — رد کن
                        $variation->set_sku('');
                    }
                }
                // اگر SKU متعلق به محصول دیگری بود، خالی می‌ماند
            }
            
            // عکس متغیر
            if (!empty($variation_data['image_url'])) {
                $img_id = $this->download_image($variation_data['image_url'], $product_id);
                if ($img_id) {
                    $variation->set_image_id($img_id);
                }
            }
            
            // s1_id را از داده ورودی بخوان (اگر سایت ۱ ارسال کرده باشد)
            $s1_id = intval($variation_data['_s1_id'] ?? 0);

            $var_id = $variation->save();

            if (!is_wp_error($var_id) && $var_id) {
                // برگرداندن آرایه‌ای شامل ID، SKU و s1_id (برای auto-mapping دقیق)
                return ['id' => $var_id, 'sku' => $sku, 's1_id' => $s1_id];
            }
            return false;
        } catch (Exception $e) {
            error_log('PIE Variation creation error: ' . $e->getMessage());
            return false;
        }
    }
    
    private function download_image($image_url, $post_id) {
        try {
            if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
                error_log('Invalid image URL: ' . $image_url);
                return null;
            }
            
            // دانلود با timeout بیشتر
            $response = wp_remote_get($image_url, [
                'timeout' => 30,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
            ]);
            
            if (is_wp_error($response)) {
                error_log('Image download error: ' . $response->get_error_message());
                return null;
            }
            
            $image_data = wp_remote_retrieve_body($response);
            if (empty($image_data)) {
                error_log('Empty image data for: ' . $image_url);
                return null;
            }
            
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            if (empty($filename) || !preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
                $filename = 'image-' . time() . '-' . rand(1000, 9999) . '.jpg';
            }
            
            $upload = wp_upload_bits($filename, null, $image_data);
            if (!empty($upload['error'])) {
                error_log('Upload error: ' . $upload['error']);
                return null;
            }
            
            $filetype = wp_check_filetype($filename);
            $attachment = [
                'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
                'post_title' => sanitize_file_name($filename),
                'post_status' => 'inherit'
            ];
            
            if ($post_id > 0) {
                $attachment['post_parent'] = $post_id;
            }
            
            $attachment_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
            if (is_wp_error($attachment_id)) {
                error_log('Attachment insert error: ' . $attachment_id->get_error_message());
                return null;
            }
            
            if ($attachment_id) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
                if ($metadata) {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }
                return $attachment_id;
            }
            
            return null;
        } catch (Exception $e) {
            error_log('Image download exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function convert_to_csv($products) {
        $csv = "نام,SKU,قیمت,موجودی,نوع,دسته‌بندی\n";
        
        foreach ($products as $product) {
            $categories = '';
            if (!empty($product['categories'])) {
                $cat_names = [];
                foreach ($product['categories'] as $cat) {
                    $cat_names[] = $cat['name'];
                }
                $categories = implode(';', $cat_names);
            }
            
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s"' . "\n",
                str_replace('"', '""', $product['name']),
                $product['sku'] ?? '',
                $product['price'] ?? '',
                $product['stock_quantity'] ?? '',
                $product['type'] ?? 'simple',
                str_replace('"', '""', $categories)
            );
        }
        
        return mb_convert_encoding($csv, 'UTF-8', 'UTF-8');
    }
    
    private function parse_csv($content) {
        $lines = explode("\n", trim($content));
        if (empty($lines)) return [];
        
        $header = str_getcsv(array_shift($lines));
        $products = [];
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $values = str_getcsv($line);
            if (count($values) !== count($header)) continue;
            
            $products[] = array_combine($header, $values);
        }
        
        return $products;
    }
}

// فعال‌سازی پلاگین
Product_Import_Export::get_instance();
