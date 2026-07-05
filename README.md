# اعلان Done تسک‌های ClickUp در تلگرام

این پروژه Laravel رویداد `taskStatusUpdated` از ClickUp را دریافت می‌کند و وقتی تسکی Done می‌شود، پیامی شبیه این در گروه تلگرام ارسال می‌کند:

> عارف تسک "رفع باگ لاگین" رو انجام داد ✅

## پیش‌نیازها

- PHP 8.2+
- Composer
- دامنه با HTTPS (برای وب‌هوک ClickUp)
- توکن API از ClickUp
- Bot تلگرام و `chat_id` گروه

## نصب محلی

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

مقادیر `.env` را پر کنید (توضیحات در `.env.example`).

## تنظیم تلگرام

1. از [@BotFather](https://t.me/BotFather) یک Bot بسازید و `TELEGRAM_BOT_TOKEN` را بگیرید.
2. Bot را به گروه اضافه کنید و به آن دسترسی ارسال پیام بدهید.
3. `chat_id` گروه را در `TELEGRAM_CHAT_ID` قرار دهید (معمولاً با `-100` شروع می‌شود).

## تنظیم ClickUp

1. از [ClickUp Settings > Apps](https://app.clickup.com/settings/apps) یک Personal API Token بسازید.
2. `CLICKUP_API_TOKEN` و `CLICKUP_TEAM_ID` (Workspace ID) را در `.env` قرار دهید.

## روش پیشنهادی: Polling (بدون webhook)

به‌جای webhook، ClickUp را چک می‌کند و تسک‌های Done شده را در تلگرام اعلام می‌کند.

### روی هاست اشتراکی (cPanel) — بدون دستور artisan

روی هاست معمولی نمی‌توانی `--loop` اجرا کنی. یک URL امن می‌سازی که cPanel هر دقیقه آن را صدا بزند:

**1.** در `.env` یک توکن تصادفی بگذار:

```env
CLICKUP_CRON_TOKEN=یک-رشته-تصادفی-طولانی
```

**2.** فایل‌ها را آپلود کن.

**3.** در cPanel → **Cron Jobs** → نوع **Fetch a URL**:

```
https://yourdomain.com/clickup_notif_on_telegram/cron/clickup-poll?token=همان-توکن-بالا
```

زمان‌بندی: `* * * * *` (هر ۱ دقیقه — حداقل cPanel)

**4.** تست: همان URL را در مرورگر باز کن. باید ببینی:

```json
{"status":"ok","notified":0,"checked_at":"..."}
```

### روی سرور/VPS (با SSH)

```bash
php artisan clickup:poll --loop --interval=10
```

تنظیمات اختیاری در `.env`:

```env
CLICKUP_POLL_INTERVAL=10
CLICKUP_POLL_LOOKBACK_MINUTES=30
CLICKUP_CRON_TOKEN=...
```

اولین بار ۳۰ دقیقه اخیر را اسکن می‌کند. بعد از آن فقط تسک‌های به‌روز شده را می‌گیرد.

## روش قدیمی: Webhook (اختیاری)

3. `CLICKUP_WEBHOOK_ENDPOINT` را روی آدرس عمومی برنامه تنظیم کنید:
   `https://yourdomain.com/api/webhooks/clickup`
4. وب‌هوک را ثبت کنید:

```bash
php artisan clickup:register-webhook
```

مقدار `CLICKUP_WEBHOOK_SECRET` که در خروجی چاپ می‌شود را در `.env` ذخیره کنید.

## نگاشت نام فارسی کاربران

در `.env` ایمیل هر کاربر را به نام فارسی نگاشت کنید:

```env
CLICKUP_USER_NAMES='{"user@example.com":"عارف","ali@example.com":"علی"}'
```

اگر نام فارسی فاصله دارد (مثلاً «حمید تد»)، کل مقدار JSON را داخل **تک‌کوتیشن** بگذارید؛ در غیر این صورت Laravel هنگام خواندن `.env` خطای 500 می‌دهد:

```env
CLICKUP_USER_NAMES='{"hamid@example.com":"حمید تد"}'
```

اگر ایمیلی نگاشت نشده باشد، `username` خود ClickUp استفاده می‌شود.

## استقرار روی cPanel

1. فایل‌های پروژه را آپلود کنید (شامل `app/`، `bootstrap/`، `config/`).
2. Document Root را روی پوشه پروژه بگذارید — فایل `.htaccess` ریشه درخواست‌ها را به `public/` هدایت می‌کند؛ نیازی به `/public/` در URL نیست.
3. `.env` را روی سرور پر کنید (ClickUp، Telegram، `CLICKUP_CRON_TOKEN`). `APP_URL` باید دقیقاً همان آدرس زیرپوشه باشد، مثلاً:

```env
APP_URL=https://yourdomain.com/clickup_notif_on_telegram
```

4. در cPanel یک Cron Job از نوع **Fetch URL** بسازید (هر ۱ دقیقه):

```
https://yourdomain.com/clickup_notif_on_telegram/cron/clickup-poll?token=CLICKUP_CRON_TOKEN
```

نیازی به `php artisan` روی هاست نیست.

## endpoint وب‌هوک

```
POST /api/webhooks/clickup
```

- امضای `X-Signature` با `CLICKUP_WEBHOOK_SECRET` بررسی می‌شود.
- فقط وقتی `after.type = closed` یا نام وضعیت در `CLICKUP_DONE_STATUSES` باشد، پیام ارسال می‌شود.

## تست

```bash
php artisan test
```

## مستندات مرتبط

- [ClickUp Webhooks](https://developer.clickup.com/docs/webhooks)
- [Webhook Signature](https://developer.clickup.com/docs/webhooksignature)
- [Task Webhook Payloads](https://developer.clickup.com/docs/webhooktaskpayloads)
