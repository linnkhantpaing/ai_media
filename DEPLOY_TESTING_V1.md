# ZATLAN Version 1 — Testing Server Setup

Branch: **`version1`** · PHP 8.3 · MySQL 8 · Node ≥ 22.16

---

## 1. Server တွင် install လုပ်ရမည်

- PHP 8.3 + extensions: `bcmath curl exif fileinfo gd intl mbstring pdo_mysql zip` (`proc_open` ပိတ်မထားရ)
- Composer 2, Node.js ≥ 22.16, MySQL 8 (utf8mb4)
- Nginx / Apache + HTTPS (domain + SSL)
- ffmpeg + ffprobe (Linux)
- yt-dlp (YouTube import အတွက်)
- Supervisor
- Cron

Git ထဲ မပါသော ဖိုင် — server ပေါ် သီးခြား ကူးတင်ပါ:

- `storage/app/bin/fonts/` (Padauk စသော subtitle font များ)

---

## 2. Code တင်ခြင်း

```bash
git clone https://github.com/linnkhantpaing/ai_media.git
cd ai_media
git checkout version1

composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.example .env
php artisan key:generate        # ပထမဆုံးအကြိမ်သာ

chmod -R ug+rwx storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## 3. `.env`

```dotenv
APP_NAME=ZATLAN
APP_ENV=production
APP_DEBUG=false
APP_URL=https://testing.your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zatlan_testing
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MEDIA_DISK=r2
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_PREFIX=testing

FFMPEG_PATH=/usr/local/bin/ffmpeg
FFPROBE_PATH=/usr/local/bin/ffprobe
YTDLP_PATH=/usr/local/bin/yt-dlp
MEDIA_NODE_BINARY=/usr/bin/node

MEDIA_STUDIO_TESTING=false
```

> `APP_ENV` ကို `local` မထားပါနှင့် (plan limit များ ကျော်သွားမည်)။

---

## 4. Run ရမည့် command များ

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Admin account ဖန်တီးခြင်း:

```bash
php artisan app:create-owner admin@your-domain.com
```

---

## 5. Queue worker (Supervisor)

`/etc/supervisor/conf.d/zatlan-worker.conf`

```ini
[program:zatlan-worker]
command=php /var/www/ai_media/artisan media:work --name="Testing Worker 01"
directory=/var/www/ai_media
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/ai_media/storage/logs/media-worker.log
redirect_stderr=true
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start zatlan-worker
```

---

## 6. Scheduler (cron)

```cron
* * * * * php /var/www/ai_media/artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Admin Panel setting

Admin login: `https://testing.your-domain.com/admin/login`

1. General Settings — name, logo, timezone, support email
2. General Settings → SMTP — ဖွင့်ပြီး test mail ပို့ပါ
3. API Keys & Models — Gemini key, text model, TTS model → Test
4. Storage & Retention — R2 စစ်ပြီး Automatic cleanup ဖွင့်ပါ
5. Payment Configuration — account / QR / currency
6. System Project Defaults
7. Packages — Free Trial, Paid, Top-up
8. Reports → AI & Storage Costs → Prices & Rates — token စျေး၊ R2 စျေး၊ USD→MMK, THB→MMK

---

## 8. စစ်ဆေးရန်

```bash
php artisan about
php artisan media:provider-check --speech
php artisan media:storage-check --write
php artisan schedule:list
supervisorctl status zatlan-worker
```

Admin → Operations → **Scheduled Tasks** တွင် Scheduler (cron) နှင့် Media worker နှစ်ခုလုံး 🟢 ဖြစ်ရမည်။

---

## 9. Code update တင်တိုင်း

```bash
php artisan down
git pull origin version1
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
supervisorctl restart zatlan-worker
php artisan up
```

Log: `storage/logs/laravel.log`, `storage/logs/media-worker.log`
