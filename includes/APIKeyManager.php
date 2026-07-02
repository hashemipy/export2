<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * مدیریت کلیدهای API اختصاصی برای پلاگین
 */
class PIE_APIKeyManager {
    private static $instance = null;
    private $option_key = 'pie_api_keys';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * تولید یک کلید API جدید
     */
    public function generate_key() {
        return 'pie_' . bin2hex(random_bytes(32));
    }
    
    /**
     * تولید یک Secret جدید
     */
    public function generate_secret() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * ذخیره یک کلید API جدید
     */
    public function create_key($name) {
        $keys = $this->get_keys();
        
        $new_key = [
            'name' => sanitize_text_field($name),
            'key' => $this->generate_key(),
            'secret' => $this->generate_secret(),
            'created_at' => current_time('mysql'),
            'active' => true
        ];
        
        $keys[] = $new_key;
        update_option($this->option_key, $keys);
        
        error_log("[PIE] New API key created: " . $new_key['name']);
        
        return $new_key;
    }
    
    /**
     * دریافت تمام کلیدهای API
     */
    public function get_keys() {
        return get_option($this->option_key, []);
    }
    
    /**
     * اعتبارسنجی یک کلید و Secret
     */
    public function validate_key($key, $secret) {
        $keys = $this->get_keys();
        
        error_log("[PIE] Validating API key: " . substr($key, 0, 10) . "...");
        
        foreach ($keys as $stored_key) {
            if ($stored_key['active'] && $stored_key['key'] === $key && $stored_key['secret'] === $secret) {
                error_log("[PIE] API key validated: " . $stored_key['name']);
                return $stored_key;
            }
        }
        
        error_log("[PIE] API key validation failed");
        return false;
    }
    
    /**
     * حذف یک کلید
     */
    public function delete_key($key) {
        $keys = $this->get_keys();
        $keys = array_filter($keys, function($k) use ($key) {
            return $k['key'] !== $key;
        });
        update_option($this->option_key, $keys);
    }
    
    /**
     * غیرفعال کردن یک کلید
     */
    public function disable_key($key) {
        $keys = $this->get_keys();
        foreach ($keys as &$k) {
            if ($k['key'] === $key) {
                $k['active'] = false;
            }
        }
        update_option($this->option_key, $keys);
    }
}
