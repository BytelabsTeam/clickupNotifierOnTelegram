<div align="center">

# ClickUp → Telegram Notifier

**Celebrate every completed task — right in your team chat.**

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
[![Tests](https://img.shields.io/badge/Tests-PHPUnit-6C5CE7?style=for-the-badge&logo=phpunit&logoColor=white)](tests/)

[![ClickUp](https://img.shields.io/badge/ClickUp-API-7B68EE?style=flat-square&logo=clickup&logoColor=white)](https://developer.clickup.com/)
[![Telegram](https://img.shields.io/badge/Telegram-Bot%20API-26A5E4?style=flat-square&logo=telegram&logoColor=white)](https://core.telegram.org/bots/api)
[![Shared Hosting](https://img.shields.io/badge/Shared%20Hosting-cPanel%20Ready-00A86B?style=flat-square)](https://cpanel.net/)
[![SQLite](https://img.shields.io/badge/Database-SQLite-003B57?style=flat-square&logo=sqlite&logoColor=white)](https://www.sqlite.org/)

[Features](#-features) ·
[Quick Start](#-quick-start) ·
[Deployment](#-deployment-shared-hosting) ·
[Configuration](#%EF%B8%8F-configuration) ·
[فارسی](#-فارسی)

</div>

---

## The idea

Your team ships in ClickUp. Your conversations happen in Telegram. This tiny bridge closes the loop: when someone marks a task **Done**, everyone in the group sees it instantly — with a friendly message like:

> **Aref** completed **"Fix login bug"** ✅

No more refreshing boards. No more “did anyone see that?” Just a steady stream of wins where your team already hangs out.

Built for teams who want the celebration without the enterprise price tag. Drop it on a **shared hosting plan**, point a cPanel cron at one URL, and you're live in minutes. Prefer real-time? Flip on **webhooks**. Stuck without inbound HTTPS? **Poll the ClickUp API directly** — same app, three paths in.

Under the hood it's **Laravel 12** with a small, test-covered codebase you can actually maintain.

---

## ✨ Features

| | |
|---|---|
| 🏠 **Built for shared hosting** | Runs on ordinary cPanel plans — no VPS, no SSH, no `php artisan` on the server. Upload, configure `.env`, set a cron URL, done. |
| 🔀 **Three ways to sync** | **Webhooks** for real-time · **Cron URL** for shared hosts · **API polling** (`artisan`) for VPS — pick what your infrastructure allows. |
| ⚡ **Real-time webhooks** | Listens for ClickUp `taskStatusUpdated` events with signature verification (`X-Signature`). |
| 🔄 **API polling (no webhook)** | Polls ClickUp directly when webhooks aren't an option — smart lookback window, incremental checks, duplicate-safe. |
| ⏰ **Cron-friendly endpoint** | Token-protected `GET /cron/clickup-poll` — perfect for cPanel **Fetch a URL** every minute. |
| 💬 **Custom message templates** | Configure the Telegram copy with `{name}` and `{task}` placeholders. |
| 🌍 **Localized display names** | Map ClickUp emails to friendly names (e.g. Persian) via JSON in `.env`. |
| ✅ **Flexible "done" detection** | Recognizes closed statuses *and* custom names like `complete`, `done`, `تکمیل`. |
| 🎯 **Scoped monitoring** | Optionally limit to a specific Space, Folder, or List. |
| 📎 **Rich media support** | Sends task attachments to Telegram — photos, videos, GIFs, and albums. |
| 🛡️ **Idempotent notifications** | Cache-based deduplication so the same completion never spams the group. |
| 🧪 **Tested** | PHPUnit coverage for webhooks, polling, Telegram formatting, and media handling. |

---

## 🚀 Quick Start

### Requirements

- PHP **8.2+**
- [Composer](https://getcomposer.org/)
- ClickUp Personal API Token + Team (Workspace) ID
- Telegram Bot Token + Group `chat_id`

### Install locally

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Fill in `.env` — see [Configuration](#%EF%B8%8F-configuration) below.

### Run tests

```bash
php artisan test
```

---

## 🔧 Choose your sync mode

### Option A — API polling via cron *(recommended for shared hosting)*

**1.** Set a secret token in `.env`:

```env
CLICKUP_CRON_TOKEN=your-long-random-secret-here
```

**2.** In cPanel → **Cron Jobs** → **Fetch a URL** (every minute):

```
https://yourdomain.com/clickup_notif_on_telegram/cron/clickup-poll?token=your-long-random-secret-here
```

**3.** Open that URL in a browser — you should see:

```json
{"status":"ok","notified":0,"checked_at":"..."}
```

No SSH. No long-running processes. Just HTTP.

---

### Option B — API polling via Artisan *(VPS / local)*

```bash
php artisan clickup:poll --loop --interval=10
```

Optional `.env` tuning:

```env
CLICKUP_POLL_INTERVAL=10
CLICKUP_POLL_LOOKBACK_MINUTES=30
```

On first run, scans the last 30 minutes. After that, only fetches newly updated tasks.

---

### Option C — Webhooks *(real-time)*

**1.** Set your public endpoint:

```env
CLICKUP_WEBHOOK_ENDPOINT=https://yourdomain.com/api/webhooks/clickup
```

**2.** Register the webhook:

```bash
php artisan clickup:register-webhook
```

**3.** Save the printed `CLICKUP_WEBHOOK_SECRET` to `.env`.

Webhook payloads are verified via `X-Signature`. Notifications are queued and deduplicated.

---

## 🏠 Deployment (shared hosting)

1. Upload project files (`app/`, `bootstrap/`, `config/`, `public/`, etc.).
2. Point the document root at the project folder — the root `.htaccess` routes requests to `public/` automatically (no `/public/` in URLs).
3. Configure `.env` on the server. `APP_URL` must match your subdirectory exactly:

```env
APP_URL=https://yourdomain.com/clickup_notif_on_telegram
```

4. Add the [cron URL](#option-a--api-polling-via-cron-recommended-for-shared-hosting) in cPanel.

> **Why it works on cheap hosting:** SQLite database, file/cache drivers, no Redis/queue worker required for polling mode, and a single HTTP endpoint instead of a daemon.

---

## ⚙️ Configuration

| Variable | Description |
|---|---|
| `CLICKUP_API_TOKEN` | Personal API token from ClickUp Settings → Apps |
| `CLICKUP_TEAM_ID` | Workspace (Team) ID |
| `CLICKUP_DONE_STATUSES` | Comma-separated status names treated as "done" (default: `complete,done,تکمیل`) |
| `CLICKUP_USER_NAMES` | JSON map of email → display name |
| `CLICKUP_SPACE_ID` / `FOLDER_ID` / `LIST_ID` | Optional scope filters |
| `CLICKUP_CRON_TOKEN` | Secret for the cron endpoint |
| `CLICKUP_WEBHOOK_SECRET` | Webhook signature secret (webhook mode) |
| `CLICKUP_WEBHOOK_ENDPOINT` | Public webhook URL (webhook mode) |
| `TELEGRAM_BOT_TOKEN` | From [@BotFather](https://t.me/BotFather) |
| `TELEGRAM_CHAT_ID` | Group chat ID (usually starts with `-100`) |
| `TELEGRAM_MESSAGE_TEMPLATE` | Default: `{name} تسک "{task}" رو انجام داد ✅` |

### Telegram setup

1. Create a bot via [@BotFather](https://t.me/BotFather) → set `TELEGRAM_BOT_TOKEN`.
2. Add the bot to your group with permission to send messages.
3. Set `TELEGRAM_CHAT_ID`.

### Display name mapping

```env
CLICKUP_USER_NAMES='{"user@example.com":"Aref","ali@example.com":"Ali"}'
```

> If a name contains spaces, wrap the entire JSON value in **single quotes** — otherwise Laravel may fail parsing `.env`.

Unmapped emails fall back to the ClickUp username.

---

## 📡 API Reference

### `POST /api/webhooks/clickup`

Receives ClickUp `taskStatusUpdated` events.

- Verifies `X-Signature` against `CLICKUP_WEBHOOK_SECRET`
- Ignores events unless status is `closed` or listed in `CLICKUP_DONE_STATUSES`
- Returns `{"status":"queued"}` or `{"status":"ignored"}`

### `GET /cron/clickup-poll?token=...`

Token-protected polling endpoint for cron jobs.

- Returns `{"status":"ok","notified":N,"checked_at":"..."}` on success
- Returns `403` for invalid token

---

## 🏗️ Architecture

```
ClickUp                          This app                         Telegram
  │                                 │                                │
  ├── webhook ──► POST /api/webhooks/clickup ──► Queue ──► sendMessage │
  │                                 │                                │
  └── REST API ◄── poll() ◄── GET /cron/clickup-poll (cron) ──────────►│
                    ▲                                                │
                    └── artisan clickup:poll --loop (VPS) ───────────►│
```

**Stack:** Laravel 12 · PHP 8.2+ · SQLite (default) · ClickUp API v2 · Telegram Bot API

---

## 📚 Related docs

- [ClickUp Webhooks](https://developer.clickup.com/docs/webhooks)
- [Webhook Signature](https://developer.clickup.com/docs/webhooksignature)
- [Task Webhook Payloads](https://developer.clickup.com/docs/webhooktaskpayloads)

---

## 📄 License

MIT © [BytelabsTeam](https://github.com/BytelabsTeam)

---

<div align="center">

**Ship it. Celebrate it. Repeat.** 🚀

[⭐ Star this repo](https://github.com/BytelabsTeam/clickupNotifierOnTelegram) if it saved your team a Slack thread.

</div>

---

---

# 🇮🇷 فارسی

## اعلان Done تسک‌های ClickUp در تلگرام

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
