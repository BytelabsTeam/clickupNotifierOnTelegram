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
4. در BotFather گزینه **Group Privacy** را خاموش کنید (`/setprivacy` → Disable) تا ربات دستورهای داخل گروه را ببیند.
5. یک مقدار تصادفی برای `TELEGRAM_WEBHOOK_SECRET` بگذارید (حروف، عدد، `_` و `-`).

## ربات تلگرام (دستورها)

با وبهوک کار می‌کند؛ نیازی به polling نیست. بعد از پر کردن `.env`:

```bash
php artisan telegram:register-webhook
```

روی هاست اشتراکی (بدون SSH) همین URL را یک‌بار در مرورگر باز کنید:

```
https://yourdomain.com/clickup_notif_on_telegram/telegram/set-webhook?token=CLICKUP_CRON_TOKEN
```

آدرس وبهوک:

```
POST /api/webhooks/telegram
```

امضای هدر `X-Telegram-Bot-Api-Secret-Token` با `TELEGRAM_WEBHOOK_SECRET` بررسی می‌شود.

در گروه تنظیم‌شده یا در چت خصوصی (اگر یوزرنیم‌تان در `TELEGRAM_USERNAMES` باشد) می‌توانید بزنید:

| دستور | معادل فارسی | کار |
|---|---|---|
| `/tasks` | لیست | تسک‌های باز |
| `/today` | امروز | تسک‌های امروز |
| `/tomorrow` | فردا | امروز، فردا و عقب‌افتاده |
| `/overdue` | عقب افتاده | فقط عقب‌افتاده |
| `/me` | تسک های من | فقط تسک‌های خودتان |
| `/topday` | برترین روز | بیشترین خط کد امروز |
| `/topweek` | برترین هفته | بیشترین خط کد این هفته |
| `/topmonth` | برترین ماه | بیشترین خط کد این ماه |
| `/help` | راهنما | لیست دستورها |

### گیت‌هاب (برترین‌ها)

رتبه‌بندی بر اساس **خطوط اضافه‌شده** در برنچ پیش‌فرض ریپوهاست (کامیت‌های merge حساب نمی‌شوند).

در `.env`:

```env
GITHUB_TOKEN=github_pat_...
GITHUB_REPOS=org/app,https://github.com/org/api
GITHUB_USER_LOGINS='{"arefmohaamd332@gmail.com":"aref-github","ali@example.com":"ali-github"}'
```

- توکن را از [Fine-grained personal access tokens](https://github.com/settings/personal-access-tokens/new) بسازید.
  - Resource owner: سازمان `BytelabsTeam`
  - Repository access: فقط ریپوهای لیست‌شده در `GITHUB_REPOS`
  - Permissions → Repository: **Contents = Read-only**
- `GITHUB_REPOS` را با ویرگول جدا کنید (`owner/repo` یا لینک کامل).
- `GITHUB_USER_LOGINS` یوزرنیم گیت‌هاب را به همان ایمیلی وصل می‌کند که در `CLICKUP_USER_NAMES` و `TELEGRAM_USERNAMES` دارید تا اسم فارسی و تگ تلگرام درست نمایش داده شود.

بعد از تغییر دستورها یک‌بار وبهوک را دوباره ست کنید تا منوی ربات به‌روز شود.

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
CLICKUP_USER_NAMES='{"arefmohaamd332@gmail.com":"عارف","ali@example.com":"علی"}'
```

اگر نام فارسی فاصله دارد (مثلاً «حمید تد»)، کل مقدار JSON را داخل **تک‌کوتیشن** بگذارید؛ در غیر این صورت Laravel هنگام خواندن `.env` خطای 500 می‌دهد:

```env
CLICKUP_USER_NAMES='{"hamid@example.com":"حمید تد"}'
```

اگر ایمیلی نگاشت نشده باشد، `username` خود ClickUp استفاده می‌شود.

## استقرار روی cPanel

1. فایل‌های پروژه را آپلود کنید (شامل `app/`، `bootstrap/`، `config/`). پوشه `bootstrap/cache/` را آپلود نکنید، یا بعد از آپلود فایل‌های `packages.php` و `services.php` داخل آن را حذف کنید؛ در غیر این صورت خطای `PailServiceProvider not found` می‌آید.
2. Document Root را روی پوشه پروژه بگذارید — فایل `.htaccess` ریشه درخواست‌ها را به `public/` هدایت می‌کند؛ نیازی به `/public/` در URL نیست.
3. `.env` را روی سرور پر کنید (ClickUp، Telegram، `CLICKUP_CRON_TOKEN`). `APP_URL` باید دقیقاً همان آدرس زیرپوشه باشد، مثلاً:

```env
APP_URL=https://xai.po2.top/clickup_notif_on_telegram
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
