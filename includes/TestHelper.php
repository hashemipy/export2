<?php
/**
 * Test Helper برای بررسی مشکلات
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIE_TestHelper {
    
    /**
     * تست ارسال محصول (AJAX)
     */
    public static function test_send_product() {
        check_ajax_referer('pie_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('دسترسی ندارید');
        }
        
        $settings = PIE_Settings::get_instance();
        $config = $settings->get_config();
        $logging = PIE_Logging::get_instance();
        
        $results = [];
        
        // بررسی تنظیمات
        $results['config'] = [
            'site_role' => $config['site_role'],
            'remote_site_url' => !empty($config['remote_site_url']) ? 'تنظیم شده' : 'خالی',
            'api_consumer_key' => !empty($config['api_consumer_key']) ? 'تنظیم شده' : 'خالی',
            'api_consumer_secret' => !empty($config['api_consumer_secret']) ? 'تنظیم شده' : 'خالی',
        ];
        
        // تست Health Check
        if (!empty($config['remote_site_url'])) {
            $response = wp_remote_get(
                rtrim($config['remote_site_url'], '/') . '/wp-json/pie/v1/health-check',
                ['sslverify' => false, 'timeout' => 10]
            );
            
            if (is_wp_error($response)) {
                $results['health_check'] = 'خطا: ' . $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $results['health_check'] = [
                    'status' => $code,
                    'data' => $body
                ];
            }
        }
        
        // تست ارسال محصول با ID 0 (برای تست بدون محصول واقعی)
        if (!empty($config['api_consumer_key']) && !empty($config['remote_site_url'])) {
            $key = trim($config['api_consumer_key']);
            $secret = trim($config['api_consumer_secret']);
            
            error_log("[PIE TEST] Key length: " . strlen($key));
            error_log("[PIE TEST] Secret length: " . strlen($secret));
            error_log("[PIE TEST] Remote URL: " . $config['remote_site_url']);
            
            $test_data = [
                'name' => '[تست] محصول تستی - ' . date('Y-m-d H:i:s'),
                'description' => 'این یک محصول تستی است',
                'price' => '99.99',
                'sku' => 'TEST-' . time(),
                'stock_quantity' => 10,
                'manage_stock' => true,
                'type' => 'simple',
                'categories' => [],
                'tags' => [],
                'images' => []
            ];
            
            $auth = base64_encode($key . ':' . $secret);
            
            error_log("[PIE TEST] Sending test product...");
            
            $response = wp_remote_post(
                rtrim($config['remote_site_url'], '/') . '/wp-json/pie/v1/receive-product',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . $auth
                    ],
                    'body' => wp_json_encode($test_data),
                    'timeout' => 30,
                    'sslverify' => false,
                    'blocking' => true
                ]
            );
            
            if (is_wp_error($response)) {
                $results['test_send'] = ['status' => 'error', 'message' => $response->get_error_message()];
                error_log("[PIE TEST] Error: " . $response->get_error_message());
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $results['test_send'] = [
                    'status' => $code,
                    'data' => $body
                ];
                
                error_log("[PIE TEST] Response code: " . $code);
                error_log("[PIE TEST] Response body: " . wp_json_encode($body));
                
                if ($code === 200 || $code === 201) {
                    $logging->log('test', 'success', 'تست ارسال موفق بود');
                } else {
                    $logging->log('test', 'failed', 'تست ارسال ناموفق: HTTP ' . $code);
                }
            }
        } else {
            $results['test_send'] = ['status' => 'error', 'message' => 'API credentials or remote URL is empty'];
        }
        
        wp_send_json_success($results);
    }
}

// ثبت AJAX
add_action('wp_ajax_pie_test_send', ['PIE_TestHelper', 'test_send_product']);
