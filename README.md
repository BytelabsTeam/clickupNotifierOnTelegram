# ربات مدیر تسک تیم (Telegram + GitHub + LLM)

ربات تیم برای:
- اعلان کامیت‌های GitHub همراه با امتیاز LLM در گروه تیم
- مدیریت تسک‌های غیرکدی هر عضو از **پیوی ربات** با دکمه‌ها
- امتیازدهی تسک‌ها و کامیت‌ها با LLM (IranRouter)
- گزارش هفتگی خودکار، تاریخچه روزانه/هفتگی/ماهانه و نمودار امتیاز ماه شمسی

زمان و تقویم بر اساس **Asia/Tehran** (ماه شمسی، هفته شنبه تا جمعه) است.

## نقش‌ها

- **گروه تیم**: فقط اعلان می‌دهد (چک‌این صبحگاهی با دکمه «شروع کار»، کامیت‌ها با امتیاز، گزارش‌ها). دستور از گروه پذیرفته نمی‌شود.
- **پیوی ربات**: مدیریت تسک، منو، تاریخچه، امتیاز و دستورهای ادمین.
- **ادمین**: آرمین (`is_admin` در `team_members`).

## نصب

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=TeamMemberSeeder --force
```

مقادیر `.env` را پر کنید (توضیحات در `.env.example`). حداقل:
`TELEGRAM_BOT_TOKEN`، `TELEGRAM_CHAT_ID`، `TELEGRAM_WEBHOOK_SECRET`، `GITHUB_TOKEN`، `GITHUB_REPOS`، `IRANROUTER_API_KEY`، `CRON_TOKEN`، `APP_TIMEZONE=Asia/Tehran`.

## اعضا (team_members)

هویت هر عضو با **آیدی عددی تلگرام** مشخص می‌شود؛ یوزرنیم فقط برای نمایش و mention است.
تعریف اعضا در `database/seeders/TeamMemberSeeder.php` است و با `db:seed` اجرا می‌شود (idempotent).

کامیت GitHub به عضو وصل می‌شود اگر `github_login` (بدون حساسیت به حروف) با نویسنده‌ی کامیت یکی باشد. کامیت نویسنده‌ی ناشناس به هیچ‌کس نسبت داده نمی‌شود.

## ربات تلگرام

ثبت وبهوک (بعد از هر تغییر دستورها):

```bash
php artisan telegram:register-webhook
```

یا روی هاست اشتراکی: `https://DOMAIN/PATH/telegram/set-webhook?token=CRON_TOKEN`

وبهوک: `POST /api/webhooks/telegram` (امضای `X-Telegram-Bot-Api-Secret-Token` با `TELEGRAM_WEBHOOK_SECRET` بررسی می‌شود).

### دستورهای پیوی

| دستور | کار |
|---|---|
| `/menu` یا `/mytasks` | تسک‌های باز من با دکمه‌های شروع / انجام |
| `/newtask عنوان \| توضیح \| 2026-11-10` | ساخت تسک برای خودم |
| `/tasks`، `/today`، `/overdue`، `/me` | لیست تسک‌ها |
| `/status ID doing\|done\|todo` | تغییر وضعیت تسک خودم (done = امتیاز LLM) |
| `/history`، `/history week`، `/history month` | تاریخچه کامیت، تسک و امتیاز |
| `/myscore [week\|month]` | امتیاز خودم |
| `/chart` | نمودار امتیاز ماه شمسی |
| `/topday`، `/topweek`، `/topmonth`، `/highlight` | برترین‌ها |

ادمین: `/team` (همه‌ی تسک‌ها) و `/assign ID @username` (واگذاری).

## زمان‌بندی (cron بیرونی)

همه‌ی آدرس‌ها با `?token=CRON_TOKEN` فراخوانی می‌شوند.

| آدرس | زمان‌بندی پیشنهادی | کار |
|---|---|---|
| `/cron/morning-checkin` | `0 9 * * *` (تهران) | پیام صبح‌بخیر و دکمه شروع کار |
| `/cron/github-commits` | هر ۱ دقیقه | بررسی کامیت‌های جدید GitHub |
| `/cron/github-score-commits` | هر ۱۰ دقیقه | امتیازدهی کامیت‌ها و اعلان همراه امتیاز |
| `/cron/weekly-report` | هر ۱۰ دقیقه | گزارش هفتگی (جمعه از ۱۸:۰۰ تهران، یک‌بار در هفته) |
| `/cron/daily-highlight` | `0 21 * * *` (تهران) | خفن‌ترین کار روز |

## تست

```bash
php artisan test
```

## ساختار مهم کد

- `app/Services/TeamIdentityService.php`: منبع واحد هویت اعضا و ادمین
- `app/Services/TeamTaskService.php`، `TaskMenuService.php`: تسک‌ها و دکمه‌ها
- `app/Services/CommitScoreSyncService.php` + `NotifyScoredCommits.php`: امتیازدهی و اعلان کامیت
- `app/Services/WeeklyReportService.php`، `HistoryService.php`، `ScoreChartService.php`: گزارش، تاریخچه، نمودار
- `app/Services/TelegramBotHandler.php`: مسیریابی دستورها و دکمه‌ها (گروه/پیوی)
