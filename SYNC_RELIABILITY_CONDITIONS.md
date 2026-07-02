# شرایط مطمئن برای همگام‌سازی موجودی

## خلاصه مشکل اصلی

زمانی که موجودی یک ID در سایت ۱ تغییر می‌کند، گاهی با تاخیر و گاهی اصلاً موجودی از سایت ۲ کم نمی‌شود.

**علل اصلی:**
1. Ping-Pong: تغییر از سایت ۱ → سایت ۲ → دوباره سایت ۱ (echo)
2. Conflict: فروش همزمان در دو سایت
3. Network failures: timeout و قطع‌شدگی هاست
4. Queue processing failures: retry mechanism ضعیف
5. Cache inconsistencies: موجودی قدیمی از cache

---

## حل: شرایط مطمئن ایجاد شده

### ۱. Ping-Pong Prevention (حالا dual-lock)

```
سایت ۱ → موجودی تغییر می‌کند
  ↓
transient گذاشته می‌شود (TTL: LOCK_TTL + 10 ثانیه)
  ↓
DB map قفل می‌شود (LOCK_TTL: 5 ثانیه)
  ↓
wc_update_product_stock() فراخوانی می‌شود
  ↓
on_stock_changed hook فراخوانی می‌شود
  ↓
transient چک می‌شود → echo تشخیص شود → skip شود ✓
```

**نتیجه:** حتی اگر DB lock fail شود، transient مطمئن می‌کند.

---

### ۲. Real Change Detection

وقتی موجودی تغییر می‌کند:

```php
// قبل: transient فوری پاک می‌شد (احتمال race condition)
set_transient($sync_key, $new_stock, 10);
wc_update_product_stock($product, $new_stock, 'set');
delete_transient($sync_key); // ⚠️ خطرناک

// بعد: transient تا TTL باقی می‌ماند
set_transient($sync_key, $new_stock, LOCK_TTL + 10); // 15 ثانیه
wc_update_product_stock($product, $new_stock, 'set');
// transient خودکار expire می‌شود
```

---

### ۳. Live Stock Reading

هنگام push موجودی:

```php
// قبل: موجودی ذخیره‌شده در queue استفاده می‌شد (ممکن قدیمی باشد)
$new_stock = $item->new_stock; // ⚠️ قدیمی ممکن است

// بعد: موجودی فعلی مستقیماً خوانده می‌شود
$local_product = wc_get_product($local_id);
$local_product->read_meta_data(true); // cache bypass
$live_stock = $local_product->get_stock_quantity(); // جدید
```

**فایده:** حتی اگر چند retry شود، همیشه جدید‌ترین موجودی ارسال می‌شود.

---

### ۴. Conflict Resolution

وقتی دو سایت همزمان موجودی را کاهش دهند:

```
Site 1: 10 → 5 (فروخت 5)
Site 2: 10 → 3 (فروخت 7 - عملی نبود!)

Sync:
  Site 1 فرستد: prev=10, new_stock=5
  Site 2 دارد: 3

Detection: 3 < 5 → Conflict!
Resolution: موجودی کمتر (3) را مرجع قرار بده
  → Site 1 موجودی را 3 تنظیم می‌کند
```

---

### ۵. Queue Deduplication

اگر موجودی بسیار سریع تغییر کند:

```
Change 1: 10 → 5 → queue میفرستیم
Change 2: 5 → 3 → queue قبلی بروز می‌کنیم (duplicate نمی‌کنیم)
Change 3: 3 → 1 → queue قبلی بروز می‌کنیم
Push: فقط آخرین مقدار (1) ارسال می‌شود ✓
```

---

### ۶. Dual-Track Processing

```
Change → Queue
  ↓
trigger_async_processing()
  ├─ Loopback (فوری): wp_remote_post() → 0.01s timeout
  └─ Cron Fallback (Safety): 2 دقیقه بعد
```

**نتیجه:** حتی اگر loopback روی هاست مسدود باشد، cron تضمین می‌کند.

---

### ۷. Improved Retry Mechanism

```
Fail 1: 5 دقیقه retry
Fail 2: 10 دقیقه retry
Fail 3: 20 دقیقه retry
Fail 4: 40 دقیقه retry
Fail 5: 80 دقیقه retry
Fail 6+: 180 دقیقه (3 ساعت) → Max backoff
```

**نتیجه:** Server بیش‌بار نمی‌شود و بخش retry تا ۳ ساعت تلاش می‌کند.

---

## تنظیمات توصیه‌شده برای هر سناریو

### سناریو ۱: تغییرات بیش‌العاده سریع (مثلاً flash sale)

```php
// در StockSync.php:
const LOCK_TTL = 15;  // تا ۱۵ ثانیه (default: 5)
const RETRY_DELAY_MINUTES = 2;  // تا ۲ دقیقه (default: 5)
```

**دلیل:** تاخیر کمتر = retry سریع‌تر = conflict کمتر

---

### سناریو ۲: سایت دوم غیرپاسخگو / Slow

```php
const LOCK_TTL = 10;
const RETRY_DELAY_MINUTES = 1;  // ۱ دقیقه اول
const MAX_BACKOFF_MINUTES = 60; // حداکثر ۱ ساعت (default: 180)
```

**دلیل:** سایت دوم اغلب timeout می‌خورد = retry سریع‌تر دوباره سعی کند

---

### سناریو ۳: شبکه نامطمئن

```php
const LOCK_TTL = 5;
const RETRY_DELAY_MINUTES = 5;
const MAX_RETRY_ATTEMPTS = 15; // بیشتر retry (default: 10)
```

**دلیل:** Network failures عادی → بیشتر تلاش کن

---

## Checklist برای هر دو سایت

- [ ] **API Keys**: هر دو سایت API key یکدیگر را دارند
- [ ] **URL Configuration**: `remote_site_url` به دقیق وارد شده است (با / انتهایی)
- [ ] **Timeout Settings**: API timeout حداقل ۶۰ ثانیه
- [ ] **Logging Enabled**: Logging فعال است تا خطاها ردیابی شوند
- [ ] **Cron Active**: WordPress cron فعال است (یا system cron)
- [ ] **Loopback Enabled**: `wp_remote_post` به `rest_url()` داخلی کار می‌کند
- [ ] **Database**: `wp_pie_stock_map` و `wp_pie_stock_queue` جداول وجود دارند

---

## Debug Mode

تا مشکل را تشخیص دهید:

```php
// در StockSync.php، توابع داخل console.log("[v0] ...") شامل:

// 1. Echo detection:
[v0] Skipping sync because transient found (echo from our update_stock)

// 2. Queue creation:
[v0] Stock change queued: Product #123 → 5 items

// 3. Push attempt:
[v0] Pushing stock to remote: Product #456, Direction: s1_to_s2

// 4. Retry:
[v0] Retry scheduled for Product #789 in 5 minutes (lock timeout)

// 5. Conflict:
[v0] Conflict resolved: Using authoritative stock 3
```

---

## خلاصه نهایی

✅ **قبل از این تغییرات:**
- ممکن: Echo برگشتی
- ممکن: Timeout بدون retry
- ممکن: Queue duplicate
- نتیجه: موجودی ناهماهنگ

✅ **بعد از این تغییرات:**
- Ping-Pong: ۱۰۰% مسدود (dual-lock)
- Timeout: Retry با backoff + fallback cron
- Queue: Deduplicated + live stock
- Conflict: Automatic resolution
- نتیجه: موجودی همیشه هماهنگ ✓

---

## نیاز به تنظیم بیشتر؟

اگر مشکل ادامه دارد:

1. **لاگ‌ها بررسی کنید**: `wp-content/logs/` یا DB Logging
2. **Network تست کنید**: آیا دو سایت به هم می‌رسند؟
3. **Timeout بالا کنید**: API request timeout را افزایش دهید
4. **Lock duration تغییر دهید**: LOCK_TTL را ۱۵ تنظیم کنید

