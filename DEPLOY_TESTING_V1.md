# ZATLAN Version 1 — Testing Server တင်ရန် Checklist

> Branch: `zin` · Laravel 13 · React 19 / Inertia 3 · PHP 8.3 · MySQL
> ဤဖိုင်ကို အပေါ်ကနေ အောက်သို့ အစဉ်လိုက် လုပ်ပါ။ ☐ ကို လုပ်ပြီးတိုင်း ✅ ပြောင်းပါ။

---

## 0. မတင်ခင် (ကိုယ့်စက်မှာ)

- ☐ `git push` — branch `zin` ကို GitHub သို့ တင်ပါ (commit များ local မှာပဲ ရှိနေသေးသည်)
- ☐ Testing server က ဘယ် branch ကို ယူမလဲ ဆုံးဖြတ်ပါ (`zin` သို့မဟုတ် `develop` သို့ merge ပြီးမှ)
- ☐ Local မှာ test အားလုံး pass ဖြစ်ကြောင်း နောက်ဆုံးတစ်ကြိမ် စစ်ပါ
  ```bash
  php artisan test
  npm run build
  ```

---

## 1. Server မှာ ရှိရမည့် software

| လိုအပ်ချက် | Version / မှတ်ချက် |
|---|---|
| PHP | **8.3** + extensions: `bcmath curl exif fileinfo gd intl mbstring pdo_mysql zip` |
| PHP function | `proc_open` ပိတ်မထားရ (ffmpeg / worker run ရန်) |
| Composer | 2.x |
| Node.js | **≥ 22.16** (frontend build အတွက်သာ) |
| MySQL | 8.x (utf8mb4) |
| Web server | Nginx / Apache + **HTTPS** (domain + SSL certificate) |
| **ffmpeg + ffprobe** | Linux build — video render, preview, watermark အတွက် **မဖြစ်မနေ** |
| **yt-dlp** | YouTube import အတွက် (YouTube သုံးမှသာ) |
| Node / Deno | yt-dlp ၏ YouTube JS challenge အတွက် (`MEDIA_NODE_BINARY`) |
| Python audio separator | "Keep Music & Effects" feature သုံးမှသာ (optional) |
| Process manager | **Supervisor** သို့မဟုတ် systemd (queue worker အတွက်) |
| Cron | Laravel scheduler အတွက် |
| Disk | Video ယာယီဖိုင်များအတွက် နေရာလွတ် အနည်းဆုံး **20–50 GB** (R2 ပေါ်တင်ပြီးမှ ပြန်ဖျက်သည်) |

### Git ထဲ မပါသော ဖိုင်များ — Server ပေါ်သို့ သီးခြား ကူးတင်ရမည်

- ☐ **Subtitle font များ**: `storage/app/bin/fonts/` (ဥပမာ `Padauk-Regular.ttf`, `OFL.txt`) — မရှိလျှင် မြန်မာ စာတန်း ပျက်ပါမည်
- ☐ **ffmpeg / ffprobe** binary (Linux) — `storage/app/bin/` ထဲထည့်ပါ သို့မဟုတ် `FFMPEG_PATH` / `FFPROBE_PATH` ဖြင့် path ပေးပါ
- ☐ **yt-dlp** binary (Linux) — `YTDLP_PATH`
- ☐ (optional) Audio separator Python runtime + models — `MEDIA_SEPARATION_PYTHON`, `MEDIA_SEPARATION_MODELS`

> Local မှာ ရှိသော `.exe` ဖိုင်များ (Windows) သည် Linux server တွင် အလုပ်မလုပ်ပါ။ Linux version ကို ထည့်ပါ။

---

## 2. Code တင်ခြင်း (ပထမဆုံးအကြိမ်)

```bash
git clone https://github.com/linnkhantpaing/ai_media.git
cd ai_media
git checkout zin            # သို့မဟုတ် ဆုံးဖြတ်ထားသော branch

composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.example .env
php artisan key:generate    # ⚠️ ပထမဆုံးအကြိမ်သာ — နောက်ထပ် run လျှင် login/session/encrypted data ပျက်မည်
```

Folder permission:
```bash
chmod -R ug+rwx storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache   # web server user အမည်အတိုင်း
```

---

## 3. `.env` သတ်မှတ်ရမည့် အချက်များ

```dotenv
APP_NAME=ZATLAN
APP_ENV=production          # ⚠️ "local" မထားရ — local ဆိုလျှင် Testing mode ဖြင့် plan limit များ ကျော်သွားမည်
APP_DEBUG=false
APP_URL=https://testing.your-domain.com   # ⚠️ Mail ထဲ link များ ဤလိပ်စာကို သုံးသည်

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zatlan_testing
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Video / ဖိုင် သိမ်းရန် (Cloudflare R2)
MEDIA_DISK=r2
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
R2_BUCKET=...
R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
R2_PREFIX=testing           # production နှင့် ဖိုင်မရောစေရန် prefix ခွဲထားပါ

# Media tools (Linux path)
FFMPEG_PATH=/usr/local/bin/ffmpeg
FFPROBE_PATH=/usr/local/bin/ffprobe
YTDLP_PATH=/usr/local/bin/yt-dlp
MEDIA_NODE_BINARY=/usr/bin/node

# Plan limit များကို တကယ် စစ်ရန် (default false ဖြစ်သော်လည်း ရှင်းရှင်းလင်းလင်း ထားပါ)
MEDIA_STUDIO_TESTING=false

# Gemini — Admin → API Keys & Models မှာလည်း ထည့်နိုင်သည်
GEMINI_API_KEY=
```

မှတ်ချက်များ:
- **Mail (SMTP)** ကို `.env` မှာ မဟုတ်ဘဲ **Admin → General Settings → SMTP** မှာ သတ်မှတ်ပါ (system က အဲဒီ setting ကိုသာ သုံးသည်)
- `APP_ENV=production` ဆိုလျှင် link များကို **https** ဖြင့် အလိုလို ထုတ်သည် — SSL မရှိလျှင် အလုပ်မလုပ်ပါ
- `.env` ကို git ထဲ **ဘယ်တော့မှ** commit မလုပ်ပါနှင့်

ပြီးလျှင်:
```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## 4. နောက်ခံ process များ (မဖြစ်မနေ)

### 4.1 Media queue worker (video process လုပ်သည့် worker)

Supervisor config ဥပမာ — `/etc/supervisor/conf.d/zatlan-worker.conf`
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
supervisorctl reread && supervisorctl update && supervisorctl start zatlan-worker
```

### 4.2 Scheduler (cron)

```cron
* * * * * cd /var/www/ai_media && php artisan schedule:run >> /dev/null 2>&1
```
Scheduler က လုပ်ပေးသော အလုပ်များ:

| Task | ကြိမ်နှုန်း | မလုပ်လျှင် |
|---|---|---|
| `media:cleanup --scheduled` | နာရီတိုင်း | သက်တမ်းကုန်ဖိုင်များ R2 မှ မဖျက်ဘဲ ကုန်ကျစရိတ် တက်မည် |
| `usage:reconcile` | ၅ မိနစ်တိုင်း | ပြတ်တောက်သွားသော job များ၏ မိနစ် မပြန်/မဖြတ် |
| `notifications:sync` | မိနစ်တိုင်း | Admin notification မတက် |
| AI usage prune | နေ့စဉ် 03:15 | AI log ၉၀ ရက်ကျော် ပေါင်းခြင်း မလုပ် |

စစ်ရန်: `php artisan schedule:list`

---

## 5. ပထမဆုံး Admin account ဖန်တီးခြင်း

```bash
php artisan app:create-owner admin@your-domain.com
```
(အမည် + password ကို မေးမည် — password: အနည်းဆုံး ၁၂ လုံး၊ စာလုံးကြီး/သေး၊ ဂဏန်း၊ သင်္ကေတ)

Admin login: `https://testing.your-domain.com/admin/login`

---

## 6. Admin Panel မှာ setting ထည့်ရန် (အစဉ်အတိုင်း)

1. ☐ **General Settings** — Platform name, logo, timezone, support email / URL
2. ☐ **General Settings → SMTP** — ဖွင့်ပြီး test mail ပို့ကြည့်ပါ (မဖွင့်လျှင် Forgot password / Welcome mail မပို့)
3. ☐ **API Keys & Models** — Gemini key, text model, speech (TTS) model ထည့်ပြီး Test နှိပ်ပါ
4. ☐ **Storage & Retention** — R2 profile စစ်ပြီး **Automatic cleanup ကို ဖွင့်ပါ** (retention ရက်)
5. ☐ **Payment Configuration** — ငွေလက်ခံ account / QR / currency (MMK, USD, THB) ဖွင့်ပါ
6. ☐ **System Project Defaults** — Studio default setting များ
7. ☐ **Packages**
   - ☐ Free Trial package (account ဖွင့်ရာတွင် လိုအပ်သည်) — Movie ကို **Locked**, Translate/Subtitles မိနစ် + Max source length, **Watermark ဖွင့်**
   - ☐ Paid package(s) — workflow တစ်ခုချင်းစီ မိနစ်
   - ☐ Top-up package(s) — workflow + မိနစ် + စျေး
8. ☐ **Reports → AI & Storage Costs → Prices & Rates** — model တစ်ခုချင်း USD / 1M token စျေး၊ R2 စျေး၊ **USD→MMK**, **THB→MMK** နှုန်း

---

## 7. Server ပေါ်မှာ စစ်ဆေးရန် command များ

```bash
php artisan about                          # environment, cache, driver များ
php artisan media:provider-check --speech  # Gemini text + TTS အလုပ်လုပ်မလုပ်
php artisan media:storage-check --write    # R2 ရေး/ဖတ်/ဖျက် ရမရ
php artisan schedule:list                  # scheduler task များ
supervisorctl status zatlan-worker         # worker run နေမနေ
```

---

## 8. Smoke test (browser ဖြင့် လက်တွေ့ စမ်းရန်)

**Admin**
- ☐ User Accounts → customer ဖန်တီး (Free Trial + Send login information)
- ☐ Welcome mail ရောက်ပြီး "စကားဝှက် သတ်မှတ်ရန်" link က **testing domain** ကို ဖွင့်သည်
- ☐ Customer ကို Delete permanently လုပ်ပြီး email တူ ပြန်ဖွင့်လို့ရသည်

**Customer**
- ☐ Login / Forgot password / Reset password
- ☐ Profile ပုံ upload (R2 သို့ တကယ်တင်ခြင်း — local မှာ မစမ်းရသေး)
- ☐ Studio: Movie card က **PAID** ပြပြီး နှိပ်လျှင် upgrade dialog ပေါ်
- ☐ Local video upload → Translate → Review → Export → MP4 / SRT download
- ☐ YouTube link import → Subtitles → Export
- ☐ Free plan export တွင် **ZATLAN watermark** ပါ
- ☐ Video အရှည် limit ကျော်ပါက ငြင်းသည် / မိနစ်ကုန်လျှင် "Buy minutes" ပြသည်
- ☐ Billing: Top-up ဝယ် → Admin approve → My Plan တွင် မိနစ် တိုးသည်
- ☐ Cancel → Retry အလုပ်လုပ်သည်
- ☐ Support chat (customer ↔ admin), Notifications bell
- ☐ Sidebar storage bar / plan / ကျန်ရက်
- ☐ Admin → AI & Storage Costs တွင် token နှင့် ကုန်ကျငွေ (MMK) ပေါ်သည်

**Mobile** — Phone တစ်လုံးဖြင့် အထက်ပါ အဓိက flow များ တစ်ခေါက် စမ်းပါ

---

## 9. Code update တင်တိုင်း (ဒုတိယအကြိမ်မှစ)

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
supervisorctl restart zatlan-worker      # ⚠️ worker က code အဟောင်းကို ဆက်သုံးနေမည် — restart မမေ့ပါနှင့်
php artisan up
```

> Migration မတိုင်ခင် database backup ယူပါ: `mysqldump zatlan_testing > backup-$(date +%F).sql`
> Version 1 migration များအားလုံးသည် column/table **ထပ်ထည့်ခြင်းသာ** ဖြစ်ပြီး ရှိပြီးသား data မဖျက်ပါ။

---

## 10. Version 1 တွင် မပါသေးသော / ဆုံးဖြတ်ရန် ကျန်သော အချက်များ

| အချက် | အခြေအနေ |
|---|---|
| Analysis ပြီးချိန်တွင် မိနစ်ဖြတ်ခြင်း | **ပြီး** — Script ready ဖြစ်သည်နှင့် မိနစ်ဖြတ်သည်။ ထို run ကို export လုပ်လျှင် ထပ်မဖြတ်။ Fail / Cancel ဖြစ်လျှင် မဖြတ် |
| Voice / Video preview တစ်နေ့ limit | **ပြီး** — Admin → Packages → Advanced → "Previews per day" (ဗလာ = အကန့်အသတ်မရှိ)။ ထုတ်ပြီးသား preview ပြန်ဖွင့်ခြင်း၊ fail ဖြစ်သော preview ကို မရေတွက် |
| Admin က top-up payment ကိုယ်တိုင် မှတ်တမ်းတင်ခြင်း | မလုပ်သေး (ငွေသား လက်မခံသေး — customer ကိုယ်တိုင် ဝယ်နိုင်) |
| Free plan 720p ကန့်သတ်ခြင်း | **ပြီး** — Admin → Packages → Advanced → "Max export resolution" (720p / 1080p / ကန့်သတ်မထား)။ Run reserve လုပ်ချိန်က plan အတိုင်း |
| Mail logo | **ပြီး** — Admin → General Settings ရှိ Main logo အတိုင်း ပြသည် (ပြောင်းလျှင် mail တွင်လည်း ပြောင်း)။ Logo မရှိလျှင် ZATLAN logo |
| AI ကုန်ကျငွေ | Token × သင်ထည့်ထားသော စျေးဖြင့် ခန့်မှန်းချက် — Google invoice နှင့် အနည်းငယ် ကွာနိုင် |

---

## 11. ပြဿနာ ဖြစ်လျှင် ကြည့်ရန်

- Laravel log: `storage/logs/laravel.log` (ffmpeg error အစစ်များကို "Media tool failed." ဖြင့် မှတ်ထားသည်)
- Worker log: `storage/logs/media-worker.log`
- Admin → **Worker Health** / **Job Monitor**
- Mail မရောက်: Admin → General Settings → SMTP ဖွင့်ထားမထား၊ `APP_URL` မှန်မမှန်
- Plan limit မအလုပ်လုပ်: `APP_ENV` သည် `local` ဖြစ်နေသလား၊ `MEDIA_STUDIO_TESTING=true` ဖြစ်နေသလား
