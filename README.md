# AI Media

A Laravel + React + Inertia project frame for AI Media, Version 1.

The application includes a compact responsive email/password login, Myanmar/English/Thai UI, password recovery, sign-out and a private workspace frame. The three-step media workflow provides resumable uploads, validated setup, queued processing, script review, MP4/SRT output and project history. Plans and payments remain Phase 2.

## Requirements

- PHP 8.3.16 with Composer 2
- Node.js 22.16.0 with npm
- Local MySQL with the ai_media database already created
- SQLite support for isolated automated tests

Dependencies are locked in composer.lock and package-lock.json for this environment.

## Start locally

From the project root:

    composer run dev

Open http://127.0.0.1:8002. The command starts Laravel, Vite and the media queue listener together.

Alternatively, use three terminals:

    php artisan serve --host=127.0.0.1 --port=8002
    npm.cmd run dev
    php artisan media:work

On Windows PowerShell, npm.cmd avoids the script execution policy applied to npm.ps1.

## Fresh checkout

    composer install
    npm.cmd ci
    Copy-Item .env.example .env
    php artisan key:generate
    php artisan migrate
    composer run dev

## Checks

    npm.cmd run build
    npm.cmd run types:check
    php artisan test --compact
    composer check-platform-reqs

## Structure

- routes/web.php: protected workspace, project workflow, operations and settings frame routes
- app/Http/Middleware/HandleInertiaRequests.php: shared Inertia props
- resources/views/app.blade.php: the root HTML document
- resources/js/app.tsx: the React/Inertia entry point
- resources/js/layouts/app-layout.tsx: sidebar, header and page layout
- resources/js/pages/workspace.tsx: the initial workspace page
- resources/js/components/: reusable interface components
- resources/css/theme.css: approved Dark/Light semantic tokens
- resources/css/frame.css: responsive studio shell
- AI_MEDIA_THEME_COLORS.md: approved reference palette and future-screen rules

The application database is local MySQL: ai_media at 127.0.0.1:3306. Automated tests use an isolated in-memory SQLite database. No AI-provider API key is required for login.

## Private owner account

Create the first owner from the project terminal:

    php artisan app:create-owner

The command asks for an email and a hidden password, with confirmation. It does not seed a shared/default password and refuses to create another owner when an account already exists.

Open http://127.0.0.1:8002/login. Use the email and password you selected. Google sign-in is deferred.

Password recovery uses Laravel reset tokens. Configure a real SMTP/transactional mail transport to deliver recovery email; local MAIL_MAILER=log writes email to the local Laravel log instead of delivering it. A recovery response does not reveal whether the email exists. Never share reset tokens or log files publicly.

For deployment use APP_ENV=production, APP_DEBUG=false, a correct HTTPS APP_URL, and SESSION_SECURE_COOKIE=true. Production responses use CSP and HTTPS HSTS; authentication/session controls remain active locally.

## Local admin seeder

AdminSeeder reads ADMIN_SEED_ENABLED, ADMIN_SEED_NAME, ADMIN_SEED_EMAIL and ADMIN_SEED_PASSWORD from the local .env through config/admin.php. No admin password is hardcoded in the seeder or .env.example.

    php artisan migrate
    php artisan db:seed --class=AdminSeeder

The development seeder runs only in local/testing environments, marks the account as admin, and preserves an existing password when rerun. The default DatabaseSeeder also calls it when ADMIN_SEED_ENABLED is true. Keep .env private. Automated tests explicitly disable environment-provided admin seeding unless a test configures it.

## Phase 1 application frame

The workspace has a 14-item sidebar, five contextual New Project workflow tabs, a collapsible desktop rail and a mobile dialog drawer. Upload, Setup, Analyze/Review/Result and the project library are connected to owned database records. Other operations/settings destinations remain navigation frames.

The confirmed sidebar/header structure follows the local POS ShopLayout: a 228 px sidebar (64 px when collapsed), a 56 px desktop header and always-visible icon submenus. The locked Iris/Mint palette in resources/css/theme.css drives both modes: deep navy/lavender in Dark, white/light gray/iris in Light. The sidebar changes theme along with the header and content. The selected theme and sidebar collapse preference persist in this browser. UI language is Myanmar, English or Thai and follows the signed-in profile. Switching language preserves the current screen.

Use Ctrl/Cmd+K or the search button to find a workspace page. The account dropdown offers Preferences and Sign out. Operations and system settings require an administrator on the server; Preferences is available to signed-in users. Notifications currently show an empty state without a fabricated unread badge.

## Media workflow and private R2 storage

- Set MEDIA_DISK=r2 with R2_ENDPOINT, R2_BUCKET, R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY in the private .env. Use the dedicated R2_BUCKET=ai-media bucket and explicitly set R2_PREFIX= (empty) so object keys start with media/. R2_ENDPOINT is the account S3 endpoint without /ai-media. Objects stay private; a public development URL is not required. Existing projects retain their original disk. When changing buckets or prefixes, copy and verify existing relative object paths before switching configuration. Never commit .env.
- Run php artisan migrate before starting the queue. php artisan media:storage-check --write checks a tiny new private object, SHA-256 readback and cleanup without printing credentials.
- Uploads use acknowledged 4 MiB chunks, a client idempotency key and resumable server offsets. Completed files are inspected with FFprobe/FFmpeg in the media-inspect worker before being archived to R2. Source and result routes require project ownership and support normal private video playback.
- The current configured defaults are 2 GiB per video, 10 minutes per video and a 4 GiB application storage budget. MEDIA_MAX_UPLOAD_BYTES, MEDIA_MAX_DURATION_SECONDS and MEDIA_STORAGE_LIMIT_BYTES configure these limits. Reservations count incomplete uploads and every retained rendered/voice artifact, including older runs. The application limit does not guarantee that the shared Cloudflare account stays within its free allowance.
- FFmpeg and FFprobe are configured by FFMPEG_PATH and FFPROBE_PATH, defaulting to storage/app/bin/ffmpeg.exe and ffprobe.exe on Windows. Set MEDIA font_directory in config/media.php for Padauk-Regular.ttf (default storage/app/bin/fonts). Runtime binaries and their licenses are local ignored assets; provision them separately on another machine.
- Add a real GEMINI_API_KEY securely to .env to enable live analysis. GEMINI_MODEL, GEMINI_TTS_MODEL and GEMINI_API_REVISION configure the provider. Credentials never reach Inertia/browser props. A missing key is shown honestly and prevents a fake processing run. Restart php artisan media:work after .env changes: its queue listener reloads application code per job, but child processes inherit the supervisor environment. This command reports an independent heartbeat while rendering. Job Monitor and Worker Health use durable task events and these real heartbeats; stale reports are not shown as Online. Existing legacy runs are indexed by php artisan media:operations-sync without inventing historical timing or attempts.
- Creator tools support MP3/WAV narration-only or mixed audio, independently enabled burned subtitles, up to 12 owned presets, and queued voice/video/timing previews. Preview requests use snapshots of the current script/settings, reuse narration caches, and do not replace published output. A concise-line suggestion changes nothing until the operator applies and saves it. The latest preview is restored on return and marked outdated when settings/script differ. Downloaded SRT follows the rendered audio timeline. Preview progress appears in Job Monitor and worker heartbeats.
- Review Before Render pauses at the saved Burmese script. Editing preserves source timestamps. Re-render retains the previous successful result until a new file is verified, and shared per-project TTS hashes avoid voicing unchanged text again. Jobs checkpoint the transcript, translation batches and voices; database queue reservations can resume after a worker restart. HTTP 429 respects the provider retry delay.
- Heavy FFmpeg/provider calls run in queued jobs. Local files are a processing cache and can be restored from private R2. This version keeps the local cache; it does not yet perform scheduled cache eviction or automatic retention expiry. Explicit confirmed deletion in My Projects removes that project's R2 objects and local cache and releases its budget.

Run php artisan media:provider-check --speech to verify a small real translation and Burmese voice sample with the configured account. This command makes live provider requests, validates the audio with FFprobe, prints no credentials and removes its temporary sample.

### Reference engine boundary

The supplied developer brief describes a working Colab Step 3 Python engine, but that source notebook is not available in the accessible reference files. Configure the Gemini key separately in the private server environment. The local recap_mm engine is a placeholder. This implementation therefore supplies a PHP queue/Gemini/FFmpeg pipeline; it is not a verified port of the original notebook. Whisper GPU transcription, automatic subtitle-band detection, pitch-based narrator selection and Edge TTS fallback are not claimed as implemented. Background preservation now uses the verified local audio-separator 0.48.0 runtime and UVR-MDX-NET-Inst_HQ_3.onnx model under storage/app/bin/separator. MEDIA_SEPARATION_PYTHON and MEDIA_SEPARATION_MODELS can configure their locations; engine-ready.json is written only after a real separation check. A trusted MEDIA_SEPARATION_COMMAND JSON argument array with {input}/{output} placeholders remains supported. Source instrumental audio is cached privately per project; narration ducking and independent background volume are available. With Keep background disabled, dubbing replaces the original audio. Separation may leave audible artifacts and CPU processing can be slow. Manual subtitle-band cover, aspect/fill handling, real subtitle burning, real speech retiming and output verification are implemented.

MediaProcessingTest exercises actual FFmpeg files, R2 stream restoration, script review, SRT-only, dubbing timing, cover/fill variants, cancellation, checkpoint resume and unchanged-line voice reuse. Provider HTTP and R2 disks are faked only inside isolated PHPUnit tests. A successful automated suite does not establish live Gemini authorization or notebook-output equivalence.

## Local video and YouTube sources

New Project supports Local Video and a public YouTube/Shorts URL. URL imports use a checksum-verified yt-dlp runtime (YTDLP_PATH, default storage/app/bin/yt-dlp.exe on Windows), an available Node.js runtime (optional MEDIA_NODE_BINARY) and the existing FFmpeg tools. The downloader ignores user config/plugins and does not read browser cookies. URL validation accepts only supported YouTube video URLs and normalizes them to one canonical video, removing playlist/tracking parameters. Private, live, sign-in restricted, region-blocked or unavailable videos can fail normally; such restrictions are not bypassed.

Imports run on the media-inspect queue with actual stage/byte updates, cancellation and retry. Limits are checked before and after download; playable H.264/AAC media is prepared, then the existing inspector archives it privately to R2. A pending import conservatively reserves the per-video size limit against the app budget until its actual size is known. Failed/cancelled imports release unused reservation and keep downloaded parts for retry.

Clear resets the current form, cancels its local browser upload request and clears its recovery pointer. Existing projects, files and background work remain in history, including failed/completed runs. Delete Project in history remains the explicit confirmed action for removing stored files.
