# ZATLAN / ai_media — Phase 2 System Handover

Updated: 2026-11-13, Asia/Bangkok.
Purpose: persistent context for continuing this project in a new chat.
This document records the user's decisions and a code-reviewed implementation checkpoint. Current source and the latest user request take precedence if they differ from this checkpoint.

## 1. Read this first

ဒီ project က ZATLAN video recap / မြန်မာစာတန်းထိုး / မြန်မာအသံပြောင်း system ဖြစ်သည်။
Phase 1 က video ထုတ်နိုင်ကြောင်း စမ်းသပ်ရန် အရင်ရေးထားခြင်းဖြစ်သည်။
လက်ရှိ Phase 2 မှာ Admin Side ကို အရင်တည်ဆောက်နေသည်။
User account ကို Admin ကသာဖန်တီးပေးမည်။ User ကိုယ်တိုင် register မလုပ်ရ။
Account အသစ်သည် Free Trial နှင့် စမည်။ Paid package ကို Payments မှ Approve လုပ်ပြီးမှ ပေးမည်။
User account ဖန်တီးချိန် Send Login Information ကို ON ထားမှ သိမ်းထားသော SMTP ဖြင့် လုံခြုံသော password setup link ပို့မည်။ Account Details မှ ပြန်ပို့နိုင်သည်။
လက်ရှိ နောက်ဆုံးပြီးသော Admin form သည် Support Inbox ဖြစ်သည်။ Admin chat၊ private attachment၊ internal note၊ customer context နှင့် Payment Request Information ကို ချိတ်ထားပြီး Customer Side Support UI ကို နောက်အဆင့်မှ ဆက်လုပ်ရန်။
User နှင့် ဆက်သွယ်ရာတွင် မြန်မာဘာသာ၊ လိုရင်းတိုရှင်းကို ဦးစားပေးရန်။

- Actual project root: `C:\laragon\www\ai_media`. The IDE/workspace may instead start in sibling `crm` or `pos`. Do not edit the wrong project.
- Stack: Laravel + React + Inertia + TypeScript + Vite; MySQL database named `ai_media`.
- Branding: ZATLAN. Folder/package names can still say ai_media or AI Media.
- Do not copy secrets, passwords, .env contents, API tokens, R2 credentials or customer records into this document.
- No Owner role or configurable permission matrix was requested. There are Admin and customer accounts.
- Do not infer that a sidebar item is implemented: use the implementation table below and inspect its route/controller.
- Read `AGENTS.md`; read applicable `.ai/rules` if that directory exists. It was absent at this checkpoint.
- This handover is documentation, not authorization to deploy, send mail, reset accounts, change live financial records or run destructive migrations.

## 2. Core business flow

```text
Admin defines Free / paid packages and receiving payment methods
    ↓
Admin creates a customer account → Free Trial assigned by default
    ↓
Customer signs in → uploads/imports video → configures → analyzes → reviews → exports
    ↓
Usage checks package features, subscription dates, storage and available allowance
    ↓
If customer wants Pro / another paid package:
Admin records transfer → reviews evidence → approves payment
    ↓
Approval creates/replaces/schedules the subscription period atomically
    ↓
Customer access follows the effective subscription and latest published package
    ↓
Successful export charges usage; failed/cancelled work releases its reservation
```

Important separations:

| Concept | Responsibility |
| --- | --- |
| User account | Identity, password, login eligibility, suspension/archive |
| Package | Reusable feature/limit definition, price, currency, validity, published versions |
| Payment method | Where/how money is received, currency, receiving details, QR, instructions |
| Payment transaction | Evidence, review, approval, refund and historical monetary snapshots |
| Subscription | Which package/period grants customer access; current and scheduled periods |
| Usage Ledger | Reserved/charged processing, video counts, storage and remaining allowance |
| Job / worker | Background processing execution, progress, retries, health |

Ending access does not delete an account or its payment history. Recording a payment does not itself grant access. Package edits do not rewrite historical payments.

## 3. Screens and implementation checkpoint

Admin URLs use the `/admin` prefix. Shared settings pages are reused inside the Admin frame.

| Screen | URL | State |
| --- | --- | --- |
| Admin login | /admin/login | Implemented; separate admin guard |
| Admin dashboard | /admin/dashboard | Implemented real read-only overview: currency-specific collections/refunds, export/usage metrics, current system/attention, charts and drill-down links |
| User Accounts | /admin/users | Implemented, including Free default, management, details and last login |
| Packages | /admin/packages | Implemented; publication, versions, limits/features, lifecycle/history |
| Subscriptions | /admin/subscriptions | Implemented; one customer summary row with next-period detail |
| Payment Configuration | /admin/settings/payments | Implemented; MMK / THB / USD receiving methods |
| Payments & Refunds | /admin/payments | Implemented; manual transfer review, approval, corrections and refunds |
| Usage Ledger | /admin/usage-ledger | Implemented; customer summaries, balances, successful recent videos, details/CSV |
| Job Monitor | /admin/operations/jobs | Implemented across customers |
| Worker Health | /admin/operations/workers | Implemented, actual heartbeats, queues and instance history |
| API Keys & Models | /admin/settings/providers | Implemented; migrated from Phase 1 |
| System Project Defaults | /admin/settings/defaults | Implemented; migrated from Phase 1 |
| Storage & Retention | /admin/settings/storage | Implemented; migrated from Phase 1 |
| General Settings | /admin/settings/general | Implemented most recently |
| Support | /admin/support | Implemented Admin chat inbox, private files/notes, customer context, Payment requests and notification/audit links; customer UI pending |
| Revenue | /admin/reports/revenue | Implemented; approved collections, completed refunds, currency-separated charts and CSV |
| Usage & Processing reports | /admin/reports/usage-processing | Implemented; real usage, export outcomes/performance, current stock, customer details and CSV |
| Audit & Security | /admin/audit | Implemented; unified journal, security events, safe detail/CSV |
| Notifications | /admin/notifications | Implemented; real Admin feed, read/archive state and related-record details |

Customer-side existing routes:

- `/login`, `/` workspace, `/projects`, `/projects/new`.
- Project upload/setup/analyze pages and authenticated status/file/preview/script endpoints in `routes/web.php`.
- All Projects is the single library menu. Draft/awaiting review/processing/completed/needs attention are filters, not separate sidebar forms.
- `/settings/preferences` remains a frame/placeholder.
- Complete Phase 2 customer billing/payment-request/support/notifications UI has not been built.
- Do not treat the existing customer workspace heading as a finished customer dashboard.

## 4. Authentication and account management

- Guards: `web` for customers, `admin` for administration. Admin routes require `auth:admin`, session checks and `can:manage-system`.
- User login does not authorize Admin routes. Admin login routes/dashboard are separate.
- There is no public self-registration flow.
- Admin creates the account: name, email, strong password + confirmation, language, account status, optional note.
- New accounts must start with an active zero-price Free Trial package. Paid choices were removed from creation.
- Package duration/custom dates and allowance preview are part of the account editor. End-date semantics are exclusive: access ends at the start of the stored end date.
- Edit, password reset, suspend/reactivate, archive/restore and session revocation have explicit actions and audit information.
- Created date is shown beneath the email in the User column. The former Created column is Last Login; never-signed-in users must be distinguishable.
- Suspension/account status and subscription status are different concepts. Do not hide a customer from all financial history just because access ended.
- Account welcome/login email is now enabled when saved General Settings SMTP is enabled/configured. Account creation sends only when Admin selects Send Login Information; OFF sends nothing. Replaying the creation request never repeats delivery.
- Account Details has a confirmed Send Login Information action for delivery retries. POST /admin/users/{customer}/login-information is Admin-only, revision-checked, idempotent and limited to one new attempt per customer per minute. Delivery failures retain the account/Free Trial and record safe status in account history.
- Email contains the customer email, canonical login URL and a one-use password setup link using the configured broker expiry (normally 60 minutes), localized to my/en/th. Plaintext account/SMTP passwords and reset tokens must never enter API responses or audit metadata.
- New SMTP welcome delivery is synchronous in the authenticated HTTP flow, using the saved platform mailer; environment-only mail configuration does not enable this option.
- Normal management must not reseed or reset existing passwords. Do not put seed-account passwords into docs.

Primary source: `app/Services/CustomerAccounts.php`, `app/Http/Controllers/Admin/UserAccountController.php`, `app/Http/Requests/Admin/SaveCustomerRequest.php`, `resources/js/pages/admin/users.tsx`.

## 5. Packages and version policy — very important

Package definition includes:

- Name/code/description, trial or paid, price/currency, validity days, status.
- Optional video-count allowance; storage quota in GiB; processing allowance in minutes (stored/accounted in seconds).
- Maximum source-video duration, maximum upload size, retention/file-availability days, concurrent jobs and queued-job limit.
- Features: local upload, YouTube import, subtitles-only, full Burmese dub, MP4/SRT/audio export, script review/editing, voice/video preview, subtitle styling, cover original subtitles, aspect ratio/fill, keep background music/effects.
- Publication validation checks coherent limits, e.g. enough storage for at least one permitted upload and processing allowance sufficient for an allowed maximum-duration video.
- Upload size uses a dropdown of supported sizes; do not restore arbitrary free-text sizes.
- Lifecycle actions include activation/deactivation/archive/restore; changes/reasons/history are retained.
- Editing publishes a new version after review/reason. Draft edits are not automatically published entitlement changes.
- Hide unnecessary trailing decimal zeros in display: 10000.00 → 10,000.

Latest user decision overrides the earlier fixed-version design:

**Active and upcoming, unclosed customer assignments use the package's latest published version.**
Use `CustomerPackageAssignment::effectivePackage()` / `effectiveSnapshot()` for current entitlement checks and displays.

- An existing paid user also gets newly published Pro features/limits.
- Preserve the original purchased snapshot for expired/closed/replaced periods, payments, audit and existing usage records.
- Updating a package must not reset consumed allowance, extend dates or rewrite an old payment amount.
- Reducing a quota can leave already-used amounts above the new allowance; do not invent a fresh balance.
- Package storage quota is independent of the application R2 storage budget. The user explicitly rejected the former 4 GiB maximum for package definitions. Pro may have 5 GiB or more even while development uses a smaller application budget.
- Actual runtime upload/storage safety checks still apply; defining a package does not provision unlimited real storage.

Primary source: `app/Services/PackageCatalog.php`, `app/Models/PackageVersion.php`, `app/Models/CustomerPackageAssignment.php`, `tests/Feature/LatestPackageVersionTest.php`.

## 6. Receiving payment configuration and currencies

- Supported currencies are MMK, THB and USD. Keep amounts tied to their currency.
- Methods contain provider/type, currency, receiving account holder/identifier, optional QR, localized customer instructions, status/default.
- Only matching enabled currency/method combinations can be selected for a payment.
- Default receiving methods are scoped by currency. Do not combine cross-currency totals into a misleading number.
- No automatic foreign-exchange conversion or external payment gateway settlement is implied by these forms.
- Method revisions/snapshots preserve what a historical payment used.
- Customer Payment Preview is a compact detail panel: status icon/text inline next to the name, no boxed status; no redundant Preview button.
- Instructions and Change History are compact, borderless sections. Change History stays expanded.
- Manual-review guidance belongs in a compact appropriate position, not a large divider in the middle.

Source: `app/Services/PaymentConfiguration.php`, `app/Http/Controllers/Admin/PaymentSettingsController.php`, `resources/js/pages/admin/payment-settings.tsx`.

## 7. Payments, paid activation and refunds

Normal paid flow: **Record Payment → Review → Approve → subscription changes**.

- Select active customer, paid package, context-appropriate purpose, matching receiving method, received amount, transfer reference/date, receipt and optional sender/note.
- Money is parsed/stored as integer minor units. Display MMK/THB/USD explicitly.
- Purposes are context-driven, not an always-available set of three unrelated choices:
  - No active access: new or renewal depending on prior history.
  - Same current package: renewal.
  - Different current package: change now or renewal after the current period.
  - Existing scheduled period: resolve it before scheduling another one.
- Backend `recordingContext()`, `recordingPurposes()`, `plan()` are authoritative.
- Package Change replaces current access from approval time. Renewal starts after current access, or now when no current period remains.
- Save for Review never activates paid access.
- Pending/unapproved records can be corrected with a reason; correction is audited and returns the record for review.
- Uploading a replacement receipt makes it the current evidence, replacing the visible old receipt. Historical evidence remains audit data, not additional current attachments.
- Duplicate checks use current evidence/reference and exclude the same payment. An old superseded image must not keep causing a false duplicate.
- An already-approved duplicate, mismatched amount, stale customer/payment state or invalid period can block approval. Explain the actual issue; do not present a permanent fake loading state.
- Approval requires checklist/state validation and uses transactional locks, revisions and idempotent requests to prevent double grants.
- Typical statuses: awaiting_payment → pending_review / under_review / needs_information → approved, or rejected/cancelled/expired.
- Request Information on Admin payments creates/reuses an unresolved payment-linked Support conversation. Customer-facing chat/notification/evidence-response UI is still deferred; a reply never grants access or approves a payment.
- Refund records have their own requested/under_review/completed/rejected/cancelled lifecycle, amount limits and audit.
- Completing a refund records an actual manual action; it does not prove an external bank/API transferred money.
- Subscription access effect must be explicit (keep/end/cancel as supported), scoped to the linked eligible period. Do not assume every refund automatically deletes access.
- Approved financial history should not be casually edited/deleted.

Source: `app/Services/Payments.php`, `app/Models/PaymentTransaction.php`, `app/Models/PaymentReceipt.php`, `app/Models/PaymentRefund.php`, `resources/js/pages/admin/payments.tsx`, `resources/js/components/payment-transactions.tsx`.

## 8. Subscriptions and account access

- Main list shows one selected period per customer, not every monthly renewal as a separate customer row.
- Scheduled Next Period appears within that customer's period section. When its start time arrives it becomes the current selection; old periods remain in details/history.
- Active, Expiring Soon (within 7 days), Expired, Upcoming and ended/cancelled states have different meanings.
- Customer, package, dates, remaining days, allowances, latest effective package and history appear in the detail panel.
- Redundant Activity History action was removed: details already exposes that information.
- Renewal/Change/Extend buttons were hidden at the user's request; normal paid changes are handled in Payments.
- End Access Now is still a distinct deliberate action with reason/confirmation. It ends the selected access, not the user account or payment history.
- An ended but otherwise active customer must remain searchable when recording a new payment.
- Suspension/archive is an account-management action and may restrict eligibility separately.
- A scheduled period is separate from the current one; inspect the existing end/cancel behavior rather than assuming ending one automatically cancels all future periods.
- Period timestamps use UTC. General Settings display timezone must not mutate these timestamps.

**Legacy implementation caveat:** `SubscriptionActionRequest` and `Subscriptions::mutate()` still contain older renew/change/extend backend contracts, although the main UI hides those actions. Account-edit package/date correction code also predates the payment-only flow. Before adding new paid-access entry points or hardening a release, review these paths against the latest payment-only policy. Do not claim that hiding buttons removed every backend pathway, and do not restore the buttons without a new user request.

Source: `app/Services/Subscriptions.php`, `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Models/CustomerPackageAssignment.php`, `resources/js/pages/admin/subscriptions.tsx`.

## 9. Usage charging, storage and the Ledger

The system has three distinct processing amounts:

| Amount | Meaning |
| --- | --- |
| Used / Charged | Successful processing already consumed |
| Reserved | Allowance held by unfinished work |
| Remaining | Entitlement less used and reserved, as calculated by the service |

- Reserve allowance before work runs; transactionally check active account/subscription, source duration, features, available processing/video allowance and queue capacity.
- Processing uses verified source-video seconds, not an invented AI credit counter.
- A new export consumes source duration. The same project counts as one video per allowance period; another export can still consume processing time.
- Successful completion settles reserved amounts into charged amounts.
- Failed/cancelled work releases its reservation; retries must not double-charge an already-settled operation.
- After a successful export, Reserved = 0 is normal. Used increases; Remaining decreases.
- A package change preserves the relevant allowance anchor so previously consumed usage is not accidentally reset. A renewal is a new period with fresh allowance; unused allowance does not carry over.
- Upload checks and output-storage checks include the customer's actual allocation. Stored-file usage is a current inventory, not a monthly processing counter.
- Stored files do not vanish merely because a period renews or expires; deletion/retention has its own policy.
- The Admin Ledger defaults to a customer summary, not one row per internal event.
- Columns: Customer, Package/Period, Allowance (Total / Used), Reserved, Remaining, Recent Usage, Actions.
- Allowance header is plain centered text, without icons or special double borders.
- Recent Usage shows successful generated videos only, not failed/running attempts.
- One centered View Detail action is enough. No duplicate Usage History button; the detail view provides history.
- CSV, filters and detail records must use the same authoritative calculations.
- Historical usage snapshots remain historical even when effective package versions change.
- Admin adjustments, where supported, require reason/audit and must not silently rewrite original execution records.

Source: `app/Services/UsageLedger.php`, `app/Services/CustomerUsageSummary.php`, `app/Models/UsageRecord.php`, `app/Models/UsageEvent.php`, `resources/js/pages/admin/usage-ledger.tsx`.

## 10. Customer video creation and processing

Existing Phase 1 workflow remains the video-generation foundation for Phase 2.

1. Select local video or YouTube URL, provide/suggest project title.
2. Import/upload with real progress; inspect metadata, duration, dimensions, audio and file validity.
3. Keep files associated with the customer/project and configured private storage.
4. Configure processing mode, source language, output, review preference and video layout.
5. Advanced options cover voice/style/speed/volume, maximum video speed, background music/SFX separation/mixing, subtitle color/lift, covering original subtitles, aspect/fill, and available preview tools.
6. Analysis prepares source audio, transcribes, builds context and Burmese translations.
7. If review is enabled, pause for script review/editing before final export.
8. Generate voice/render/export as selected; publish a valid result before charging usage.
9. Show result/player and the available MP4, SRT and audio downloads.
10. All Projects allows resuming, opening results, reviewing scripts, retrying eligible failures and deleting owned projects.

Mode meanings:

- Subtitles Only: original audio retained; Burmese subtitle output as configured.
- Full Burmese Dub: Burmese narration replaces speech. Keeping music/effects requires the separation engine and the package feature; do not mix the full original speech back in as if it were an instrumental track.
- SRT is a timed subtitle text file, not an MP4. Saving a script is not the same as rendering/downloading a final video.
- Audio export and previews depend on actual mode/settings/features; do not display inaccessible features as working.

Implementation reality versus the friend's brief:

- The developer brief described a target Colab pipeline using faster-whisper, Python dubbing, Smart Sync, Gemini Vision and FFmpeg.
- Current web implementation is service-based Laravel processing; `MediaPipeline::analyze()` calls `GeminiClient::transcribe()` after FFmpeg audio extraction.
- Do **not** state that the original notebook engine/faster-whisper/GPU parity has been integrated just because it appears in the brief.
- Automatic burned-in subtitle detection was shown as not integrated; verify current readiness before promising Auto mode. Manual cover and actual renderer code are separate.
- There is an isolated Python audio-separator integration (`MediaBackground.php`, `separate_audio.py`, model/runtime paths under storage). Installation was previously authorized, but recheck files/readiness on the actual machine.
- Do not invent TTS providers or silently substitute a model that is unavailable. Use supported/discovered provider models and configured fallbacks.
- Queue state, cancellation, stage checkpoints, cached outputs and access/allowance checks are meaningful backend state, not fake progress animation.

Source: `MediaProjectController`, `MediaToolsController`, `app/Jobs`, and `app/Services/Media/`:
`MediaUploads`, `YouTubeImports`, `MediaInspector`, `MediaPipeline`, `GeminiClient`, `MediaRenderer`, `MediaVoices`, `MediaPreviews`, `MediaBackground`, `MediaRunActions`, `MediaStorage`.

## 11. Central settings and runtime behavior

### API Keys & Models

- User wanted saved form data to drive video generation, not repeated manual editing of GEMINI_API_KEY in .env.
- Use `ProviderSettings` for the current saved configuration, encrypted credentials, primary/fallback text models, speech model/voice defaults and processing policy.
- Existing config/environment values are bootstrap/fallback paths; do not bypass a saved revision by reading env directly in controllers/workers.
- New operations resolve/capture the appropriate configuration. In-flight work keeps its captured configuration for consistency.
- Model discovery/test actions must report actual results. Do not hardcode a screenshot model name as proof it exists.
- Respect provider retry delays/429s. Do not rotate keys to evade shared provider quota.
- Readiness is separate from configuration: a key being present does not prove it works, nor does a Ready separator prove a worker is running.

### System Project Defaults

- Saved starting options for newly created projects; per-project settings can override within entitlement/capability limits.
- Distinguish provider voice defaults, system project defaults and an existing project's explicit settings.
- Saving defaults must not overwrite every existing project, reset usage or change subscriptions.
- Code/UI: `app/Services/Media/ProjectDefaults.php`, `ProjectDefaultsController`, `resources/js/pages/settings/defaults.tsx`.

### Storage & Retention

- Videos use S3-compatible Cloudflare R2. User created a dedicated `ai-media` bucket; do not return to the POS bucket/prefix.
- Use `StorageSettings` / `MediaStorage`, saved connection revisions and private authenticated/signed access.
- Never hardcode account tokens/secret access keys in source or send them to browsers.
- The system budget (historically 4 GiB in development) is not Cloudflare's contractual free-tier limit and is not a maximum package quota.
- Upload formats/size/duration, capacity and retention are configured separately.
- Connection changes/new settings must preserve correct access to files stored with an older connection/revision; do not blindly redirect old object paths to the new bucket.
- Cleanup preview is a dry run. Actual deletion is a separate explicit/scheduled operation with recorded history.
- Retention off means no automatic deletion should be claimed. A configured policy is not evidence the scheduler is actually running.
- Inspect `routes/console.php`, `RetentionCleanup` and schedule/heartbeat behavior before describing production automation.
- Source: `StorageSettings.php`, `MediaStorage.php`, `RetentionCleanup.php`, `StorageSettingsController`, `resources/js/pages/settings/storage.tsx`.

### General Settings

Route: `/admin/settings/general`.

- Platform name/tagline, main logo, optional light logo/favicon, live preview.
- Interface language default (my/en/th), display timezone/date format/12-or-24-hour format.
- Existing user language preferences take precedence; default is for new/unset accounts and visitors.
- Display formatting is independent of UTC persisted timestamps and billing/period calculations.
- Support email/phone/HTTPS URL are exposed through a safe public settings projection.
- Raster logo uploads are validated (PNG/JPG/WEBP, up to 2 MiB); favicon PNG/ICO up to 256 KiB. New assets only become active after a successful save. Discard preserves current branding.
- Settings are encrypted in `platform_settings`; safe audit events are in `platform_setting_events`.
- Save uses an expected revision and atomic update; a stale editor must not silently overwrite someone else's change.
- Shared app branding, title/favicon and date-display helpers consume the safe public projection.

SMTP:
- Enable switch, host/port/encryption, username, password replacement, sender name/email, optional reply-to.
- Stored password is encrypted; admin payload only has a blank password field and has-password indicator. Blank replacement preserves the saved secret.
- Test Connection authenticates/connects without sending mail.
- Send Test Email requires an explicit recipient/confirmation and uses saved configuration. Dirty settings must be saved first.
- A successful send means the SMTP server accepted the mail, not guaranteed inbox delivery.
- Test failures are safe common error toasts; do not display raw server credentials/exception responses.
- Plain SMTP without encryption is limited to localhost.
- **Saving SMTP never sends welcome emails by itself. Admin can select Send Login Information during account creation or confirm a resend from Account Details when saved SMTP is enabled.**
- Test code uses mocks; actual external SMTP delivery still requires real saved credentials and an explicit manual test.

Maintenance:
- Enabling requires confirmation and Save; toggling an unsaved switch alone does nothing.
- Blocks new upload/import/complete/preview/start/generate/retry actions with a safe 503 maintenance response.
- Keeps Admin access, current work, history/results/downloads and non-blocked cancellation/deletion usable.
- Does not erase files, grant refunds or stop subscription clocks.
- Existing workers finish previously queued/running work.
- Exact blocked routes live in `EnsurePlatformAvailable`; do not replace this with a global down mode that locks out Admin.

Key files: `app/Services/GeneralSettings.php`, `PlatformMail.php`, `app/Models/PlatformSetting.php`, `GeneralSettingsController`, `ApplyPlatformSettings`, `EnsurePlatformAvailable`, `SetLocale`, `HandleInertiaRequests`, `resources/js/pages/admin/general-settings.tsx`, `resources/js/lib/platform-display.ts`, `resources/js/components/brand-logo.tsx`.

## 12. Jobs, automatic workers and health

- A background worker is still technically required for heavy processing. The user does not want to keep a terminal manually running it.
- Automatic processing launches a background worker on demand, handles queued work/retries and stops after the configured idle interval.
- `php artisan media:work` remains a manual troubleshooting/managed-worker command, not a new prerequisite to force onto the user.
- `WakeMediaWorker` and `AutomaticMediaWorker` implement on-demand startup. Startup can be triggered by authenticated web requests when pending work exists.
- Do not disable the queue or run heavy video processing inside a web request merely to remove a terminal command.
- Automatic mode with zero online instances and no work is normal Standby, not automatically an outage.
- Worker rows represent real registered instances. Repeated automatic starts can produce multiple stopped historical instances.
- Worker Health should initially show data (All Workers), not a blank current-only screen requiring Recent History first.
- All / Current / Recent History scopes are useful; online/busy/idle summary counts must only reflect current live heartbeat state.
- A worker tied to an already-terminal task is not necessarily still busy.
- Queue overview separates preparation and processing queues, ready/delayed/claimed work and oldest waiting work.
- Job Monitor is Admin-wide: customer, project/job, type, progress/stage, status, start/duration, allowed actions, details/events/checkpoints and files.
- State/action eligibility comes from the backend. Do not display impossible pause/restart/kill controls merely because a mockup contains an icon.
- Polling is silent; opening a details panel must not first show content and then abruptly blur it with a global loader.
- Fixed panel headers/footers and animated entry/exit are already established.

Source: `AdminJobMonitor.php`, `AdminWorkerHealth.php`, `app/Services/Media/OperationsMonitor.php`, `OperationsTelemetry.php`, `AutomaticMediaWorker.php`, `app/Http/Middleware/WakeMediaWorker.php`, `config/operations.php`, `resources/js/pages/admin/job-monitor.tsx`, `worker-health.tsx`.

## 13. UI/UX decisions to preserve everywhere

- General Settings must fill the available content width, matching other Admin forms. Do not restore a centered fixed/max-width wrapper that leaves large side gutters on wide or zoomed-out screens.

- Admin frame copies the approved customer sidebar/header structure. Brand-row lower edge aligns with header lower edge.
- Use the locked Iris/Mint theme in `AI_MEDIA_THEME_COLORS.md` and `resources/css/theme.css`; do not restore POS yellow/black styling.
- Auth screens are dark-only cinematic login with saved ZATLAN logo/background, compact card, no theme button. Admin and user access badges differ.
- Other screens support both themes, saved preference, desktop/mobile and my/en/th. Use real flag assets in `public/images/flags`.
- Compact fonts about 11–12px UI / 12–13px controls, with Myanmar/Thai line height and usable touch targets.
- Sidebar submenus stay expanded; no submenu accordion toggles. Sidebar show/hide itself is animated.
- Breadcrumbs are plain group name → current form name. No leading icon or boxed current-form pill.
- Do not repeat the four Settings tabs inside forms when sidebar navigation already provides them.
- Reuse existing shared components rather than adding competing UI systems.
- Save/Create buttons remain clickable with missing required data. On submit, validate, show field errors and focus the first invalid field. Do not disable Save because Reason is blank.
- Only busy actions or genuinely unavailable operations are disabled. Unavailable controls use not-allowed, not a loading cursor. Show why unavailable where needed.
- Required asterisks are red, including Reason.
- Search focus ring belongs to the entire outer input wrapper, not a nested inner rectangle.
- Textareas use the same border/focus tokens as textboxes.
- Pagination numbers are clickable. Cancel superseded requests/latest response wins; only true boundary arrows or actual unavailable actions are disabled.
- Shared success/error toast is top-right, themed, animated and about 3 seconds.
- Copy feedback is a local Copied tooltip near the icon for about 2 seconds; no persistent full-width message.
- Shared blocking loader: centered animated video icon/ring over a blurred backdrop; no logo or surrounding text/card. Background polling stays silent.
- Modal/panel body must scroll with hidden scrollbar; header/footer stay fixed and do not jitter.
- Use existing year/month/day date picker; avoid reverting to a plain native calendar where the custom picker is established.
- Details/actions need clear tooltips. Remove redundant history/view buttons when one details action already provides the same content.
- Keep allowance summaries compact. Do not introduce four large extra cards in a subscription modal where one row/chips fits.

Reusable code: `layouts/app-layout.tsx`, `components/studio-icon.tsx`, `studio-breadcrumb.tsx`, `media-workflow.tsx` (MediaDialog/SelectField), `date-picker.tsx`, `action-tooltip.tsx`, `lib/loading.tsx`, `lib/toast.tsx`, `lib/media-api.ts`.

## 14. Database / service map

| Domain | Main persistence / service |
| --- | --- |
| Accounts | users, CustomerAccountEvent; CustomerAccounts |
| Packages | Package, PackageVersion, PackageEvent, PackageOperation; PackageCatalog |
| Subscriptions | CustomerPackageAssignment; Subscriptions |
| Receiving accounts | PaymentMethod, PaymentMethodVersion, PaymentConfigurationEvent; PaymentConfiguration |
| Transactions | PaymentTransaction, PaymentReceipt, PaymentRefund, PaymentEvent; Payments |
| Allowances | UsageRecord, UsageEvent; UsageLedger, CustomerUsageSummary |
| Media | MediaProject, MediaRun, MediaTask, MediaPreview, MediaPreset; Media services/jobs |
| Operations | MediaWorker, MediaTaskAction; telemetry, AdminJobMonitor/AdminWorkerHealth |
| General settings | PlatformSetting, platform_setting_events; GeneralSettings, PlatformMail |
| AI/storage/defaults | Dedicated media configuration/revision tables; ProviderSettings, StorageSettings, ProjectDefaults |

Use named routes from `routes/admin.php` / `routes/web.php`. Admin shared links are in `HandleInertiaRequests`; menu declarations are in `resources/js/lib/admin-navigation.ts`. A new form normally needs both a real protected route and its shared navigation link.

## 15. Remaining work and sensible continuation

Not implemented/finished as complete Phase 2 product flows:

1. Customer-side integration and operational acceptance for the implemented Admin Dashboard/Revenue/Usage reports; these Admin screens are no longer pending.
2. Audit retention/archival and production database integrity controls, if required; unified Audit & Security screen is implemented.
3. Customer-facing Support/chat UI and navigation; Admin Support Inbox and owner-scoped customer backend endpoints are implemented.
4. Customer Phase 2 payment selection/submission, request-information responses and payment/refund status UI.
5. Optional future email queue/retry infrastructure or delivery/bounce tracking; explicit synchronous welcome/login delivery is implemented.
6. Customer Preferences and full customer dashboard/subscription/allowance presentation.
7. Production deployment, real scheduler/supervision, backups, provider quota/SMTP/R2 live checks, operational acceptance and original-notebook parity where required.
8. Audit older admin subscription/account-correction contracts against the payment-only paid-access policy.

No next form has been authorized just by listing it here. Ask/confirm the next screen's scope through the user's next instruction. When the user wants a UI-generation note, they prefer copyable text in chat instead of accumulating extra note files; this handover file was explicitly requested as an exception.

## 16. Development and verification checkpoint

- PHP 8.3 project, Laravel 13, React 19, Inertia 3, TypeScript 5.9, Vite 7, Node >=22.16. Confirm installed versions from lockfiles/tools before using version-specific APIs.
- Read config classes and secret-presence/status projections. Do not dump .env or decrypted settings.
- Keep migrations additive. Never use migrate:fresh, delete live storage, reset passwords or rerun destructive seeds for routine UI work.
- On Windows PowerShell, use `npm.cmd` if execution policy blocks npm.ps1.
- Typical commands:
  - `php artisan route:list --path=admin --except-vendor`
  - `php artisan test --compact tests/Feature/GeneralSettingsTest.php` (choose relevant tests for the task)
  - `php artisan test --compact` (broader regression when justified)
  - `php vendor/bin/pint --dirty --format agent` after PHP changes
  - `npm.cmd run build`
- Most recent completed verification before this documentation update: **425 tests passed, 4326 assertions; build passed; Pint completed.** This is a historical checkpoint, not a substitute for checking future changes.
- General Settings browser checks covered desktop/mobile, both themes, three locales, no horizontal overflow, validation/focus, dirty-save/discard/leave behavior and hidden-scrollbar timezone options.
- Browser SMTP-save QA used mocked responses; tests used fakes. No real SMTP delivery was certified.
- General Settings migration `2026_11_12_150238_create_platform_settings_table.php` had been applied locally. Recheck migration status on another machine.
- Some local setup may have a running Vite dev server/hot file. An already-open browser can hold old Inertia props after an HMR change; refresh after adding page props. Do not stop the user's servers or switch ports silently.
- The repository was largely untracked at the last inspection. Do not assume a clean git baseline or discard existing files.
- Workspace permissions may cover crm/pos but not ai_media. If needed, use the environment's normal approval mechanism for ai_media writes; do not relocate this project just to bypass the workspace boundary.
- Background processes started for QA must be owned/tracked and cleaned up; leave the user's dev services alone.

## 17. Copyable new-chat request

> Read AGENTS.md and PHASE2_SYSTEM_HANDOVER.md in C:\laragon\www\ai_media first. We are continuing ZATLAN Phase 2, currently focusing on Admin Side. The real Admin Dashboard is now implemented after Admin Support Inbox; customer Support UI remains in the customer-side phase. Preserve the established UI and the business rules: Admin creates Free accounts, paid access is granted through approved Payments, current/upcoming users use the latest published package, usage is reserved/charged/released correctly, and welcome/login emails require explicit Admin opt-in and saved SMTP. Compare the current source against the handover before changing anything. My next task is: [describe the next form/change here].

## Login-information email update — 2026-11-13

- User confirmed SMTP test now works and explicitly requested activation of customer login-information emails. This supersedes earlier deferred-email decisions.
- Save Configuration alone never sends a welcome email; account creation checkbox and Account Details resend confirmation are the entry points.
- Notification: app/Notifications/CustomerWelcome.php; service: CustomerAccounts::mailReady(), sendLoginInformation(), deliverLoginInformation(); tests: tests/Feature/CustomerLoginInformationTest.php.
- No real customer email was sent as part of automated verification; notification tests use fakes/mocks.


## Audit & Security implementation — 2026-11-13

- Implemented Admin-only read-only screen at `/admin/audit`; JSON detail at `/admin/audit/{id}`, filtered CSV at `/admin/audit/export`.
- UI: existing Admin frame, full-width layout, four actual-count cards, six category tabs, actor/result/date/search/sort filters, custom UTC date range, 10/25/50-row pagination, one centered view action, animated detail drawer. Drawer header/footer remain fixed; body scrolls with scrollbar hidden. Shared toast/loading/tooltip/date-picker components; my/en/th and both themes.
- `audit_events` is a separate safe journal with unique (source, source_id). Domain-event creation observers copy the safe record in the same database transaction, so rollback does not leave a success entry and replay does not duplicate it. Actor/target identity snapshots survive later source changes/deletion. There are no Audit create/edit/delete web endpoints; model instance updates/deletes are rejected. This is application-level immutability, not protection against a privileged database administrator.
- Sources: customer account and subscription events, package events/versions, payment/refund events, payment-configuration events, General Settings saves/SMTP tests, provider revisions/tests, storage tests/new saves, new System Project Defaults saves, Admin job actions, and manual usage adjustments. Automatic usage reservations/heartbeats are not copied into this administrative journal.
- Authentication listeners record sign-in success/failure, lockout, sign-out and password reset. Middleware records forbidden access once. Failed-login credentials/attempted passwords are never saved. Context uses a generated request UUID, browser family and masked IP, not cookies/session IDs/raw User-Agent.
- `AuditTrail` uses explicit safe-field lists. It never decrypts/imports provider/R2/SMTP settings, payment evidence/correction ciphertext or receipt files. Password/session actions show action names only. Package before/after comparison uses the actual saved versions; new profile edits record permitted profile before/after values. Older events with no comparison/security context honestly show unavailable values.
- Result and sensitivity are separate: an SMTP failure is Failed; an SMTP test is not itself a sensitive settings change; an Admin successfully rejecting a payment is a successful administrative action. The initial importer’s five SMTP test classifications were corrected locally; original settings/payment data was untouched.
- `php artisan audit:import-history --no-interaction` safely imports existing domain records and provider revision identities with stable source IDs. It was run locally and is repeatable; it never replays actions or sends mail. It cannot recreate deleted source rows, pre-existing login events, historical Storage/Defaults revisions that were never retained, or IP/before-values that were never recorded.
- Migration `2026_11_13_025032_create_audit_events_table.php` applied locally (batch 24). On another machine, migrate before enabling the new hooks, then import history once. Future settings/security actions record automatically.
- CSV uses the same filters and sort, UTC timestamps, UTF-8 and spreadsheet-formula protection; excludes raw metadata/credentials. Viewing/filtering/exporting only reads the journal.
- Main files: `app/Services/AuditTrail.php`, `app/Models/AuditEvent.php`, `app/Http/Controllers/Admin/AuditController.php`, `app/Http/Middleware/RecordAuditContext.php`, `app/Console/Commands/ImportAuditHistory.php`, `resources/js/pages/admin/audit-security.tsx`, `resources/js/lib/audit-i18n.ts`, `resources/css/audit-security.css`, `tests/Feature/AuditSecurityTest.php`.
- Browser checks: desktop 1672px and phone 390px; dark/light, Burmese/English/Thai; no page horizontal overflow or JavaScript exceptions; direct pagination 2 → 3, empty search/reset, whole-search focus outline, panel fixed header and functioning hidden-scrollbar body. No financial changes, new customer emails or video generation were performed during QA.

- Final verification for this change: **446 tests passed, 4495 assertions; TypeScript/Vite production build passed; Pint completed.** This supersedes the older 425-test checkpoint for this implementation, without certifying external provider delivery or production readiness.

## Pagination boundary controls ? 2026-11-13

- Every implemented pagination control now includes dedicated First page and Last page buttons alongside the existing Previous/Next and page-number controls. Shared component: `resources/js/components/page-boundary-button.tsx`; styles: `resources/css/pagination.css`; first/last icons in StudioIcon.
- Covers 13 pagination sets: Admin Users, Packages, Subscriptions, Payment Configuration, Payments & Refunds, Usage Ledger list + customer recent-video/history panels, Job Monitor, Worker Health, Audit & Security, customer Projects and shared customer OpsPagination. The shared operations pager covers the customer Job Monitor.
- Buttons preserve each screen's existing filters, page size, fetch/cancellation handler and real loading guards; first/last are disabled at their respective boundaries, including both on a single-page list. Labels/tooltips are localized to my/en/th; colors inherit the approved theme.
- Mobile pagination wraps inside its available width. Browser checks on Admin lists at 320px/390px showed no horizontal overflow; Audit real-data first/last jumps were verified. No backend/schema or business-flow change was required.

## Admin Notifications implementation — 2026-11-13

- Implemented `/admin/notifications` under Administration, reusing the established full-width frame. Header bell is now a real Admin-only notification feed on every Admin form.
- Schema: additive migration `2026_11_13_040409_create_admin_notices_table.php` creates shared safe notices and per-Admin read/archive state. It has been applied locally. Migrate on another environment before enabling the corresponding model observers.
- Sources: pending/approved payments, requested/under-review refunds, failed/retry media tasks and recovery, expiring/expired subscriptions, newly active paid periods, verified storage usage >=90% of the latest effective package quota, unavailable workers when actual work has waited, blocked repeated sign-ins, failed provider/R2/SMTP checks and maintenance changes. No sample customer alerts were seeded.
- Ordinary worker standby/idle shutdown without waiting work is normal. It does not generate a worker failure. Completed/cancelled tasks and recovered connections resolve the corresponding issue. A successful SMTP email also clears an older SMTP connection failure; synchronization uses the latest SMTP result, rather than replaying an old failure after a newer success.
- Subscription renewal coverage distinguishes a scheduled consecutive period from a gap. Covered expiry is informational; a gap still needs attention. Superseded/ended/archived access alerts are resolved. Storage notices follow verified files and current published entitlements; ending access resolves the old quota alert.
- Reading/archive state is per Admin and independent of issue state. Viewing details marks that notice read through a protected POST; opening the bell/list alone does not mark everything read. Mark All applies to current inbox notices, not archived notices. Archive/restore never deletes the source record.
- Semantic deduplication keeps one notice per relevant issue. Repeating a check does not create duplicate rows or restore an archived item. A genuinely recurring failure increments its revision, returning it to the inbox as unread.
- List: actual unread/attention/critical/last-24-hours cards; category tabs; archive toggle; search/read/priority/issue/date/sort filters; UTC custom ranges; selected-row read/unread/archive/restore; 10/25/50 pagination with First/Previous/numbers/Next/Last. Search includes safe context such as project/package names. Failed polling retains the last loaded data.
- Bell: unread count (99+ cap), latest five unread inbox items, View All and Mark All. Silent polling every 30 seconds while visible. Feed failures show retry without pretending the count is zero.
- Details: animated right drawer, fixed header/footer and a working hidden-scrollbar body; safe original event context, current related-record status, copy tooltip and a link to the appropriate source form. It loads before opening, so the drawer does not appear and then abruptly blur. Payments are approved/rejected only in Payments & Refunds, and jobs are managed in Job Monitor.
- Notifications never send mail, call an AI provider, process video, modify a payment/subscription, or resolve an issue merely because it was read/archived. Customer-side notification and payment-request-response UI remains deferred. Welcome/login email rules remain unchanged.
- Reconciliation: `php artisan notifications:sync`, also registered every minute with Laravel Scheduler. Admin list/feed requests perform a cooldown-protected sync, so local development can see time-based alerts without a manually running notification worker. Production still requires the normal Laravel scheduler. Existing audit-history imports do not create a flood of old notification events.
- Safety: Admin guard + system authorization, CSRF and throttled mutation endpoint; explicit payload validation, transaction-protected state changes, short safe DTOs. No provider/R2/SMTP secrets, receipt content, private receiving details or internal payment notes are copied into notices.
- Main files: `app/Services/AdminNotifications.php`, `app/Models/AdminNotice.php`, `app/Http/Controllers/Admin/NotificationController.php`, `app/Console/Commands/SyncAdminNotifications.php`, `resources/js/pages/admin/notifications.tsx`, `resources/js/components/admin-notifications.tsx`, `resources/js/lib/notification-i18n.ts`, `resources/css/notifications.css`, `tests/Feature/AdminNotificationsTest.php`. Integration: AppServiceProvider, AuditTrail, HandleInertiaRequests, AppLayout, admin routes and scheduler.
- Verification: full suite passed with 457 tests / 4574 assertions before two additional notification tests; the final notification suite passes 13 tests / 87 assertions, and the latest Notifications + General Settings + Audit + Payments regression run passes 75 tests / 741 assertions. Tests use fakes/in-memory DB; no real customer mail or provider calls were sent. Browser checks cover real MySQL reads, desktop 1672px, phone 390px/320px, both themes, my/en/th, hidden-scrollbar drawer scroll with fixed header, no page horizontal overflow or JavaScript exceptions.

- Notifications final build passed (TypeScript + Vite), Pint completed. Browser archive/restore and real MySQL context search were verified without modifying source financial/access records. Owned QA browser/server were stopped; user development services were left running.

## Unread-only header bell ? 2026-11-13

- Header bell dropdown now fetches the latest five **unread, unarchived** notices, applying the unread filter before limiting results. Read items remain available in the full Notifications form/history.
- Opening a notice marks it read and removes it from the bell feed immediately; the next unread notice fills its place. Mark All returns an empty feed.
- Bell badge and dropdown header show the total unread count, not just the five preview rows; the bell shows a muted 0 when none remain. Positive counts retain the 99+ badge cap.
- Empty bell explicitly says there are no unread notifications, localized to my/en/th. Admin read/archive isolation and source issue status remain unchanged.

## Revenue Report implementation — 2026-11-13

- Admin-only read-only screen: `/admin/reports/revenue`, under Reports → Revenue. JSON uses the same index endpoint; CSV is `/admin/reports/revenue/export`; safe entry detail is `/admin/reports/revenue/{payment|refund}/{id}`. All routes retain Admin authentication/session/system authorization; CSV is throttled. No additional revenue database, seeds or financial mutation endpoints were added.
- Existing approved payments contribute their actual received amounts at `approved_at`. Only completed refunds with `completed_at` contribute refunds in that period. Pending, rejected, cancelled, needs-information and Free Trial records do not become collections. Report queries require an approved parent payment for a completed refund.
- MMK, THB and USD totals stay separate. No exchange-rate conversion or mixed-currency monetary grand total. Net = approved collections in range minus completed refunds in range. An older payment refunded in a newer period may make that newer period negative. Multiple refunds do not multiply the original payment. Paying Customers is distinct across currencies, not the sum of per-currency customer counts.
- Date filters use the configured General Settings timezone, with inclusive local start date and exclusive next-day end converted to UTC. Today/7/30 days use calendar-day ranges including today; This Month, Last Month, All Time and Custom are supported. This does not change stored UTC timestamps or the existing subscription-period rules.
- Summary, trend, package/method breakdowns use report-level currency/date/package/method/purpose filters. Search/activity type/sort/pagination affect only the transaction table. Amount sorting requires one selected currency. Table changes do not silently change summary totals.
- All Currencies summary displays three monetary rows on each Gross/Refunds/Net card. The stacked count panel displays approved payments, completed refunds and distinct paying customers. Trend displays one explicitly selected currency at a time, as in the latest approved reference image; chart currency tabs in All view do not mix currencies.
- Native responsive SVG chart has toggleable gross/refund/net series, hover/focus detail, negative-value support, grouping and empty states. Long date ranges are grouped more coarsely to keep charts usable. No chart dependency was added.
- Donut is clearly labeled as the share of **approved payment count** by package, including in All Currencies view. It does not combine unlike monetary amounts. No approved payments shows a truthful empty state even when refunds exist.
- Transaction Summary has Activity / By Package / By Method views. Activity rows represent an approved payment or a completed refund, with references, purchased package/version, purpose, immutable receiving label, currency, signed display amount, timestamp/status and one centered View action. Refund rows also show the original payment reference. Pagination includes First/Previous/numbers/Next/Last and 10/25/50 rows.
- Historical purchased package/version and receiving labels use payment snapshots; changed current package prices do not rewrite paid amounts. Aggregate package/method groups use stable identities and their current administrative display names, including archived items, while transaction rows preserve their captured labels.
- Detail drawer loads before appearing, animates, fixes header/footer, and scrolls with hidden scrollbar. Shows the customer, financial entry, captured package/method and linked subscription dates where available. Lifetime original-payment totals are labeled separately from the filtered report period. Open Payment returns to Payments & Refunds; no approve/edit/refund controls are duplicated here.
- Safe DTOs/CSV never include private receiving account details, refund destination, receipt content, internal notes or ciphertext. Receiving snapshot is decrypted server-side only to whitelist its display name/type. Monetary source data is not modified, and no customer mail/provider/video work is triggered by reports.
- CSV includes all matching financial activity, not just the current page. It uses UTF-8 BOM, explicit currency, separate positive incoming/outgoing columns, timestamp/timezone, reference/customer/package/version/purpose/method/status and formula-injection protection. Decimal CSV amounts are generated from integer minor units. Report/detail/export reads use database transactions; production read consistency follows the database isolation configuration.
- Browser checks used real local MySQL data: currency switching, package/method views, search retaining summary counts, refresh failure retaining loaded rows, recovery and CSV. Desktop 1672px, phones 390px/320px, both themes and my/en/th showed no horizontal page overflow, question-mark corruption or JavaScript exceptions. Drawer body scroll and fixed header/footer were verified. Browser fixtures for refresh errors were temporary; no real payment/refund/subscription changes or external email were made.
- Main sources: `app/Services/RevenueReport.php`, `app/Http/Controllers/Admin/RevenueController.php`, `resources/js/pages/admin/revenue.tsx`, `resources/js/components/revenue-charts.tsx`, `resources/js/lib/revenue-i18n.ts`, `resources/js/types/revenue.ts`, `resources/css/revenue.css`, `tests/Feature/RevenueReportTest.php`; integration in Admin routes and shared Admin links.
- Verification: full regression passed **468 tests / 4682 assertions** before two additional report tests. Final focused Revenue suite passed **10 tests / 91 assertions**, covering currency separation, distinct customer counting, completed-only refunds, negative periods, multiple refunds without duplicate gross, timezone/day/month boundaries, filters/pagination, unchanged historical snapshots, safe details, CSV and authorization.
- Final checks: Pint passed; TypeScript and production Vite build passed. Temporary headless browser and review server on port 8002 were closed after browser verification; existing development servers were left running.

## Admin Usage & Processing Report implementation — 2026-11-13

- Implemented read-only Reports → Usage & Processing at `/admin/reports/usage-processing`. JSON uses the same index, CSV uses `/export`, and customer detail uses `/customers/{customer}`. Existing Admin guard, authenticated session and manage-system authorization apply. CSV is throttled. No schema changes, source-data seed/reset, financial mutations, provider calls or customer email were introduced.
- Preserves the existing full-width Admin frame, active Reports navigation, plain breadcrumb, compact fonts and locked theme tokens. Screenshot composition: six summary cards, dual-axis bar/line chart, export outcome donut, mode/source donuts, top customers and customer summary table. Uses actual data only. Decorative previous-period percentages from the mockup were deliberately omitted because the report does not calculate a comparable previous period.
- Projects means distinct project IDs with matching activity, including project creation, finished tasks and charge/correction events. A project with several tasks/exports is not multiplied in this metric. Input-source share uses those same distinct projects.
- Processing Used is the signed sum of ledger charged events minus adjustment events at each event's own timestamp. It is source-video time, not task/GPU execution time. A correction against an older charge can make a newer period negative. The report does not mutate allowance accounting. Mode attribution uses the immutable run settings where present; missing/deleted context is explicitly Not recorded.
- Successful/Failed/Cancelled Exports count final `generate` tasks, using their recorded terminal status and finished_at. Analysis/import/validation/preview jobs do not inflate export counts. A task retry is not another export row. Telemetry's final task state represents the job outcome, not an immutable log of every failed attempt. Missing terminal timestamps are excluded rather than invented.
- Average Export Duration uses stored measured processing_seconds on successful exports with valid started_at/finished_at; missing measurements show a dash. This accumulated processing duration may include retries and excludes queue wait; it is not GPU utilization. Success rate is successful / (successful + failed), excluding cancelled/unfinished/non-export tasks, and is unavailable when there is no denominator.
- Current Storage includes verified media_files whose state is not deleted, including retained source/output files. Stock ignores historical date bounds. Customer/source/mode scope applies, and package stock uses the currently selected subscription identity. Current reservations use reserved ledger records and their captured package/run context. The page labels and footnote distinguish these current values from period metrics.
- Historical operation package filters/badges come from usage snapshots. Project-creation events can use an assignment covering creation time; if unavailable they are Unknown. Package aggregates use stable package IDs and current names, while customer badges/detail exports preserve captured versions. Old records without sufficient context are not silently assigned today's package.
- Trend has explicit minutes on its left axis and successful-export counts on its right axis; integer count ticks, safe negative usage, keyboard/hover details, toggles and automatic daily/weekly/monthly grouping. Mode donut uses net charged seconds; negative correction cases show signed values without inventing a percentage composition. Source and outcome donuts use counts. Top-customer share uses positive net usage as its denominator.
- Filters: calendar presets including Today/7/30/current/previous month/all/custom, package, searchable customer (bounded 50 suggestions plus selected identity), processing mode and input source. General Settings timezone defines local day bounds; storage and reservations remain current. Requests are debounced and abort/sequence guarded; stale responses cannot replace newer results. Silent polling every minute while visible; failed refresh retains last loaded data and shows retry/toast.
- Table views: Customers (one row per customer), Packages and Processing Stages. Table search/sort/pagination do not change report summaries/charts. Stage counts are explicitly job counts, not video counts. Customers with retained stock/current reservations can appear with zero activity in an older selected period. First/Previous/direct numbers/Next/Last and 10/25/50 page sizes are present.
- Customer details load before an animated right drawer appears. Header/footer remain fixed; body scrolls with hidden scrollbar. Shows filtered metrics, separately labeled current subscription, latest 20 finished exports with their captured package/version and measured duration, and context links to Usage Ledger/Job Monitor. These links use supported query parameters and All Dates for the full related job list. No duplicate history/retry controls or assumed playable files.
- CSV exports all matching rows of the selected Customers/Packages/Stages view, including current table search/sort and explicit date/timezone. Exact integer source-video seconds, bytes, counts and clearly labeled measured durations avoid rounding away small usage. Spreadsheet formula protection applies. Safe DTOs/CSV exclude transcripts, source paths, secrets, internal correction reasons and raw worker logs.
- Browser verification: real local MySQL returns actual usage; tested customer/package/stage views, keyboard search, full CSV, failed refresh retention/recovery, and customer drawer scrolling/fixed footer. English Dark desktop, Myanmar Light desktop, Thai Light 390px and Myanmar Dark 320px have no horizontal page overflow, question-mark corruption or JavaScript exceptions. No real payment/subscription/media generation mutations were made during report QA.
- Main files: `app/Services/ProcessingReport.php`, `app/Http/Controllers/Admin/ProcessingReportController.php`, `resources/js/pages/admin/processing-report.tsx`, `resources/js/components/processing-report-charts.tsx`, `resources/js/lib/processing-report-i18n.ts`, `resources/js/types/processing-report.ts`, `resources/css/processing-report.css`, `tests/Feature/ProcessingReportTest.php`; routes and shared Admin links connect the existing usageReports menu.

- Final verification: focused Processing Report suite passed **10 tests / 90 assertions**; combined Processing Report, Revenue Report, Customer Usage Summary and Usage Ledger regression passed **45 tests / 361 assertions**. Pint, TypeScript and production Vite build passed. Temporary browser QA was closed; existing application/Vite processes were left running.

## Processing customer-detail visual revision ? 2026-11-13

- Applied the latest supplied detail-panel reference in the isolated ProcessingCustomerDetail component: 800px desktop drawer, customer/period hero with subtle waves, colored icon summary cards, full-width reservation card, compact subscription/status/date strip, recent exports with authenticated real posters and source-duration labels, separate mode/format/duration badges, read-only information box and fixed footer links. Mobile uses the viewport width, wrapping subscription/record content; body scrolling remains functional with hidden scrollbar. Both themes and my/en/th are supported.
- Recent export DTO adds only poster_url and verified metadata source_duration; thumbnails use the existing Admin-authorized Job Monitor poster endpoint and fall back when unavailable. Thumbnail navigation opens the corresponding job. No source/storage paths or full metadata are exposed. Current subscription adds the safe package type for its icon. Date-only report range formatting preserves the recorded calendar dates across browser timezones.
- Validation: Processing Report tests passed 11 tests / 96 assertions; Pint, TypeScript and production build passed. Real posters loaded locally; desktop/390px/320px, Light/Dark and my/en/th checks found no panel overflow or JavaScript exceptions. Header/footer remain fixed while the hidden-scrollbar body scrolls. No source financial/usage/subscription records were changed.

- Latest owner correction: Processing detail Usage Summary is compact again: four columns on desktop, seven metrics in two rows (Reserved spans two columns), 9px labels, 14px values, 27px icons and roughly 56px cards. Phones use compact two-column cards with 13px values and working scroll. This supersedes the earlier large two-column/four-row summary presentation.

## Admin Support Inbox implementation — 2026-11-13

- Implemented the existing Customers > Support menu at /admin/support, using the established Admin frame and logo, plain Customers > Support breadcrumb, compact typography, both locked themes and my/en/th.
- Desktop: independently scrolling conversation list, chat thread with fixed header/composer, optional customer-context column. Tablet/phone: list/chat navigation, compact fixed composer and animated context drawer. All relevant scrolling works while scrollbars are hidden. The Admin conversation list loads 20 records initially and appends the next 20 when scrolling down; pagination controls are removed.
- New Conversation: searchable real customers, required subject/category, priority, optional customer-owned Payment/Job/Subscription reference, message and optional attachments. No sample conversations or customer replies were seeded. Empty state represents an actual empty inbox.
- Additive tables: support_conversations, support_messages, support_reads, support_attachments. Only the new support migration was applied to the local DB. No financial/customer/package records were reseeded/reset/changed for QA.
- Replies, Admin-only internal notes, safe bold/italic/list text formatting, emoji insertion, quick replies that populate the composer without auto-sending, scoped quoted replies, image/PDF attachment cards, draft preservation across conversation switches and per-admin session-tab text drafts. Sending failures preserve drafts and retry uses the same operation UUID for the unchanged payload (including across thread switches). No arbitrary HTML is executed.
- Limits: messages up to 4,000 characters, subject 160; up to 5 PNG/JPG/WEBP/PDF files per message, 10 MiB each. Server validates actual MIME/extension and image dimensions (8,000 pixels per side, at most 40 MP). Attachments currently use the private local application disk like payment receipts; videos remain on their configured R2 disk. Authenticated routes stream previews/downloads after conversation ownership/visibility checks; no raw storage paths/public file URLs. PDF downloads are attachments. No support-file retention/deletion UI was added.
- Read and Needs Reply are separate: read acknowledgements are explicit, monotonic and bounded to actually returned messages. Admin reading clears their own unread state but not the unanswered issue. An internal note does not count as a customer reply or clear Needs Reply. A public Admin reply sets Waiting for Customer. Customer reply opens a resolved/waiting conversation again. Manual status/category/priority changes are revision-checked and produce timeline/audit entries.
- History is cursor-paged, latest 60 messages plus Load Earlier; refreshing merges older loaded history. New messages auto-scroll only when already near the bottom, otherwise show New Messages. Silent visible-page polling every 8 seconds, AbortController/sequence guards, stale-data retention and retry. No WebSocket dependency, typing indicator, online presence, actual customer-delivery claim or fabricated read receipt.
- Customer context reads actual identity/account status/last login, latest effective package/period/status, recent payment amounts with currencies, recent jobs, current verified media storage and context links. Related record ownership is checked by the backend. Support does not approve payments, issue refunds, grant packages or retry video processing.
- Payment Request Information now creates/reuses the customer's unresolved Payment-linked conversation in the same transaction. Replayed payment actions do not duplicate messages. Asking for information still leaves payment needs_information and grants no access. Customer replies do not approve a payment or replace its current receipt automatically; Admin reviews evidence in Payments.
- Support activities are recorded in the safe Audit journal without message bodies/attachments. Incoming customer messages create real Support-category Admin notifications and protected conversation links. Support read acknowledgements mark corresponding notices read; Admin replies/resolution resolve the associated attention notices. Unread conversation counts remain per Admin.
- Customer-side Support UI/navigation remains intentionally pending for the customer phase. Authenticated /support-api endpoints already support owner-scoped list/create/detail/reply/read/attachments, with no customer access to internal notes, their files, quoted private content or another customer's records. Admin/customer endpoints use existing web sessions/CSRF and request throttles. Messages saved by Admin are not described as delivered/read without an actual customer acknowledgement. No support email is sent automatically.
- Main files: app/Services/SupportInbox.php; Admin/SupportController.php and CustomerSupportController.php; resources/js/pages/admin/support.tsx; resources/js/components/support-inbox.tsx; support-i18n.ts; types/support.ts; resources/css/support.css; support migration; tests/Feature/SupportInboxTest.php. Shared routes/Admin links, StudioIcon, optional controlled ActionTooltip display, Payment Request Information, Audit related links and Notifications support category were connected.
- Validation: Support/Payments/Notifications/Audit regression passed 81 tests / 791 assertions. Pint and production TypeScript/Vite build passed. Browser checks verified actual local empty state/options, desktop full-width layout, New Conversation validation/scrolling, and representative chat UI using browser-only fixtures (no real customer conversation writes). Chat draft retention, note sending, 320px/390px phones, my/en/th, Light/Dark, fixed composer, hidden-scrollbar modal/context scrolling, no horizontal overflow and no JS exceptions were checked. Owned QA browser was closed; existing PHP8000/Vite5174 were left running.
- Historical checkpoint: the real Admin Dashboard was pending at this Support checkpoint and is implemented in the latest Dashboard checkpoint below. Customer Support, billing/payment replies/preferences/dashboard remain separate future work; no next screen is authorized merely by this note.

### Support Inbox owner refinements — 2026-11-13

- Admin list uses encrypted, actor/filter/sort-bound keyset cursors: fetch at most 21 rows to return 20 plus has-more detection. An additive (last_activity_at, id) index is installed. Latest and waiting sorts handle equal timestamps consistently. Customer API paging is unchanged.
- Infinite scrolling retains the selected chat, deduplicates appended rows, cancels stale filter requests, preserves loaded rows on failure and offers local Retry. Silent refresh retains loaded pages and the visible scroll anchor. No customer conversations were seeded or changed for browser QA.
- Conversation three-dot menu closes on outside pointer interaction or Escape, and when switching conversations. Inside menu controls remain usable.
- Normal priority has no marker. High has a prominent amber warning triangle; Urgent has a red lightning icon. Markers are 28px with 20px SVGs and remain visible in compact layouts. Emoji trigger uses a recognizable 25px smile SVG; emoji insertion remains functional.
- Latest validation: SupportInboxTest passed 19 tests / 183 assertions; Pint, TypeScript and production build passed. Browser-only 45-row fixtures exercised initial 20, failed next-batch retry, 40 then 45 rows without duplicates, outside/Escape dismissal, emoji insertion, and desktop/390px layouts. Screenshots confirmed compact markers, emoji control and hidden scrollbars. Owned QA Chrome closed; existing application servers retained.
### Support compact chat layout — latest owner correction, 2026-11-13

- Removed the large repeated Support title/description and duplicate left-side All/Unread/Needs Reply/Resolved tabs. The breadcrumb identifies the screen; an accessible hidden H1 remains. A single compact top toolbar now contains All plus the four existing count filters and New Conversation. Filters keep the same backend behavior; there is no duplicate filter state.
- Left conversation rows are approximately 60px high with 30px avatars. High/Urgent markers are now plain 20px containers with 18px amber/red SVGs, no border, background or shadow; Normal remains unmarked. This supersedes the earlier boxed 28px markers.
- Compact composer combines reply/internal-note tabs and formatting/emoji controls in one top toolbar, a 36px initial textarea and compact attachment/quick-reply/send footer: about 106-107px overall. Text grows only up to 84px then scrolls, so long drafts do not consume the chat. Private-note hints, quotes, pending files, validation and draft/send/retry behavior remain available.
- Removed the redundant page-heading height, tightened the breadcrumb spacing, narrowed side columns modestly and kept the thread flexing to fill the remaining height/width. The message area is the main workspace. Mobile still switches list/chat; hidden-scrollbar scrolling and 20-at-a-time loading are unchanged.
- Validation: production TypeScript/Vite build passed. Browser-only fixture checks covered filter selection, emoji insertion, growing/shrinking textarea and sending; English Dark/Light desktop and Myanmar 320px/Thai 390px phones have no horizontal page/composer/filter overflow or JavaScript exceptions. No real customer messages or financial records were written during this UI QA.
### Support conversation preview direction — 2026-11-13

- Removed the subject/category icon row from the left conversation list at the owner's request. Existing conversation subjects and messages are preserved; only that list presentation is removed.
- Each last-public-message preview now includes a direction icon and localized Sent/Received prefix (Iris for Admin replies, Mint for customer messages). API preview_sender is read from the same latest public reply as the preview text. Internal notes and status/priority events do not replace that preview or its sender. Attachment-only replies have a localized Attachment fallback; no-reply threads show No messages yet.
- Compact 60px rows, priority markers, infinite scrolling, composer, chat bubbles and other approved layout remain unchanged.
- Validation: SupportInboxTest passed 19 tests / 189 assertions, including Admin/customer direction changes and ignoring a newer internal note. Pint and production TypeScript/Vite build passed. Browser checks confirmed both directions, removed subject rows, 60px rows and no overflow/JS errors on English Dark desktop and Myanmar Light 320px. No real conversation data was changed during QA.
- Latest Support presentation correction (2026-11-13): removed only the Sent/Received preview direction icons from the left list; localized colored prefixes remain. Removed the conversation subject from the chat header and reduced its minimum height/padding. Priority markers remain. Supersedes the prior preview-icon note. Build passed; browser checks confirmed zero preview icons, absent header subject, retained direction labels, no overflow or JavaScript errors on desktop and 320px mobile. No conversation records were deleted.

### Support send controls and message deletion — 2026-11-13

- Explicit owner exception to the normal clickable-validation rule: the Support composer Send button is disabled for an empty/whitespace-only draft with no attachments, and while a request is actually in flight. Valid attachment-only replies remain supported. Empty Enter is a no-op. Plain Enter sends; Shift+Enter inserts a newline. IME composition/229 key events do not send. A synchronous request ref prevents repeated Enter/click sends; existing request UUID replay protection remains.
- Removed the visible Ctrl+Enter shortcut and outbound saved/read receipt icon. Quick Reply is now a small sparkle button in the upper composer toolbar, with a localized tooltip/accessibility label; templates fill the draft without sending. Small translated mobile layouts wrap toolbar groups cleanly rather than overlap. Reply/quote actions use a recognizable curved arrow SVG.
- Added a delete icon only on the signed-in Admin's own non-system replies/internal notes, guarded again on the server. Common confirmation precedes deletion. DELETE /admin/support/{conversation}/messages/{message} is authenticated, CSRF-protected, throttled, conversation-scoped and idempotent. Other Admins' messages and customer/system messages cannot be deleted by this action.
- Applied additive migration 2026_11_13_151417_add_deleted_at_to_support_messages_table.php. Deletion clears stored message body and marks deleted_at, preserving the timeline ID as a muted localized deleted-message placeholder. Attachments are omitted and their authenticated routes return 404; underlying private attachment objects are retained, not publicly accessible. No restore UI or hard conversation deletion was added.
- Quoted deleted messages are redacted, new quotes targeting them are rejected, remembered composer quotes are cleared, and loaded older history receives bounded deleted_ids on polling via loaded_from. Deleted messages do not count as unread. List previews show the deleted placeholder when their latest public reply was deleted. Deletion does not undo conversation status, payments, packages or usage.
- A safe support.message_deleted Audit event is recorded once with a distinct source key; no removed body/attachment content is copied to Audit. Existing idempotent send replay cannot restore the deleted message.
- Validation: SupportInboxTest passed 23 tests / 304 assertions, covering author/scope denial, repeated deletion, customer visibility, quote/attachment redaction, private-note isolation, send replay and older-history tombstones. Pint, TypeScript and production build passed. Browser-only interaction QA verified empty/whitespace Send disabled, Enter send, Shift+Enter newline, IME no-send, Quick Reply fill-only, repeated deletions and quote placeholders. English Dark desktop and Myanmar Light 320px mobile have no horizontal/toolbar overlap or JS errors; Quick Reply stays within the mobile screen. No real customer messages were sent/deleted for QA. Owned browser closed and existing PHP8000/Vite5174 retained.
- Latest Support message-action styling correction (2026-11-13): Reply/Delete SVGs are 12px inside compact 18px buttons with 2px spacing; timestamp keeps 3px extra separation. Existing tooltips/actions are unchanged. Production TypeScript/Vite build passed.

### Real Admin Dashboard — latest checkpoint, 2026-11-13

- Replaced the temporary roadmap screen at `/admin/dashboard` with the supplied dashboard composition: six KPI cards, currency-specific collections/refunds bars, export-result and processing-mode rings, current Needs Attention/System Status, eight quick links, recent final export jobs and top packages by net charged processing time. Existing locked sidebar/header/branding and plain breadcrumb are preserved.
- Source files: `app/Http/Controllers/Admin/DashboardController.php`, `app/Services/AdminDashboard.php`, `resources/js/pages/admin/dashboard.tsx`, `resources/js/lib/dashboard-i18n.ts`, `resources/js/types/dashboard.ts`, `resources/css/admin-dashboard.css`; protected route in `routes/admin.php`.
- Read-only aggregates reuse the authoritative RevenueReport and ProcessingReport event queries. MMK/THB/USD stay separate; integer minor amounts use the established display conversion. Approved payments use approval time, completed refunds use completion time, and net is gross minus completed refunds. No receipt/account credentials or provider secrets are returned.
- Period presets: Today, 7/30 days, this/last month, All Time, custom inclusive local dates. Queries use half-open UTC bounds from the saved display timezone. Chart buckets use local calendar bounds, including historical DST and half-hour offsets, without requiring MySQL timezone tables. All Time starts at the earliest financial or processing activity. Previous comparison uses an equal preceding calendar range; no invented percentage when there is no positive baseline, and no comparison for All Time.
- Successful/failed exports count final `generate` tasks only; validation, analysis and previews do not inflate exports. Processing Used is charged source seconds minus recorded corrections, not wall-clock job time. Recent jobs are bounded to five, use measured job duration (unknown stays unavailable), safe authenticated posters and job-specific filtered Job Monitor links. Negative mode corrections do not produce misleading donut proportions.
- Current Active Customers count active, non-admin accounts with a current unclosed period covering now. Worker availability, verified application-file storage and configured budget remain current even when selecting an old period. Zero online automatic workers is normal Standby. AI Services reports the last matching saved configuration test (or unconfigured/not tested/failed), never a fabricated live connection.
- Needs Attention shows unresolved job notices, current payment reviews, pending refunds, support conversations needing reply and current worker attention. Links open the corresponding real management forms. Quick Actions opens existing User Accounts, Payments & Refunds and Support screens. Dashboard itself does not approve payments, grant access, retry jobs, send email or alter usage/subscriptions.
- Initial data is server-rendered. Visible-page polling is silent every 60 seconds; previous results remain visible on refresh failure with Retry. Abort/sequence guards reject obsolete responses, and refresh uses the shared loading/toast conventions. PC/tablet/mobile and en/my/th, Light/Dark use compact fonts; 320px/390px/768px/1280px/1672px checked without horizontal page overflow. Wide recent-job columns scroll inside their own table with hidden scrollbar.
- Verification: dashboard feature tests cover Admin guard/SSR, empty data, financial currency/timestamps, completed-only refunds, prior period, final exports vs previews, net usage corrections, current access/storage/workers, date validation, timezone DST/half-hour buckets and secret redaction. Dashboard + existing Revenue/Processing report suite passed 27 tests / 265 assertions. Browser checks verified locale/theme layouts, currency switching, Quick Actions outside-close, and failed refresh preserving all six cards then recovering. No real payment/subscription/customer/usage changes or external emails were performed for QA.
- Customer-side Dashboard/Support/billing/preference work and production acceptance remain deferred; this screen does not mean all Phase 2 product flows are finished.