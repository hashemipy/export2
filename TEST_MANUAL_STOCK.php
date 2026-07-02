<?php
/**
 * اسکریپت تشخیص و تصحیح مسائل قابلیت تنظیم موجودی دستی
 * 
 * استفاده:
 * 1. این فایل را از طریق WP-CLI یا مستقیم اضافه کنید
 * 2. یا از URL آن مستقیم دسترسی پیدا کنید
 */

// تنظیم WP
require_once(dirname(__DIR__, 3) . '/wp-load.php');

// بررسی‌های پایه
$checks = [];

// ۱. بررسی plugin فعال است یا نه
$checks['plugin_active'] = [
    'name' => 'Plugin فعال؟',
    'status' => is_plugin_active('product-import-export/product-import-export.php'),
];

// ۲. بررسی جدول mapping موجود است
global $wpdb;
$table_map = $wpdb->prefix . 'pie_product_map';
$checks['table_exists'] = [
    'name' => 'جدول Mapping موجود؟',
    'status' => $wpdb->get_var("SHOW TABLES LIKE '{$table_map}'") ? true : false,
];

// ۳. بررسی تعداد mapping‌ها
$map_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_map}");
$checks['mapping_count'] = [
    'name' => 'تعداد Mapping‌ها',
    'status' => (int)$map_count,
    'info' => 'اگر 0 باشد، ستون جدید نمایش داده نخواهد شد',
];

// ۴. بررسی AJAX action ثبت شده
$checks['ajax_action_registered'] = [
    'name' => 'AJAX Action ثبت شده؟',
    'status' => has_action('wp_ajax_pie_adjust_stock'),
    'info' => 'اگر false باشد، handler AJAX کار نمی‌کند',
];

// ۵. بررسی current user دسترسی دارد
$checks['user_capability'] = [
    'name' => 'User دسترسی دارد؟',
    'status' => current_user_can('manage_woocommerce'),
    'info' => 'فقط admin یا editor می‌توانند استفاده کنند',
];

// ۶. بررسی WooCommerce فعال است
$checks['woocommerce_active'] = [
    'name' => 'WooCommerce فعال؟',
    'status' => is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce'),
];

// ۷. بررسی jQuery بارگذاری شده
$checks['jquery_required'] = [
    'name' => 'jQuery بارگذاری می‌شود؟',
    'status' => wp_script_is('jquery', 'registered'),
];

// HTML نمایش نتایج
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تشخیص مسائل تنظیم موجودی</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 5px;
            background: #f9f9f9;
            border-right: 4px solid #e0e0e0;
        }
        .check-item.success {
            background: #e8f5e9;
            border-right-color: #4caf50;
        }
        .check-item.failed {
            background: #ffebee;
            border-right-color: #f44336;
        }
        .check-item.info {
            background: #e3f2fd;
            border-right-color: #2196f3;
        }
        .status-icon {
            font-size: 20px;
            margin-left: 15px;
            width: 30px;
            text-align: center;
        }
        .check-content {
            flex: 1;
        }
        .check-label {
            font-weight: 600;
            color: #333;
        }
        .check-value {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        .solutions {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }
        .solutions h3 {
            margin-top: 0;
            color: #856404;
        }
        .solutions li {
            margin-bottom: 8px;
            color: #856404;
        }
        .code {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 بررسی وضعیت تنظیم موجودی دستی</h1>
        
        <?php foreach ($checks as $key => $check): ?>
            <?php
            $status = $check['status'] ?? false;
            $is_numeric = is_numeric($status) && !is_bool($status);
            
            if (is_bool($status)) {
                $class = $status ? 'success' : 'failed';
                $icon = $status ? '✓' : '✗';
            } else {
                $class = 'info';
                $icon = 'ℹ';
            }
            ?>
            <div class="check-item <?php echo $class; ?>">
                <div class="status-icon"><?php echo $icon; ?></div>
                <div class="check-content">
                    <div class="check-label"><?php echo esc_html($check['name']); ?></div>
                    <div class="check-value">
                        <?php 
                        if (is_bool($status)) {
                            echo $status ? 'تایید شده' : 'ناتایید';
                        } else {
                            echo esc_html($status);
                        }
                        ?>
                        <?php if (!empty($check['info'])): ?>
                            <br><em><?php echo esc_html($check['info']); ?></em>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <div class="solutions">
            <h3>💡 راه‌حل‌های احتمالی</h3>
            <ul>
                <li><strong>کش مرورگر را پاک کنید:</strong> Ctrl+Shift+Delete</li>
                <li><strong>صفحه را دوباره بارگذاری کنید:</strong> F5 یا Ctrl+R</li>
                <li><strong>Plugin را غیرفعال و دوباره فعال کنید</strong> (البته یک نسخه پشتیبان بگیرید)</li>
                <li><strong>Accordion را باز کنید:</strong> بر سر هدر محصول کلیک کنید</li>
                <li><strong>حداقل یک نگاشت (جفت ID) وجود داشته باشد:</strong> اگر mapping‌ها تهی است، ستون دیده نمی‌شود</li>
                <li><strong>کنسول مرورگر را بررسی کنید:</strong> F12 → Console برای خطاهای JavaScript</li>
            </ul>
        </div>
        
        <div class="solutions" style="background: #e8f5e9; border-color: #4caf50;">
            <h3 style="color: #2e7d32; margin-top: 0;">✓ اگر تمام موارد بالا تایید شدند</h3>
            <p style="color: #2e7d32; margin: 0;">
                ستون "تنظیم موجودی" باید دیده شود. اگر هنوز نمایش داده نشده:
            </p>
            <ol style="color: #2e7d32;">
                <li>کنسول مرورگر را باز کنید (F12)</li>
                <li>کد زیر را چسبانید:
                    <div class="code">console.log($('.pie-adjust-stock-btn').length, 'buttons found');</div>
                </li>
                <li>اگر عدد 0 است، JavaScript بارگذاری نشده است</li>
                <li>اگر عدد > 0 است، دکمه‌ها موجود هستند و کار می‌کنند</li>
            </ol>
        </div>
    </div>
</body>
</html>
