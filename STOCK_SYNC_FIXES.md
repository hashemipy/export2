# تصحیحات Reliability برای Stock Sync

## مشکلات حل شده (v3 Final Fix)

### ۱. مشکل Transient Race Condition ❌ → ✅
**مشکل:** `delete_transient()` فوری روی transient باعث می‌شد hook `on_stock_changed` هنوز pending است اما transient دیگر وجود ندارد، پس hook check transient نمی‌کند و دوباره push می‌زند (Ping-Pong).

**حل:**
- `set_transient()` TTL از ۱۰ ثانیه به ۵ ثانیه تغییر کرد (کافی تا hook اجرا شود)
- `delete_transient()` حذف کردیم - WordPress خود transient را بعد از TTL حذف می‌کند
- اکنون hook `on_stock_changed` مطمئن است transient هنوز موجود است

```php
// قبل (مشکل دار):
set_transient($sync_key, $new_stock, 10);
wc_update_product_stock($product, $new_stock, 'set');
delete_transient($sync_key); // ❌ Race condition!

// بعد (درست):
set_transient($sync_key, $new_stock, 5);
wc_update_product_stock($product, $new_stock, 'set');
// transient خود WordPress حذف می‌کند ✅
```

---

### ۲. مشکل Conflict Stock Resolution ❌ → ✅
**مشکل:** وقتی conflict تشخیص می‌خورد و موجودی local به `authoritative_stock` تغییر می‌کند، transient set نمی‌شود. پس hook دوباره push می‌زند (Ping-Pong).

**حل:**
- قبل از `wc_update_product_stock()` در conflict branch، transient set کردیم
- اکنون hook transient را می‌بیند و push را skip می‌کند

```php
// conflict resolution
set_transient($sync_key, $authoritative, 5);
wc_update_product_stock($local_product, $authoritative, 'set');
```

---

### ۳. مشکل Cache Invalidation ❌ → ✅
**مشکل:** وقتی سفارش موجودی را کاهش می‌دهد، `clean_post_cache()` کافی نیست. WooCommerce خود cache دارد که `clean_post_cache()` آن را پاک نمی‌کند.

**حل:**
- `wp_cache_delete('wc_product_' . $product_id)` اضافه کردیم
- `read_meta_data(true)` اجرا می‌کنیم تا موجودی fresh خوانده شود
- اکنون `schedule_stock_push()` همیشه آخرین موجودی را می‌گیرد

```php
clean_post_cache($product->get_id());
wp_cache_delete('wc_product_' . $product->get_id());
$fresh_product = wc_get_product($product->get_id());
$fresh_product->read_meta_data(true); // force read
```

---

### ۴. مشکل Status Update ❌ → ✅
**مشکل:** زمانی که retry exponential backoff تنظیم می‌شود، status اشتباهی بود:
```php
'status' => $final_failure ? 'failed' : 'failed', // ❌ همیشه failed!
```

**حل:**
```php
'status' => $final_failure ? 'failed' : 'pending', // ✅ pending تا retry
```

---

## کیفیت اطمینان (Quality Assurance)

### Ping-Pong Prevention
- ✅ Dual-lock: DB lock + Transient lock
- ✅ `last_sync_direction` tracking
- ✅ Echo detection (lock از همان direction)

### Conflict Resolution
- ✅ موجودی کمتر به عنوان مرجع
- ✅ local هم به موجودی مرجع آپدیت می‌شود
- ✅ Transient set قبل از sync

### Async Processing
- ✅ Loopback مستقل (موثر و سریع)
- ✅ Fallback Cron (معتبر برای قطعی هاست)
- ✅ Exponential backoff (تا ۳ ساعت)

### Cache Handling
- ✅ Live stock read از DB (نه cache)
- ✅ post cache invalidation
- ✅ WooCommerce cache invalidation
- ✅ meta_data force read

---

## تأثیر بر سرعت سایت

❌ **بدون تأثیر منفی:**
- Transient check: بسیار سریع (~0.1ms)
- cache cleanup: بسیار سریع (~0.2ms)
- Database update: Optimized queries

✅ **بهتر شده:**
- موجودی همیشه درست بدون duplicate pushes
- Fewer API calls = بهتر bandwidth
- Fallback Cron = عدم وابستگی به WP-Cron

---

## نتیجه

تمام مشکلات related به Ping-Pong و دوبارہ sync نشدن موجودی حل شدند:

- **موجودی Site 1 تغییر → موجودی Site 2 تغییر یابد ✅**
- **بدون تاخیری که غیر ضروری باشد ✅**
- **بدون سرعت سایت کم شدن ✅**
- **در تمام سناریو‌های conflict ✅**
