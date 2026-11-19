# ZATLAN / ai_media — Phase 2 System Handover

Updated: 2026-11-15, Asia/Bangkok.
Purpose: persistent context for continuing this project in a new chat.
This document records the user's decisions and a code-reviewed implementation checkpoint. Current source and the latest user request take precedence if they differ from this checkpoint.

## 1. Read this first

ဒီ project က ZATLAN video recap / မြန်မာစာတန်းထိုး / မြန်မာအသံပြောင်း system ဖြစ်သည်။
Phase 1 က video ထုတ်နိုင်ကြောင်း စမ်းသပ်ရန် အရင်ရေးထားခြင်းဖြစ်သည်။
လက်ရှိ Phase 2 မှာ Admin Side ကို အရင်တည်ဆောက်နေသည်။
User account ကို Admin ကသာဖန်တီးပေးမည်။ User ကိုယ်တိုင် register မလုပ်ရ။
Account အသစ်သည် Free Trial နှင့် စမည်။ Paid package ကို Payments မှ Approve လုပ်ပြီးမှ ပေးမည်။
User account ဖန်တီးချိန် Send Login Information ကို ON ထားမှ သိမ်းထားသော SMTP ဖြင့် လုံခြုံသော password setup link ပို့မည်။ Account Details မှ ပြန်ပို့နိုင်သည်။
Admin Dashboard အပါအဝင် Admin forms များ ရေးပြီး User Side Version 1 Recap Studio ကို ပြောင်းထားသည်။ အောက်ဆုံး 2026-11-14 checkpoint ကို အရင်ဖတ်ရန်။ Customer Side Support UI ကို သီးသန့် ဆက်လုပ်ရန် ကျန်သည်။
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

## Latest checkpoint — 2026-11-14: User Version 1 Recap Studio

The current user request implements the three supplied Source & Style / Review Script & Scenes / Render & Download screenshots. Preserve the existing Iris/Mint colors, ZATLAN sidebar/header and very compact typography. The new workspace is flat; do not restore the former enclosing wizard panel. Some review/settings columns and selected controls deliberately retain restrained outlines as in the supplied screenshots.

### Customer workflow now implemented

1. New Project opens **Recap Studio → Source & Style**. Existing chunked, resumable local upload and queued YouTube import are reused. Source metadata, native video preview, replace/remove and private storage remain connected. Replace/remove starts a different draft; it does not delete the previous project's financial/history records.
2. **Narrated Recap** offers **Summarize a Long Movie** or **Translate an Existing Recap**. **Subtitles** preserves the original audio and source sequence. UI locales remain exactly my/en/th; output-language codes are my/en/th/zh/hi/ko/ja/es/id/vi and source auto-detect.
3. Long Movie has an approximate target duration (3/5/10/15/20 minutes or custom 0.5–60 minutes), narration style, chronological/hook/character presentation, ending preference, custom instructions and name dictionary. Target cannot exceed source length. Existing-recap and subtitle modes keep source ordering/duration rather than pretending to summarize a full movie.
4. Write the Script saves settings, starts the existing background job and navigates to the owned analysis URL. Polling displays real job stages and supports cancel/retry. Automatic workers remain on demand; no new terminal requirement was added.
5. **Review Script & Scenes** displays source ranges, inclusion/lock, narration, optional separate translated subtitles, notes and source description. Movie scenes can be added/reordered or replaced by editing their source range. AI regenerate/hook/shorten/expand/names uses the existing asynchronous preview queue and applies a suggestion only after explicit confirmation. Locking is an editing aid; it is not an account permission system. Names/dictionary can be amended; existing text is changed explicitly with Check Names/regeneration.
6. **Render & Download** has Voice/Subtitles/Video/Audio/Export tabs: provider voice, delivery, speech rate, volume/pitch, subtitle styling, aspect/fill/resolution, manual subtitle cover, separated background/ducking and MP4/SRT/ASS/MP3/WAV. Preview is a selected source scene, not an invented whole-project preview. Download links require actual published files. Changing draft settings clears stale local previews.
7. Completed projects can reopen script review and rerender. Existing legacy routes remain and now render the shared studio. Legacy settings default to workflow=translate so existing dubbing projects are not automatically recut as movies.

### Backend and data map

- No schema migration is required: new studio settings and scene data use existing JSON columns.
- Shared React UI: resources/js/components/recap-studio.tsx; source-upload hook: use-recap-source.tsx; styles: resources/css/recap-studio.css; labels: resources/js/lib/recap-i18n.ts. projects/create.tsx, setup.tsx and analyze.tsx are thin entry wrappers. Existing MediaPageProps and MediaLine types were extended compatibly.
- MediaSettings::STUDIO_DEFAULTS adds workflow, output languages, duration/style/story preferences, dictionary, delivery/pitch, caption style and resolution. The old DEFAULTS constant remains usable by historical callers. Request allowlists accept both sets; Admin Project Defaults does not suddenly require every optional studio field.
- New PATCH /media/projects/{uuid}/scenes uses SaveRecapScenesRequest and RecapStudioController. It enforces ownership, idle review state, source bounds, at least one included scene, translation chronology, current script-edit entitlement and an optimistic SHA-256 revision from the current persisted scene array. Save returns the new revision. The legacy text-only script endpoint remains.
- Source/story preference changes require explicit confirmation and clear the old script before reanalysis. Render-only voice/style changes retain the script. Dictionary changes do not silently discard an edited script.
- RecapPlanner processes the COMPLETE source in 240-second chapters. Movie chapters use a low-resolution 1fps video plus audio; nonmovie chapters use audio transcription. Each validated catalog chapter checkpoints in MediaRun.artifacts. A full-story model call then chooses bounded absolute source ranges and narration/subtitle text. This is actual scene selection, not a renamed full-length dub. Provider model routing, credential snapshots and 429 handling stay in GeminiClient/ProviderSettings.
- Interrupted chapter encodes use partial files and only rename after success. Completed plan/translations are reused on retry. Renderer caches successful per-scene encoded clips with content signatures, in addition to existing per-line TTS caching.
- Movie renderer includes only selected scene ranges, omits original gaps/tail, and uses the reviewed scene order. Actual output length follows voice length, planned duration and maximum video speed; target length is not promised exact. Translate mode retains source gaps/tail. Subtitle captions are split into bounded chunks for long narration.
- Narration language/delivery/dictionary are included in voice cache identity. Pitch uses asetrate/aresample plus chained atempo, preserving the selected speech speed. Separate subtitle text is used for SRT/ASS; narration text remains independent.
- Published recap.ass is registered in storage and served through the owned styledSubtitles file route only when output metadata confirms it exists. It follows the same private R2/file access and storage accounting as other outputs.
- Preview kind rewrite extends the existing queue; voice/video preview and scene-edit entitlements are enforced on the server. UI shows the current package/platform upload limits instead of advertising only global limits.

### Business rules and configuration preserved

- Usage remains charged by SOURCE duration for a successful export, with the existing one-project video counting rule, reserve/settle/release transactions and preview behavior. A 2-hour source summarized to 10 minutes is NOT silently billed as only 10 minutes. The UI explicitly labels estimated source usage.
- Do not raise live package entitlements, prices or the R2 budget merely to make a demo pass. No live account/payment/package rows or secrets were edited during this change.
- Storage settings now allow a source-duration ceiling up to 86400 seconds and provide long-duration choices. This does NOT change the saved policy or any customer's package automatically. Long movies need BOTH an adequate saved platform duration/file-size policy AND an adequate current package. Existing accepted uploads keep their captured storage policy.
- Default source upload size ceiling remains 2 GiB. R2 is storage; processing still runs in the application's worker runtime.
- Padauk is the bundled Myanmar caption font. Arial/Tahoma choices require those installed font files (the Windows runtime resolves them from Windows Fonts). Do not advertise CJK/font fallback quality as verified without testing the chosen deployment fonts.

### Verification and practical limits

- PHPUnit tests use SQLite :memory:, fake storage and factory accounts; no production migrations, reseeding or financial writes were used.
- RecapStudioTest covers ownership, optimistic conflicts, invalid source ranges, no-empty selection, chronological translation, story invalidation vs voice-only settings, model timestamp rejection, complete 3-chapter catalog/resume, package permissions/limits, real FFmpeg selected-scene output, ASS generation, clip/TTS reuse and pitch/speed extremes.
- Regression runs include MediaProcessingTest, MediaCreatorToolsTest, MediaUploadTest, ProjectDefaultsTest, UsageLedgerTest, AutomaticMediaWorkerTest, StorageSettingsTest and PackageFeatureAccessTest. The old StorageSettingsTest 5000-second rejection was intentionally updated: long movie ceilings are now allowed, values above 86400 still reject. A new test confirms raising the duration does not change previously accepted project policies.
- TypeScript and production Vite builds passed. Browser fixture QA checked source/review/render, all render settings tabs, switching Narrated/Subtitles, dark/light and my/en/th at 320/390/1280/1672 widths. No horizontal page overflow or React runtime exception was observed after the hidden file-input width fix. The browser fixtures were isolated test accounts, not real customer records.
- A real API call using the saved provider configuration successfully read an inline 2-second synthetic blue video and returned the correct color. This validates current provider/video transport; it is NOT a quality test on a real feature film.
- Full-length real-film narrative accuracy, target-duration closeness, per-language pronunciation, long CPU workloads and production-scale concurrency still require representative media acceptance testing. Do not claim 100% real-film quality or notebook equivalence from synthetic tests. The original Colab notebook engine was not newly imported in this checkpoint; the implementation extends the existing Gemini-based web pipeline.

Final verification: the broad regression run passed 114 tests / 950 assertions; the final narrowed RecapStudioTest rerun passed 11 tests / 72 assertions after additional timestamp and clip-cache checks. Pint passed and npm run build passed. No full-length feature-film sample was available for an end-to-end quality comparison.


## Latest checkpoint — 2026-11-15: real subtitle timing fix and temporary local testing

This latest user decision temporarily overrides the normal package enforcement described above. The user wants to test the new long/short-video workflows before redesigning package features.

- App\Support\StudioTesting::enabled() is true ONLY in Laravel's local environment when media.studio_testing is enabled (default true; optional MEDIA_STUDIO_TESTING=false disables it). It cannot be enabled by a customer/request parameter or in production by this flag alone. No live package, payment, subscription, credential or saved storage-policy rows were rewritten.
- Local testing bypasses package features (including scene edit/preview), subscription-period requirements, per-package duration/upload/storage allowances, processing/video limits and package queue/concurrency checks. Existing account-active/session/ownership checks, input validation, source verification, actual runtime readiness, global per-file size, local free-disk checks, private R2 access and application storage budget stay enforced.
- Effective local source-duration ceiling is 86400 seconds (24 hours) for inspection/import and new upload snapshots. StorageSettings::policy() still returns the actual Admin policy; limits() applies the temporary duration override. Global upload bytes/budget are unchanged. The Studio displays a localized testing notice and does not show a misleading package balance.
- Usage continues to record actual source-time reserve/settle/release exactly once; it is not untracked or a payment operation. Disabling local testing restores normal package checks without erasing consumed history. Do not delete financial/usage records to reset a test.
- Root cause reproduced with explicit user approval on the reported source: actual duration approximately 171.642 seconds, but Gemini's one-chapter transcription returned a last range approximately 172.96–178.60 seconds. HTTP/JSON succeeded; strict source-bound validation correctly rejected these invalid timestamps. No package rejection caused that screenshot.
- GeminiClient::transcribe() now accepts the measured chapter duration and explicitly requests relative seconds within that exact boundary.
- RecapPlanner now transcribes audio workflows in 60-second chapters; visual movie catalog chapters stay 240 seconds. Invalid response/timing is retried twice on that chapter, then subdivided recursively (bounded depth 2; stop splitting at 15 seconds or less). Valid dialogue is never silently dropped or scaled to make invented timing pass. 429/cancellation and other failures retain existing handling.
- Encoded chapter filenames include offset AND length, so an older invalid/cached 240-second chapter cannot be reused as a 60-second file. catalog_chunk_seconds preserves the boundaries of already checkpointed catalogs during resume. Completed chapters and plans remain resumable.
- Empty/failed review no longer claims the script is Saved; subtitle footer no longer repeats Source Video twice.
- The reported real failed subtitle project was retried and rendered successfully with the saved AI/R2 configuration: 3 validated chapters, 15 translated lines, MP4 + SRT, measured output approximately 171.642 seconds. R2 publication completed. No full-length real feature film has been verified; keep that distinction when describing long-movie readiness.
- New tests cover invalid timing retry, subdivision without dropped dialogue, chapter offsets/resume, local-only bypass, restoring package checks, continued source-time accounting, suspended-account rejection and physical storage enforcement.

Verification for this checkpoint: 30 processing/studio tests passed (226 assertions); the final expanded StudioTestingTest passed 7 tests (44 assertions). The other regression files passed in the preceding combined run; a test-fixture mass-assignment/CSRF setup issue was corrected in the local-mode tests. TypeScript/Vite build and Pint passed. The real approved source successfully produced verified video/subtitle objects in R2.

### Recap Studio layout follow-up — 2026-11-15
- Pending source limits and Remove now share one row, with Remove aligned to the right edge and wrapping helper text on mobile.
- Custom target duration uses an outer focus-within border/ring instead of a nested input outline.
- Scene Add/Up/Down controls stay at the bottom of the stretched scene panel; mobile keeps horizontal scenes and normal-flow controls.

## Latest checkpoint — 2026-11-15: Render workspace with live visual editing

- Render & Download now uses LiveRecapEditor (resources/js/components/live-recap-editor.tsx) and resources/css/live-recap-editor.css. It keeps the approved Iris/Mint themes and the existing sidebar/header. Preview occupies roughly two thirds of the desktop workspace; the right tools scroll independently with a hidden scrollbar. Project information is one compact expandable row. Mobile stacks tools below a sticky preview that respects the 104px small-screen header.
- Live Preview draws the authenticated ORIGINAL source video to a canvas. Aspect/fill (contain, crop, blur, pad), manual cover and subtitle styling update locally without calling AI or starting an export. Caption chunks and output dimensions follow MediaRenderer rules through resources/js/lib/recap-preview.ts. Captions use reviewed subtitle_text with narration fallback, respect included scenes and source times, and can be dragged vertically. Manual cover can also be dragged; position remains within output bounds. The bundled Padauk font and its OFL license are in public/fonts/padauk so browser captions can use the rendering font.
- Live mode is a source-based editing approximation: narration synthesis, voice delivery/speed/pitch, separation, ducking and final narration timing still require the existing asynchronous Listen to voice / Render Preview / final export. Do not claim browser caption layout, blur or source-scene playback is an exact FFmpeg/TTS result. No full dubbed intermediate is exposed or copied into R2 by this UI.
- Original mode provides comparison. Rendered mode plays the last actual completed video or newly completed rendered scene preview, without live overlays, preventing double-burned captions. Editing settings switches back to Live Preview. Downloads remain explicitly labeled as the last completed export, never a newly saved draft.
- Scene selection seeks to its source range, including Subtitles mode. Movie playback advances across included scenes in reviewed order and stops at the end without a repeated seek loop. Preview canvas work runs during playback and redraws paused frames on settings/seek/resize; no provider calls occur for sliders.
- Added safe-area guides, expanded preview with Escape/focus restoration, playback/seek/mute, bounded/coalesced settings Undo/Redo, and Reset Tab to the settings captured when entering Render. Browser unload protection still covers unsaved edits. New editor labels support my/en/th.
- Completed projects can Save Draft or Apply & Export directly. Apply & Export uses existing owned endpoints: reopen -> save scenes with the fresh revision -> save settings -> generate. Existing output remains available while the new run is queued. Back/Edit Script still opens actual script review. Existing provider snapshots, validation, private file routes, worker dispatch, usage reservations/charging and temporary local testing rules stay intact.
- Verification: RecapStudioTest passed 14 tests / 101 assertions, including completed-project reopen/save/generate preserving the old output and rejecting duplicate generation. Five Node built-in preview tests cover output geometry, time boundaries, excluded scenes and Unicode caption chunks (run with node --experimental-strip-types --test tests/Unit/recap-preview.test.mjs). Browser fixture checks covered my/en/th, light/dark, 320/390/768/1280/1672px, live caption color, Undo/Redo, portrait geometry, caption dragging, expanded view, full synthetic playback across scenes, rendered-to-live switching and the client re-export request sequence. No page width overflow or React exception was observed. Browser fixtures used synthetic video and mock requests, not customer records or live AI/payment actions. Real long-film generation was not rerun for this UI change.
Final verification for the live-editor checkpoint: TypeScript/Vite production build and Pint passed. The final browser rerun after the loading-state and mobile-header changes also reported no React exceptions or horizontal overflow.

## Latest checkpoint — 2026-11-16: editor controls and persistent customer queue

- User workspace headers now replace the theme toggle with CustomerQueue. Authenticated GET /media/queue returns only the current customer's queued/running/retry_waiting/stopping tasks, exact aggregate counts and the latest eight owner-safe workspace links. It never launches, consumes or cancels work. Completed/failed/cancelled tasks leave the active count. Import/validation returns to Source; script tools return to Review; voice/video previews return to Render.
- The queue badge refreshes every 3 seconds while active (10 seconds idle), on focus and after media mutations, with hidden-tab backoff and no overlapping requests. Failure shows unavailable/retrying rather than falsely reporting an empty queue. Its compact dropdown links back to work from any User workspace form. Server jobs continue after navigation; unmount cleanup aborts polling only. Existing automatic-worker dispatch, Admin queue/concurrency settings and package testing policy remain unchanged. Closing the PHP/worker host still stops server processing; this does not create an external always-on service.
- User workspace is presented in dark mode via the existing fixed-theme scope. Its theme button is hidden; Light theme code and Admin theme controls remain intact. User language trigger shows only the actual flag with no box border; the dropdown still has all three languages and keyboard behavior. Mobile header explicitly places these controls on the existing second row.
- Bottom/top subtitle margin displays exactly two decimals, including after dragging captions; underlying position precision is preserved. Other slider labels suppress floating-point noise. MP4, SRT and ASS download actions share the same height and alignment.
- Desktop Editing tools measures available viewport height above the existing footer, stays sticky, and stretches down to that footer gap. Heading/tabs remain fixed within the panel; settings alone scroll with a hidden scrollbar. Mobile retains stacked responsive flow. Undo/Redo use curved arrows; Reset has a recognizable icon, short label and the shared accessible action tooltip. Completed-export preview height accounts for downloads so actions remain above the footer.
- Matching current preview state is restored when returning to the workspace. Render preview requests persist dirty render settings before dispatch so returning does not lose those settings. Existing source-based live visual preview versus actual server-rendered output distinction remains unchanged.
- Validation: queue feature tests passed 6 tests / 34 assertions (ownership, suspended access, exact counts, terminal removal, correct stage links and navigation preserving queued work). Earlier combined studio/queue/automatic-worker regression passed 30 tests / 177 assertions before the final link refinement. Node preview tests passed 6 tests. Final TypeScript/Vite build passed. Synthetic browser fixtures checked 320/390/850/1280 widths, dark User rendering even with a saved Light preference, tool scroll sizing, tooltip, aligned downloads, queue navigation/return and badge removal on completion. No horizontal page overflow or React exception was observed. No live AI/customer media, email, financial, package or storage-setting writes were used for this checkpoint.

## Latest checkpoint — 2026-11-16: compact Review Script timeline editor

- Replaced the old scene-list / single-textarea / scene-tools grid with ReviewSceneEditor (resources/js/components/review-scene-editor.tsx and resources/css/review-scene-editor.css). Desktop puts the wider editable script table on the LEFT and a narrower video player on the RIGHT, with Video / Narration / Subtitle timeline tracks below. The existing header/sidebar and Iris/Mint palette remain. The upper panes fit the desktop viewport and scroll internally; mobile stacks video above the script table and retains a horizontally zoomable timeline. New labels support my/en/th.
- Each line now carries scene_id independently of its display index. Client initialisation assigns IDs to legacy lines; SaveRecapScenesRequest validates supplied IDs (bounded safe characters, distinct), and RecapStudioController preserves them or supplies UUIDs for legacy clients. Reorder moves the complete scene object: source range, script, subtitle, note, planned duration and lock state. Selection follows the ID. Browser playback synchronization explicitly preserves the selected scene after edit/reorder, rather than selecting whatever used to occupy the current playhead. Server scripts and active runs both retain the ID/notes after saving.
- Recap Preview is now the default Review player. Its own playhead/time display follows the included clips in reviewed order with gaps removed for movie recap. Original Source remains an explicit comparison/source-selection view. A 300-second source with a 60-second reviewed plan displays Source 05:00 and Recap 01:00, rather than exposing the native full-source duration as the recap duration. Seeking/crossing a clip boundary synchronizes the script selection. Subtitle/translation modes retain original timeline positions and gaps.
- Review playback is a browser clip-timing approximation with ORIGINAL source audio (muted initially), not synthesized narration or an already exported MP4. Timeline planned duration accounts for planned seconds and the source/max-video-speed floor; actual TTS length can extend the final export. Source-based review captions are bounded with the existing liveCaption helper. Final voice/style/timing still belongs to the existing Render stage. Do not claim exact-duration real-film acceptance based on this UI test.
- Added source-range editing, trim handles with live range feedback, drag reorder, explicit Up/Down controls, Split at playhead, Include/Exclude/Restore, scene locks, and coalesced bounded scene Undo/Redo. Splitting divides narration/subtitle text at grapheme-safe boundaries, splits timing and keeps contextual notes; it does not duplicate a full narration onto both halves. Locked scenes block edits/reordering across them. Nonmovie dialogue cannot be saved with overlapping/out-of-order ranges.
- Add Scene now opens a source picker; it requires a valid real source range and nonblank narration before inserting after the selected scene. It no longer appends the last one-second clip with placeholder Narration repeatedly. Replace Scene uses the same source picker and retains the scene identity. Both let the user use the source playhead for In/Out and play the selected range. Manual source context and optional notes/subtitle can be supplied.
- Fit clip timing to target previews/asks to apply an unlocked-clip timing adjustment. It trims source ranges as needed and allocates planned duration while preserving notes/script and locked scenes. It reports impossible locked-scene targets. This is not a silent AI rewrite: long narration may still need Shorten, and the UI explains that actual voice length can change duration.
- AI Tools is a compact outside-click/Escape dismissible menu (Regenerate, Improve Hook, Shorten, Expand, Check Names, Name Dictionary). Existing queued rewrite endpoints/providers are reused. Suggestions require explicit Apply, stay attached to the requested scene ID, reject stale text/locked scenes, and can be undone. Subtitle-mode rewrites request the actual subtitle output language. No live provider calls were used to validate this change.
- Review Save Draft, AI tools and Continue now persist review dictionary/settings alongside scene data. Existing ownership/revision checks, asynchronous worker/queue, billing/usage rules, Admin policies, private source routes and the current local package-testing override remain intact.
- Verification: 33 focused Studio/scene identity/creator-tool/queue tests passed (238 assertions); the final expanded ReviewSceneIdentityTest passed 4 tests / 22 assertions, adding a mocked rewrite-language/identity assertion. Seven Node scene-operation tests plus the existing six preview tests cover note identity, 5-minute-to-1-minute timeline mapping, source gaps, Unicode split, lock boundaries, fit/trim limits and caption/geometry behavior. TypeScript/Vite production build and Pint passed. Isolated browser fixtures with synthetic media/mocked requests verified playback across cuts, pointer trim, drag reorder, note editing and switching/reordering, Undo, split, Add validation/insertion, AI Apply/Undo, Save Draft and Continue to Render. At 320/390/850/1280/1672px in my/en/th there was no horizontal page overflow or React runtime exception. No live customer/financial/storage/provider rows were changed and no real-film generation was rerun.

## Latest checkpoint — 2026-11-16: Review space, scene thumbnails and track switches

- The User Review step no longer displays the local testing notice. This is visual only: StudioTesting, Admin settings, ownership, storage checks, queue dispatch and allowance accounting retain their existing behavior.
- Back / Save Draft / Continue are compact actions above Review. The old bottom footer and duplicate saving row are omitted in this step. Review measures the remaining desktop viewport, gives the upper script/preview panes the flexible space, and anchors a 225px timeline at the bottom. The timeline was not made shorter. Mobile retains stacked, scrollable flow.
- Desktop script rows use one compact narration textarea with start/end side by side. Notes, translated subtitle text and source context open through the per-row note icon instead of expanding every selected scene automatically. Scene IDs, selection, text and notes still move together; Undo/Redo remain available.
- The preview panel uses a small mode header, then a 5:2 height split between video and the compact transport/scene/timing section. Long explanatory copy moved to an information tooltip. It remains an original-source clip timing preview, not synthesized narration; actual narration timing still requires Render Preview/final export.
- Video timeline clips show actual source frame thumbnails. use-scene-thumbnails.ts uses one sequential off-DOM video decoder, lazy IntersectionObserver requests for visible/nearby clips, bounded source cache and cleanup on source/scene changes or unmount. Thumbnails stay browser-local; no AI, uploads, R2 writes or database changes are used to generate them.
- MediaLine JSON now supports optional boolean audio_muted and subtitle_hidden. Each Audio/Narration clip has a speaker switch; each Subtitle clip has a visibility switch, with shared action tooltips. Track labels provide Mute/Unmute all and Hide/Show all. Switches are one undoable edit and persist through Save/Continue, revision validation, reload and scene reordering/splitting. Locks protect script/timing but allow these explicit mixing/visibility switches.
- Both Review and Render live previews honor the flags. Original Source comparison and an already rendered output remain original/actual comparisons rather than receiving draft mute effects. The separate headphones control only monitors source audio locally.
- MediaRenderer honors the switches in actual exports. Subtitle mode silences selected source intervals. Dub mode silences the scene's voice AND separated background while preserving measured voice/clip timing; TTS caching/timing still runs. If all included scenes are muted, source gaps are silent too. Hidden captions are omitted from burned ASS, ASS downloads and SRT. All-hidden subtitles produce a valid empty SRT and a valid ASS header with no Dialogue events, without a false render-verification failure. Existing output files remain unchanged until a new export.
- Verification: 36 focused PHP tests passed / 268 assertions (Studio, scene identity, creator tools and customer queue). Real FFmpeg tests used synthetic video/audio and mocked provider/separation inputs to verify silence versus retained audio, whole-track gap silence, hidden captions, all-hidden SRT/ASS and preserved output duration in both subtitle and dub modes. Fourteen Node scene/preview tests passed. Pint and the TypeScript/Vite production build passed.
- Isolated browser fixtures verified real frame thumbnails, per-clip/all-track switches, saved flags, notes following reorder, recap playback across cuts, and Save/Continue. Layout checks at 320/390/850/1280/1672px in my/en/th found no horizontal page overflow or React exception. On desktop the timeline ends 12px above the viewport bottom and the video/details split measured 5:2. No real customer/payment/package/provider/storage records were changed; no live AI call or real-film generation was rerun.
## Latest checkpoint — 2026-11-16: taller, naturally scrolling Review

- Latest user decision supersedes the previous viewport-fit Review sizing: do not squeeze all Review content and Timeline into one PC screen. The desktop Review now grows in normal document flow and permits page scrolling; Timeline stays below the upper panes at its existing 225px height.
- Upper pane height is measured from approximately the first ten narration rows plus toolbar/table headers (600px minimum, 1100px practical ceiling). The adjacent preview grows with it and retains the existing 5:2 video/details split. Mobile keeps stacked preview and an independently scrollable script table.
- Narration uses an auto-growing controlled textarea with 10px type and Myanmar/Thai-friendly line spacing. Full multiline content is displayed rather than clipped; editing, width changes and font loading trigger remeasurement. This changes presentation only, not scene text, IDs, save/reorder behavior or provider settings.
- Replaced combined Source range heading/cell with separately aligned Start and End columns. Both retain their existing source-second validation and trim handlers; Output remains separate for movie workflows.
- Fixed Timeline Audio/Subtitle icon and label overlap: tooltip anchors reserve an explicit 16px flex slot, speaker/visibility icons are 10px, and labels are 8px with ellipsis only after the icon. Existing per-scene/all-track mute/visibility persistence and export logic are unchanged.
- Validation: TypeScript/Vite production build passed. Isolated synthetic browser checks at 1905/1280/850/390/320px in en/my/th found zero clipped narration textareas, zero timeline icon/text overlaps, no horizontal page overflow and no React exceptions. Desktop table height accommodated ten multiline rows; page scrolling reached the Timeline. Editing four lines grew the field, and Save retained text plus mute/visibility flags. No live media/provider/financial/package changes were used or needed.
## Latest checkpoint — 2026-11-16: thin subtitle-cover blur render failure

- Diagnosed a reported Generate failure with invalid_media at the final render stage. Source video, generated voice/scene clips, concatenated dubbed video and subtitles were already valid. The fixed boxblur=8:2 on a 32px-high cover band failed because the yuv420 chroma plane permits a radius strictly below 8. The generic invalid_media message incorrectly suggested choosing another source file.
- MediaRenderer::blurFilter() now bounds luma and chroma radii independently using their actual plane dimensions, including the zero-radius case for a two-pixel cover. Both manual subtitle-cover blur and aspect-fill background blur use this dimension-aware filter. Selected cover placement/style and story/voice/package/usage settings were not changed.
- Verification: 26 focused Studio/creator-tool tests passed / 225 assertions; Pint passed. New real FFmpeg regression covers Smart blur / Blur strip at narrow and two-pixel heights with burned subtitles and no provider requests. A local final encode of the reported cached dubbed source also succeeded: 185.1 seconds, with video and audio streams. This diagnostic made no AI calls, R2 uploads, queue retries or project/usage/financial writes. Its temporary output was removed after validation. The failed project retains its state until the user selects Retry through the normal workflow.
### Review/render completion indicator — 2026-11-16
- The final Render & Download workflow step now displays the same completed checkmark as prior steps when project.status is completed. It no longer retains the purple step-3 number after a successful final export. Completion follows the server project status, not local visual-preview success; queued/running/failed re-exports do not claim completion. The current step remains exposed with aria-current. TypeScript/Vite production build verified.
## Latest checkpoint — 2026-11-16: measured background progress

- Recap Studio now uses a shared compact `RecapWorkProgress` display for source upload/import/validation, queued analysis/generation and previews across all three steps. It shows a numeric percentage, real determinate bar, localized stage and downloaded bytes where available. Processing stages also show their own completion percentages. Active runs remain visible even when opening their source route. Queue header progress follows the same backend state.
- Added `media_projects.source_progress` (additive migration `2026_11_16_072632_add_source_progress_to_media_projects`, applied locally). YouTube metadata/download use matching format selection; downloader combines actual per-stream byte/total telemetry, keeps progress monotonic and caps active downloads below completion. Import reserves 5–75% for download, 75–90% for measured remux/transcode and the remainder for validation/publication. A retry resets the new attempt to zero. Never calculate download percentage from `expected_bytes`, which is a storage reservation rather than actual video size.
- `MediaRuntime::execute` has optional progress/duration arguments: opted-in FFmpeg work parses encoded timestamps, throttles updates, retains normal/binary stdout and cancellation/timeouts, and reports 100 only after successful process exit. Renderer reports completed voice lines, cached/new scene clips, concatenation and final encode. Analysis reports actual chapter and translation-batch checkpoints; validation reports completed file/probe/decode/poster/publication checks. Preview video uses weighted stage completion rather than incrementing progress on every callback.
- Run progress is monotonic within a phase, retains checkpoint artifacts and stays <=99 until the successful terminal save (analysis review-ready or final export completed). UI percentages describe completed work, not elapsed-time estimates: opaque AI/separation calls can hold at their last checkpoint, and polling can skip integers. No timer-based fake 1%-per-second increments were introduced.
- Verification: 67 focused tests / 620 assertions passed, including source ownership/cancel/retry, measured run-to-queue progress, active completion caps, real synthetic FFmpeg intermediate progress/binary stdout and encoding failure never reporting 100. Pint and TypeScript/Vite production build passed. Isolated browser checks covered import/validation/render at 1672/390/320px in en/my/th (27 combinations), with correct numeric/bar values, no horizontal overflow or React exceptions; desktop/mobile screenshots visually checked. No customer media was sent to AI, no real generation/import was launched and no package/payment/provider settings were changed. Existing already-running PHP worker processes finish with the code loaded when they started; subsequent worker processes load this update.
### Progress header layout correction — 2026-11-16
- Scoped CSS fixes the legacy `.recap-progress > div:first-child` flex rule overriding the shared progress grid. The run label, percentage and Cancel now share a readable header row; the full-width bar and hint sit below. Phone layouts preserve whole words and compact controls. Only run-progress presentation changed; progress calculations, queue jobs, source/import UI and other forms were preserved.
- Verified import/validation/render fixtures at 1672/390/320px in en/my/th, with no horizontal overflow or React errors; visually inspected run-progress desktop/mobile screenshots. TypeScript/Vite build passed.
## Latest checkpoint — 2026-11-16: faster Review Script analysis

- Scope: Step 2 analysis latency, not R2 publication or final video encoding. Real diagnostics found both sequential chapter requests and repeated invalid visual timestamps (e.g. a 240-second chapter described past 360–400 seconds), causing repeated validation retries/subdivision. Preserve complete source coverage, original-language dialogue, silent visual events and the final whole-story synthesis; do not replace this with opening-only sampling or truncate invalid scenes into range.
- `RecapPlanner` now analyzes independent chapters in bounded concurrent waves (default 3, capped at 4), immediately persists each validated chapter, and numerically orders the complete catalog before whole-film narration. Failed waves drain other in-flight successes before surfacing the failure. Retry/resume requests only missing chapters. Subtitle translation batches use the same concurrency and checkpoint behavior, retain complete-story context and validate every original line index; an incomplete batch is never skipped just because its last line exists.
- `GeminiClient` keeps synchronous public wrappers for existing callers and adds asynchronous structured/transcription/translation methods. Requests share a curl handler and explicitly build Laravel's lazy promises before waiting; merely wrapping the old calls in promises is sequential on this installed framework. Preserve saved provider revision/model priorities, authentication-only credential fallback and provider Retry-After cooldowns. Cancellation/deadline causes survive curl wrapping and are not reported as provider outages. Analysis requests are capped at 180 seconds; other callers retain their saved provider timeout.
- Visual analysis proxies include a small T+Ns clock for reliable clip-relative timing, under versioned `chapter-clock-v1-*` filenames. This clock is analysis-only and never added to source or customer exports. Existing plain proxies can be reused to build clock proxies with their audio copied. New proxy filters sample at 1 fps before scaling rather than scaling every discarded frame; the previous 512-pixel width, 1 fps coverage and audio analysis settings remain. Source files are not modified.
- Timestamp schemas carry rounded clip-duration numeric bounds, and application validation still rejects invalid ranges, text and array cardinality. Avoid adding large nested maxItems constraints to these numeric schemas: a live test confirmed Gemini's schema-state-complexity HTTP 400 with that combination. Invalid timing still retries/subdivides instead of dropping dialogue or fabricating timestamps.
- Long analyses cooperatively yield after 600 seconds using `MediaAnalysisDeferred`, keep their checkpoints/reservation and release the same queue job to resume automatically. This is not a failed attempt, does not grant a result prematurely, and avoids the previous 30-minute worker-supervisor termination window. Transient analysis failures count consecutive failures without checkpoint progress (three attempts), while a newly completed chapter/batch refreshes that budget. Explicit Retry clears that failure budget while preserving completed work. Package policy, charging, automatic worker setup and leaving the page during work remain unchanged.
- Internal defaults live in `config/media.php`: analysis_concurrency=3, analysis_request_timeout=180, analysis_slice_seconds=600. No provider credentials, models, package limits or Admin settings were changed for this task. Per-chapter elapsed seconds/concurrency are saved in run artifacts for diagnostics without prompts or secrets.
- Validation: affected Studio/provider/media/progress/queue/usage/worker regressions passed 114 tests / 819 assertions; the additional stalled-retry-budget regression passed 1 test / 13 assertions. Tests include genuine concurrent local HTTP (peak three simultaneous requests), complete 22-chapter ordering/coverage, failed-chapter-only resume, quota cooldown, live in-flight cancellation, cooperative yielding without charging, partial subtitle batches, and a real FFmpeg clock proxy preserving source hash, duration, audio and 1 fps frames. Pint passed. No frontend changes were needed in this checkpoint.
- Authorized live benchmark: an existing 5,137.229-second movie (85m37s) completed all 22 chapters plus whole-story synthesis in 333.46 seconds (5m33s), producing 9 scenes with a 180-second plan, with no job retries or continuations. This reused existing low-resolution analysis proxies; first-time source preparation adds time and provider latency/quota can vary. It is not a promise that every new upload finishes in five minutes. Separate cold-proxy measurement on the same 240-second source segment: previous filter 16.32s, updated timestamped filter 9.92s; both preserved 240 frames / 240 seconds / 512x288.
- The live benchmark used an in-memory run with database saves disabled and a private temporary directory. It did not alter customer project results, payments, subscriptions or usage; temporary copies were removed. The timing observations establish the speed improvement, not a formal semantic quality score or an exported-video benchmark.
## Latest checkpoint — 2026-11-16: background mix, localized voices and continuous scene narration

- Latest request: include separated original background music/SFX when selected; show friendly narrator names and gender in the three interface locales; remove the long silent tails between narrated scenes, including when speech speed is increased.
- Root causes: Studio displayed its default audio_track=mix while keep_background=false, so the selector could promise a mix while the renderer correctly omitted background. The renderer also used apad to fill short TTS lines to their scene duration, producing audible gaps before the next scene.
- Studio now synchronizes the background switch and Output audio selector in both directions. Selecting Narration + background mix enables background; choosing Narration only disables it. Old saved contradictory settings are presented honestly as narration-only when the switch is off. Explicit localized status copy explains what the next export includes. Original speech is still separated before mixing; never substitute the original dialogue track when separation fails. Subtitles-only continues to keep original audio. Live visual preview still uses source audio; Render Preview/final export checks the actual voice/background mix.
- Narrator labels use resources/js/lib/media-voices.ts and the current interface locale (my/en/th), not the narration language. Localized aliases include Kore=မြမြ (မ), Charon=သီဟ (ကျား), Fenrir=ရဲမင်း (ကျား), Aoede=သဇင် (မ), Orus=အောင်မင်း (ကျား), Leda=နီလာ (မ), with English/Thai equivalents. These are presentation aliases, not new/cloned voices. Existing provider IDs remain unchanged in saved settings and API requests. Gender mapping follows Google's Gemini-TTS voice list: https://docs.cloud.google.com/text-to-speech/docs/gemini-tts . The compact project summary uses the same labels; Admin provider configuration is unchanged.
- MediaVoices::prepareScene() creates a reusable local WAV with only leading/trailing synthesis silence removed, preserving internal natural speech pauses and the original cached TTS file. MediaRenderer now fills the entire spoken scene with pitch-preserving time fitting instead of seconds of silent padding. Installed FFmpeg uses Rubber Band with formant preservation; builds without it (or extreme ratios outside its accepted range) fall back to chained atempo with the selected pitch. Tiny final sample padding is bounded by the exact scene endpoint. Existing scene/all-track intentional mute flags are preserved.
- Scene duration still preserves all narration: a voice longer than the movie's planned scene extends that scene/video/subtitles together rather than cutting words. A shorter voice spans its planned scene even when a faster nominal speed was selected; the UI explains that scene fitting can adjust the effective pace. Translation mode retains source scene duration and deliberate source gaps. Extreme differences between text length and scene time can sound stretched; this is not a guarantee of natural speech at arbitrary ratios. No script text is fabricated or automatically rewritten to hide those differences.
- Timing reports use the same cleaned-voice measurement and scene-duration policy. Versioned renderer piece signatures and preview request cache keys prevent a new export/preview from reusing old silence-padded clips. Raw provider voice caching remains available. Existing published customer results are not silently overwritten: Apply & Export/Render Preview produces the updated audio.
- Validation: five new real FFmpeg regressions passed (57 assertions): 5-second scenes at 0.5x/1x/2x with audible speech at their tails and across the cut, unchanged pitch, MP4/WAV instrumental mix with and without ducking and no original-dialogue tone, internal pauses preserved, raw cache unchanged, all-silent TTS rejected, longer speech retained and pitch-preserving fallback. Seventeen Node tests passed, including localized labels/stable IDs and mix-toggle synchronization. Existing Studio/creator tests passed (26/225); the combined media/render/progress suite passed 46 other cases and exposed one prior exact-millisecond assertion, corrected to allow sub-frame edge trimming; that corrected test passed (1/12). Pint and the production TypeScript/Vite build passed.
- Installed real UVR readiness also passed; running the actual local Python/ONNX engine on a synthetic six-second music sample produced a valid 44.1kHz stereo six-second instrumental stem. Worker-equivalent FFmpeg PATH is required by the Python package and is already supplied by MediaBackground. This synthetic smoke check is not a soundtrack-separation quality benchmark for every movie.
- Isolated browser checks at 1672/390/320px in en/my/th verified all six narrator options, mix selection actually enabling the background switch, correct status text, no horizontal page/control overflow and no React exceptions; desktop/mobile screenshots inspected. No customer data, provider/model/secret settings, packages, payment/subscription/usage records or source media were modified; no customer media was sent to AI for this task.
- Final verification after the fitting fallback guard: MediaNarrationContinuityTest + MediaProcessingTest passed together, 16 tests / 154 assertions. The prior creator/Studio/progress results and 17 Node/browser checks remain applicable; no further frontend code changed after the successful production build.

## Latest checkpoint — 2026-11-17: unclipped dropdowns and verified project deletion feedback

- Shared SelectField now renders its list in the browser top layer using a manual popover. It escapes the Editing tools scroll panel and native dialog clipping while preserving its existing theme and hidden scrollbar. The list follows the trigger on page/panel scroll and resize, flips above when space below is limited, constrains its size to the visible viewport, and closes if its trigger scrolls completely out of its clipping ancestor. Outside click, keyboard arrows/Home/End/Enter/Escape, disabled state and focus restoration are retained; choosing an option does not scroll the whole page. No new dependency was installed.
- Redesigned All Projects -> Delete Project confirmation: compact responsive 480px dialog, aligned trash icon/title/close header, readable project-name row, clear irreversible-delete description, grouped Cancel/Delete actions and enter/exit motion. Only real pending deletion disables the action buttons.
- ToastProvider supports an optional success(message, {placement:'center'}) argument. Project deletion uses this reusable centered, themed success toast with an animated check icon, countdown and approximately three-second automatic dismissal/exit animation. Existing forms' corner success/error toasts remain the default. Reduced-motion preference is respected. A deletion success no longer leaves a persistent inline success banner in the library.
- Backend deletion already targets the project's captured storage disk/profile and the complete media/<project-id>/ prefix, including input, outputs, previews, voice/background caches and unregistered cached artifacts. MediaStorage::discard now explicitly checks deleteDirectory return values as well as directory absence on remote and local storage. Failure returns 503 and preserves the project record instead of claiming success; owner scoping, confirmation requirement, busy-job/preview guard and storage-ledger cleanup remain.
- Verification: MediaUploadTest passed 14 tests / 160 assertions, including owned R2+local prefix removal, other-customer files preserved, unauthorized deletion blocked and failed remote delete retaining local files/database record. ProjectLibraryTest passed 8 tests / 214 assertions; its optional PROJECT_LIBRARY_UI_CAPTURE fixture uses only factory records. Pint and TypeScript/Vite production build passed.
- Isolated browser checks verified dropdown visibility/hit testing outside a deliberately clipped tools panel at 1672/390/320px in en/my/th, without horizontal overflow. Floating selectors worked inside a native modal; Escape closed the list and kept the parent dialog open. Desktop/mobile deletion dialogs had aligned icons and no overflow; actual frontend deletion success produced a toast centered in the viewport and dismissed it automatically. Screenshots inspected; zero React exceptions. Browser deletion endpoints were mocked and storage tests used fake disks: no live customer project or real R2 object was deleted during verification.

## Latest checkpoint — 2026-11-17: illustrated project confirmation and final-export success

- Supersedes the preceding project-deletion confirmation/center-toast design. `ProjectFeedbackDialog` now provides three compact, responsive, animated native-modal variants with an 11px full-screen blurred backdrop, dark glass styling and English/Myanmar/Thai copy. Other forms' generic dialogs and corner toasts are unchanged. Shared `MediaDialog` accepts an optional descriptionId for accessible descriptive text.
- All Projects deletion uses the pink trash/video illustration, Move to Trash? heading, named project and explicit permanent/irreversible source/script/output/cache deletion warning, Cancel and red Move to Trash actions. This remains PERMANENT deletion; there is no recoverable trash feature. Only an actual pending request disables confirmation actions. Failed deletion keeps the dialog open with its error and retryable action.
- Confirmed deletion opens the green trash/check success modal with exact English text requested: Project Delete Successfully! / The project has been permanently deleted from your workspace. There is one visible OK button. It stays until dismissed; no three-second toast replaces it. The existing verified captured-profile local/R2 deletion path is retained.
- Recap Studio watches real final generation completion (project completed + generate run completed + MP4 available), announces each observed run once, and opens the green video/check success modal. Initial loading of an old completed project, preview/analysis completion, retained old output during rendering, failed output and audio/SRT-only exports do not falsely announce a generated video. The modal dismisses after three seconds once its illustration loads, with reduced-motion support. Watch Video selects the completed rendered output in the existing editor and attempts playback; Download Video uses the existing authorized download endpoint. Regular output download buttons remain available after auto-dismissal.
- Assets live in public/images/project-trash-confirm.png, public/images/video-export-success.png and public/images/project-delete-success.png; no R2 hosting. The user had no local source PNG files. Built-in imagegen was used to create faithful reference-derived transparent PNG variants from the supplied artwork, rather than claiming byte-identical copies. Prompt set: (1) isolate/reproduce only the pink 3D trash basket, purple video card and curved arrow; (2) isolate/reproduce the mint 3D clapperboard, play triangle, check and sparkles; (3) isolate/reproduce the emerald 3D trash basket, lifted lid, check and sparkles; all centered, transparent, no words/UI/people. Original generated outputs remain in the tool's generated_images directory; the application references only its public/images copies.
- Main source map: resources/js/components/project-feedback-dialog.tsx, resources/css/project-feedback.css, resources/js/lib/recap-completion.ts, resources/js/components/recap-studio.tsx, resources/js/components/live-recap-editor.tsx and resources/js/pages/projects/index.tsx. Superseded library-delete-modal CSS was removed.
- Validation so far: six focused Node completion regressions passed; ProjectLibraryTest + MediaUploadTest passed 22 tests / 374 assertions using fake storage; Studio fixture rendering test passed. TypeScript/Vite production build passed. Factory-only mock browser checks at 1672/390/320px in en/my/th confirmed confirmation/deletion layouts, loaded PNGs and blurred backdrop; deletion waits for OK. Final-export polling opened video success, auto-dismissed it, did not repeat it or announce an initially completed project, and Watch selected Rendered. No live customer project or R2 object was deleted, and no customer media was sent to AI for UI verification.
- Final follow-up verification: fixed the success card's decorative pseudo-element overflow at 320px; Myanmar heading words stay together and very narrow Myanmar video actions stack cleanly. English/Myanmar/Thai narrow-screen success checks passed with no horizontal dialog overflow. Mocked remote-deletion failure correctly retained confirmation/error and enabled retry without displaying a success modal; Escape closed confirmation. All isolated browser checks reported zero React exceptions. PNG metadata confirmed genuine alpha transparency. The final TypeScript/Vite build and focused completion tests are the final checks for this checkpoint.

## Latest checkpoint — 2026-11-17: Step 3 selected-scene background separation

- Confirmed root cause: the background-separation stage extracted and ran CPU UVR over an entire 94m31s source movie before rendering a three-minute movie recap. The observed run really failed with media_timeout after the 1,200-second separator subprocess limit; 0% previously did not distinguish active inference from a stopped process. This development PC has no NVIDIA CUDA GPU. Do not describe this as an R2 upload problem.
- MediaBackground::plan now merges only included source ranges for workflow=movie and maps original scene timestamps to a packed background WAV using offset(). Translation/full-length workflows retain full-source audio, including gaps. Source files and saved scripts are preserved. Overlapping/reordered/duplicate source scenes map correctly.
- Only selected audio is decoded, in bounded 60-second core chunks with six seconds of real source context at each edge. The same installed UVR-MDX-NET-Inst_HQ_3 model, stereo 44.1kHz processing and existing separation settings are retained. Context is trimmed after neural inference; verified core PCM WAV checkpoints are concatenated losslessly. No original-dialogue fallback, model downgrade, sparse audio sampling or subtitle/voice truncation was introduced.
- separate_audio.py loads the model once for all pending chunks. It emits actual MDX inference-window progress through RECAP_BACKGROUND messages; PHP persists monotonic separation progress. Input preparation, verified cache reuse and final concatenation also report measured work. Completed chunks survive engine failure/cancellation and retry processes only missing/invalid chunks. The compact completed background is published through the project's captured storage profile; interim core checkpoints are local. Deleting the project still clears its complete local/R2 media prefix.
- MediaRenderer obtains the background once and maps each scene's original source seek to packed audio time. Existing narration fitting, background ducking, pitch, volume and clip cache behavior remain; revised background identity invalidates incompatible old clip caches. SRT-only exports skip separation entirely.
- Validation: focused MediaBackgroundSelectionTest, MediaNarrationContinuityTest, MediaProcessingTest, RecapStudioTest and MediaProgressTest passed 42 tests / 352 assertions; one optional installed-engine smoke test skipped in the routine run. Separately the real installed UVR smoke passed in 51.246 seconds, processing two distinct context windows with one loaded model, exact four-second packed output, two verified checkpoints and real intermediate progress. Fake-engine orchestration tests cover edge context, correct source tones/mapping, cache reuse, cancellation and retry after the second chunk fails. Pint passed. No frontend changes were needed.
- Authorized live export verification is in progress. The failed generation was retried through MediaRunActions::retry and AutomaticMediaWorker::wake at 04:05:21 UTC, preserving the existing run/script/provider revision. Nine selected ranges contain 230 source seconds; with model context the neural workload is about 338 seconds instead of 5,671 seconds. Do not promise a universal 5–10 minute export; CPU, selected duration, provider quota/latency, voice generation and rendering still matter. Record the verified final outcome below when available.
- Live verification finished successfully at 04:17:31 UTC: same real 94m31s source -> 180.106-second final MP4 with both video and audio streams. Retry execution began at 04:05:23 UTC: total 12m08s (12m10s including wake), background separation 8m46s, voice preparation/clip assembly 2m35s, final encoding/verification about 37s, publish about 10s. MP4 (17,213,953 bytes), SRT and ASS were checked directly in the project's private R2 profile and all matched local byte sizes. No new error occurred and the existing run/project are completed at 100%. These are actual timings on this PC, not a universal runtime guarantee. Saved source, review script, provider configuration and package definitions were not replaced.

## Latest checkpoint — 2026-11-17: contained voice preview and flat Studio canvas

- Recap Studio's completed Listen to voice player now lives directly under its trigger inside the Voice tab, with a compact responsive player and close button. It is no longer rendered below the whole workflow, so it cannot appear in Source/Review or the export-processing screen. Starting/retrying generation or reopening analysis clears the stale preview; loading a saved project does not restore a previous voice sample. A requested sample auto-plays only once per preview ID, so switching tabs does not unexpectedly restart it.
- All three Studio steps use the same solid var(--background) canvas. The radial page background is suppressed only on a shell containing Recap Studio; other pages and existing button/selected-state accent colors remain unchanged. No backend job, API, storage or audio processing behavior changed.
- Verification: TypeScript checking and the Vite production build passed. The change is limited to Studio preview lifecycle/rendering and its page canvas; live media jobs and customer data were not modified for this UI fix.

## Latest checkpoint — 2026-11-17: fixed-duration narration and clearer Step 3 controls

- Owner decision: a movie recap keeps its selected target duration when speaking speed changes. Faster delivery should use a fuller factual script, and slower delivery a shorter script, instead of cancelling the selected speed with large audio stretching. This applies to narrated movie recaps; full-length translation/subtitle timing retains its existing behavior.
- New MediaNarrationFit service measures actual cached/synthesized TTS at the selected speech rate, expands/shortens supported narration, and measures again. It allocates the same total target across included scenes, respects locked scenes, preserves source ranges, scene IDs/notes and subtitle language, and skips intentionally muted narration. Rewrites use source-scene facts, original narration, dictionary and measured timing; instructions forbid invented events and filler. Up to three rewrites/four measurements per scene, maximum 120 included scenes, checkpointed candidates and existing TTS caches keep retries bounded and reusable. Impossible source-speed limits stop pointless rewrites.
- Studio -> Voice -> Adjust Script to Speed runs as an owned, cancellable queued preview (kind=fit), reports measured progress, and opens a before/after script review. Existing text stays unchanged until Apply script. Partial results can be applied for further manual editing; they do not claim ready timing. Locked scenes or insufficient factual material produce an explicit warning rather than fake silence or fabricated content. Closing/navigating away preserves the background job and its proposal; a matching result is restored on return. Whole-script fitting cache does not depend on the currently selected preview scene.
- New Studio settings opt into fit_script_to_speed=true. The server normalization default stays false for existing captured/legacy runs. For opted-in movie/dub exports, a completed ready fitting signature must match the applied text, timing, inclusion, voice/model/language and speed settings. Later narration/speed edits require fitting again; cosmetic subtitle/color/background-volume changes reuse the proof. Final rendering preserves planned duration, reuses fitted voice WAVs, and permits only a three-percent timing correction around the chosen speed. It refuses a mismatched old script instead of silently undoing the chosen rate. Natural pauses inside phrases are preserved. Exact script length cannot be guaranteed from insufficient source facts; the UI requires a corrected/ready fit before export.
- Step 3 now has compact scene-preview controls (planned duration with source range as secondary detail), a short Project details disclosure instead of the long title occupying the control row, aligned MP4/SRT/ASS downloads and a smaller footer. Listen to voice is a tooltip-equipped play button immediately to the right of the voice selector; its contained audio player and close/lifecycle rules remain.
- Subtitles tab now explicitly exposes Hide original subtitles, cover style and cover top/height controls with the existing live mask preview. This covers burned-in text using blur/dark box; it does not reconstruct hidden picture content. Video tab no longer duplicates these controls, and Reset tab follows their new location. Audio tab explicitly exposes Auto duck background during narration, explains quieter music during narration and restoration between phrases, and is unavailable until background mixing is enabled. Existing FFmpeg sidechain compression performs the ducking; no original-dialogue fallback was introduced.
- Main files: app/Services/Media/MediaNarrationFit.php; MediaToolsController; MediaProjectController; MediaPreviews; MediaRenderer; MediaVoices; MediaSettings; UpdateMediaSettingsRequest; recap-studio.tsx; live-recap-editor.tsx; recap-studio.css; media.ts; recap-i18n.ts; media-i18n.ts. No dependency, schema, credential or package-definition changes.
- Verification: narration fitting, continuity, creator tools, Studio and processing regressions passed (49 tests before the additional ducking case). Additional actual-FFmpeg ducking test verifies background amplitude rises between narration phrases. Synthetic 1.5x TTS expansion and 0.75x shortening each produce the fixed 30-second target, preserving reviewed text until Apply. Endpoint tests cover ownership, required apply, invalidation after manual edits/rate changes, generation after a matching apply, caching, cancellation and legacy/subtitle behavior. Final export reuses measured voice cache. Tests use fake storage and mocked AI responses; this checkpoint does not claim a live customer AI fitting run.
- TypeScript checking, Vite production build and Pint passed. Isolated factory-only browser checks at 1672/390/320px in English/Myanmar/Thai verified same-row voice selector/listen button, masking and ducking controls, matching download heights, proposal Apply and restoration after page reload, no horizontal overflow and zero React exceptions. Existing build-time unresolved asset warnings for the older admin background/Padauk font remain outside this change.

## Latest checkpoint — 2026-11-17: faster fitting, truthful retry UI, shared top navigation

- Diagnosed the reported 77% fitting wait as a real Gemini HTTP 429 with a 900-second Retry-After, not an R2/render error. Preview retries now preserve their previous percentage and real phase metadata, persist retryAt and expose a live countdown. Already-delayed database jobs also expose their available_at time. Rate limits/cooldowns are respected; no quota-bypass key rotation, invented percentage increments or failure disguised as completion.
- MediaNarrationFit now measures a round of scenes, rewrites only mismatches in bounded batches of three, and checkpoints every measured scene as well as candidate/attempt. Retries skip verified measurements and completed edits. Four rewrite attempts/five measurements remain bounded; only factual source-supported expansion/shortening is allowed. A failed/cancelled operation preserves the saved original and checkpoints.
- Automatic unlocked output slots may now be balanced against measured narration, maintaining the exact total target, unchanged source in/out cuts and scene identities, locked slots, max_video_speed and the existing three-percent speech-tempo correction bound. This is an explicit proposal reviewed before Apply, not an automatic change to saved customer scripts. If the overall narration is already close and a source-speed lower bound is the bottleneck, rewrite only those bottleneck scenes. No silence/filler, missing dialogue, model downgrade or large slowdown to cancel the selected speed. Infeasible results remain marked partial and Apply as draft & review returns to Step 2. Whole-fit request cache has a v2 discriminator; existing raw voice and scene measurement checkpoints are retained.
- Raw Gemini speech cache no longer includes speech_rate, because speed is applied locally and is not sent in the synthesis request. Compatible legacy local WAV identities are reused. A verified active media_files entry with matching project/disk/path/byte count avoids repeatedly uploading the same voice to R2. New or unverified voice files still use normal publication and verification.
- Project responses expose narrationFitReady. Studio compares narration-relevant settings and applied scenes against the saved project, retaining readiness across cosmetic edits/reloads but invalidating after narration/voice/speed/timing edits. Step 3 shows a clear Adjust reminder/action before Export. A completed un-applied proposal offers Review instead of encouraging another adjustment; server validation remains authoritative. Incomplete timing is explicitly shown as a draft rather than a final export-ready script.
- Removed the Studio form title and redundant Render & Download heading. All three steps share one top toolbar: steps on the left, compact Back/Save/Continue/Export actions on the right. Removed the bottom footer action row and the duplicate navigation inside ReviewSceneEditor. Small screens retain a three-column step indicator and wrapping top actions; the existing sidebar/header/theme remain.
- Live verification: the original delayed fit eventually completed as a partial proposal. After the optimization, cached measurements/synthesis were resumed and only the remaining bottleneck narration needed a new correction. The resulting proposal completed in 43 seconds, all 11 scenes ready, proposed slots summing to exactly 180 seconds with the selected 1.2x delivery retained within the existing tolerance. This is a cached resume measurement, NOT a guarantee that a fresh long-movie adjustment takes 43 seconds. Original saved script/output were not overwritten or exported automatically; the customer must review and Apply the new proposal.
- Validation: 54 affected PHPUnit tests / 469 assertions passed sequentially, including batching, stable IDs, exact duration/speed, measured checkpoint resume, 429 retry metadata, cache reuse, continuity, real synthetic FFmpeg audio, creator tools, Studio and processing. TypeScript and production Vite build passed; Pint passed. Isolated factory browser checks covered all three toolbars at 1672/390/320px and real retry countdown presentation, no bottom navigation/title or horizontal overflow, zero React exceptions. Additional en/my/th fitting-proposal Apply/restore and inline voice/subtitle/ducking checks passed at those widths. Existing build warnings for the older admin background and Padauk font remain outside this change. Browser mock pages intentionally do not load real customer videos.

## Latest checkpoint — 2026-11-17: approved Source & Style visual refresh

- Step 1 now uses a compact media card on the left and three creation choices on the right: Summarize a Long Movie (workflow movie / dub), Translate an Existing Recap (translate / dub), and Subtitles (subtitles / subs). Existing upload/import, validation, analysis, scene review, queue, settings and server authorization remain in use. No PHP processing logic or customer data was changed for this UI refresh.
- Keep the existing small three-step bar, header/sidebar, flat Studio canvas and Iris/Mint theme. Step 1 explicitly overrides the previous shared-action placement: its only Next: Review Script button is at the bottom right, calling the existing start/save/analyze operation. Steps 2 and 3 keep their existing top actions.
- Project title is plain text with a pencil edit control. Ready videos retain native playback and concise file metadata; Replace/Remove are compact and retain the existing confirmation/removal flow. Empty upload/YouTube, real upload/import progress, retry/cancel and source limits remain available.
- Eight narration styles have image-backed selection tiles. Language, target/custom duration, story order, spoiler preference, custom style and character focus still use the same saved settings. Story / translation instructions stay visible in all workflows; the compact Note action focuses that same field and shows an indicator when populated (not a separate unsaved note). Advanced retains the Name Dictionary editor. Movie-only narration controls hide for translate/subtitles as before.
- Main UI files: resources/js/components/recap-studio.tsx, resources/css/recap-source-style.css, resources/js/lib/recap-i18n.ts. New copy supports English, Burmese and Thai. Layout stacks on smaller screens; dropdowns continue using the existing accessible floating SelectField component. Custom duration uses an outer focus border.
- Permanent image assets are local public/images/narration-styles/{standard,storyteller,suspense,punchy,emotional,funny,documentary,custom}.jpg. Eight individually generated images from the built-in imagegen tool, optimized to 384x256 JPEG (about 168 KiB total). No R2 storage or runtime AI image requests. Originals remain in the Codex generated_images directory.
- Image prompt set: one standalone edge-to-edge landscape 3:2 premium cinematic illustration per style, midnight navy/iris purple palette, dark lower quarter for HTML label, no text, logos, UI or frame. Subjects: standard moonlit mountain river; storyteller luminous open book at sunset; suspense lone silhouette in cyan misty forest; punchy energetic city light trails; emotional glowing heart over sunset lake; funny gold comedy mask and confetti; documentary Earth over mountain horizon; custom prism with mint/rose aurora refractions.
- Validation: existing RecapStudioTest suite passed (16 tests / 140 assertions); owned-page capture check passed. TypeScript and production Vite build passed. Synthetic browser checks cover 1672/1280/850/390/320px in en/my/th: no horizontal overflow, all eight local images load, no React exceptions, title edit, workflow/language visibility, custom minutes, Note focus, empty-upload gating and YouTube tab. No real customer upload, Gemini generation or export was needed for this visual-only change. Existing build warnings for older admin background/Padauk paths remain outside this scope.

## Latest checkpoint — 2026-11-17: supplied style artwork and compact raised controls

- Replaced all eight local narration thumbnails with the exact images supplied by the user, in order: standard movie/clapperboard, storyteller book/fantasy, suspense corridor, punchy runner, emotional sunset portrait, funny village group, documentary camera/landscape, custom lettering. Saved as aspect-preserving 480px-wide JPEGs under public/images/narration-styles; a version query prevents browsers reusing the earlier generated thumbnails. No regeneration, R2 publication or remote artwork dependency.
- Source upload/media card now has transparent background, no border and a subtle raised shadow. Replace uses the existing swap-arrows icon. Choose what to create uses folder-play and Custom style uses sliders, removing star/sparkle icons from those labels.
- Removed the entire Advanced/Note row. The existing Name Dictionary editor is accessed through a small icon/text/count button beside the always-visible Story / translation instructions textarea. No name dictionary, instructions or other saved settings were removed.
- Workflow, target duration, story presentation and spoiler selected controls use inset highlights and raised shadows instead of colored borders. Story/spoiler choices fit their content and wrap rather than stretching across the entire available row.
- Removed the underline below the three-step toolbar. The current step circle is transparent with an Iris outline. Completed steps retain real completion checks and now use luminous pale green (#76ff9f) with a subtle glow. Existing queue, generation and success logic remain unchanged.
- Validation: production TypeScript/Vite build passed; existing owned Studio pages feature check passed (1 test, 6 assertions). Synthetic browser checks passed at 1672/1280/850/390/320px in en/my/th, including all eight supplied assets, no horizontal overflow, title and workflow changes, custom duration, inline dictionary modal, empty source and YouTube selection; zero React exceptions. Checked outlined/current and green/completed steps in Steps 2 and 3. Existing unrelated build warnings for older admin background and Padauk font remain.

### Source & Style final spacing refinement — 2026-11-17

- Selected creation, duration, story and spoiler buttons retain their raised shadows and now include a subtle Iris-tinted background for clearer selection. Language dropdowns are capped at 170px each on desktop and shrink to the available mobile width.
- Name Dictionary now sits in the instructions heading at the top right; the textarea uses the full width below it. Removed its former position beside the textarea. Other source controls, step states and processing behavior stay unchanged.
- Validation: TypeScript/production build and the owned-page feature check passed. Existing synthetic desktop/mobile checks passed in English, Burmese and Thai, including the dictionary editor and workflow selection, with no horizontal overflow or React exceptions.


## Latest checkpoint - 2026-11-17: compact workflow actions and immediate script review

- Studio top Back / Save Draft / Continue (and corresponding Step 3 actions) now use compact, content-width pill buttons. Source & Style Next uses the same rounded style without the previous 215px minimum or mobile full-width stretch. Existing labels, action placement and primary colors remain.
- Clicking Source & Style Next now immediately switches the visible workflow to Step 2 before awaiting settings save and analysis queue creation. The existing shared progress component shows saving at 0% while the request is pending, then actual queued/job progress. Empty/stale scene content is hidden during this transition. Analysis-route synchronization does not add a blocking navigation overlay. This does not make AI processing synchronous or claim a finished script.
- A save/start failure returns to Step 1 with existing input and field validation; the existing reanalysis confirmation is preserved. Invalid settings never start an analysis job. Backend queue, media processing, security and storage behavior are unchanged.
- Validation: production TypeScript/Vite build passed. Isolated browser checks passed at desktop 1672px and mobile 390/320px for English/Myanmar/Thai (18 layouts), compact pill sizes and no horizontal overflow. Delayed mock save/start requests verified Step 2 appears before either finishes, correct save-before-start order, validation recovery and retained reanalysis confirmation; zero React exceptions. No real customer job or provider call was made.


## Latest checkpoint - 2026-11-17: persistent personal Name Dictionary and compact feedback

- Name Dictionary now uses a compact localized editor with labeled numbered entries, optional pronunciation, small red pill Remove buttons, Add name/count and a fixed Done footer. Its body scrolls with hidden scrollbars on small screens; header/footer stay visible. Changes remain a modal draft until Done; invalid entries keep the dialog open and focus the field. Done saves immediately and shows a success toast.
- Added authenticated, CSRF-protected, media-actions-throttled PUT /media/name-dictionary. SaveNameDictionaryRequest validates up to 50 entries with 100-character fields. The nullable users.recap_name_dictionary JSON column stores personal defaults and is hidden from incidental User serialization. Migration 2026_11_17_143314 was applied to the local database without resetting existing data.
- A save updates the current owned, non-busy project's dictionary only, plus that account's defaults, in one transaction. Unauthorized project IDs and busy jobs cannot cause partial personal saves. Other project settings, scenes and captured/running jobs are preserved. New local uploads and YouTube imports inherit the saved dictionary; refresh and fresh Studio pages restore it. Older projects retain their own dictionaries until explicitly saved. Clearing every entry and pressing Done persists an empty dictionary. Accounts never share these defaults.
- Source & Style Remove/Replace now reuse the existing illustrated trash confirmation design. Wording explicitly says source removal resets this screen while the saved project/files remain in All Projects. Actual permanent project deletion and its existing local/R2 cleanup are unchanged.
- Shared illustrated deletion confirmation and success dialogs are slightly smaller (500px desktop maximum), including artwork, spacing and pill actions. Screen blur, video-success automatic three-second dismissal, and deletion-success OK behavior are retained. The common YouTube SVG now uses red body and white play triangle.
- Verification: focused persistence/ownership/validation/new-upload and new-import tests passed (7 tests, 67 assertions), and TypeScript/production build passed. Isolated browser checks covered 12 dictionary layouts at desktop/tablet/390px/320px in English/Myanmar/Thai, scrolling, Done validation, save/reload/new-video inheritance and clearing. Nine source-confirmation layouts, YouTube branding and the smaller automatic video-success modal passed with zero React exceptions. Browser requests used a local mock; PHP tests verified actual database behavior in isolated SQLite. No real media/provider job was started.

- Final regression validation: all 52 tests passed (692 assertions) across NameDictionaryTest, MediaUploadTest, YouTubeImportTest, RecapStudioTest and ProjectLibraryTest; Pint passed. Corrected the older library-deletion test fixture to include valid scene index/start/end values required by the existing narration-fit signature. No processing implementation was changed for that fixture correction.


## Latest checkpoint - 2026-11-17: Studio transfer and mobile state polish

- Removed the duplicate bottom action/loading line shown during redirects, script start and export submission. Keep the single shared top progress component; generate/retry/preview submission shows pending progress there until actual queued/run/preview status arrives. Immediate Step 2 transition and background processing are preserved.
- Local upload and validation replace the empty dropzone, Choose button and large generic spinner with a compact file-transfer panel showing file name, actual file size, acknowledged upload bytes and real percentage. useRecapSource captures selected file name/size immediately so the initial transfer UI no longer shows 0 KB / 0 KB. Only one progress indicator appears for that transfer. Existing chunk/resume, validation and YouTube import flows remain.
- At <=800px, the review narration table uses its content height with a 480px maximum scroll area. Short scripts no longer leave the former fixed 420px blank region; long scripts still scroll. Desktop paired script/preview height and timeline are preserved.
- Moved Your output is ready from the workflow toolbar to the export/download actions area. Completed clean output uses a compact mint check and label; older output while settings are dirty remains labeled Last completed export. Completed step checks remain in the toolbar.
- Mobile step connectors now align on a shared centered track with reserved spacing between the three markers/labels, including wrapped Myanmar and Thai text. Marker styling and step success logic are unchanged.
- Validation: TypeScript/production build passed. Isolated browser checks passed for all three steps at 1672/390/320px in English/Myanmar/Thai (27 layouts), no horizontal overflow, centered connectors and no repeated loading row. Short mobile scripts fit their actual table height (136px for two sample rows); long scripts retain scrolling; desktop keeps its paired pane height. Mock local upload verified immediate name/size, accurate 0%-50%-validation transitions, single progress panel and mobile fit. Redirect checks verified immediate Step 2 and save-before-start queue ordering. Completed ready status was checked beside downloads. Zero React exceptions; no customer media or provider generation was used.

- Mobile connector refinement: step groups now use flexible, normal-flow horizontal lines that fill the available gaps and stay vertically centered, rather than fixed 8px dashes. Desktop and step state behavior are unchanged. Production build and isolated browser checks passed at 607/390/320px in English/Myanmar/Thai, plus desktop regression checks, with no horizontal overflow or React exceptions.


## Latest checkpoint - 2026-11-17: mobile Review summary actions and centered preview

- At widths <=800px, Review Script renders the existing single Back / Save Draft / Continue navigation beside Scenes / Target / Planned / Saved, aligned to the right. Desktop keeps its workflow toolbar actions. At <=600px these three controls show compact icons with accessible text and descriptive titles so the summary and actions fit together. Resizing updates placement without duplicating buttons; existing save and transition handlers are reused. Pending analysis retains toolbar actions until the editor is available.
- Mobile review preview stage now explicitly spans its pane width and centers the contained video, removing the unused side region caused by its height/aspect-ratio sizing. Desktop paired pane sizing remains.
- Validation: TypeScript/Vite production build passed; isolated browser checks passed at 320/390/607/800/801/1672px in English/Myanmar/Thai (18 layouts), checking one action group, summary/right alignment, centered full-width video stage and no horizontal overflow or React exceptions. Browser fixtures use mock media; no customer/provider job was started.


## Latest checkpoint - 2026-11-18: mobile Review bottom-right actions

- Owner clarified that the requested bottom-right placement refers to Back / Save Draft / Continue, not the video. At <=800px the existing Review navigation now stays fixed at the viewport bottom-right in a compact themed pill dock with safe-area offsets. Added bottom content clearance so the timeline can scroll fully above the controls. Its single action group and existing handlers remain; desktop actions stay in the upper toolbar. Preview stays centered in its existing pane. This supersedes the mobile summary-row action placement above.
- Validation: TypeScript/Vite build passed. Isolated browser checks passed across 320/390/607/800/801/1672px in English/Myanmar/Thai (18 layouts): one action group, three buttons, fixed position before/after scroll, timeline clearance, centered preview and no horizontal overflow or React exceptions. No customer media or provider job was run.


## Latest checkpoint - 2026-11-18: labeled independent mobile Review buttons

- Mobile bottom-right Review actions now show both icons and full text at every supported width, including 320px. Removed the surrounding dock border, background, padding and shadow; only the three independent pill buttons remain. Bottom safe-area positioning and timeline clearance stay in place.
- Compact/mobile Continue reads Continue Review Script (localized short wording for Myanmar/Thai); desktop retains Continue to Voice & Look. The existing handler still saves scenes/settings and opens Step 3; this is only a label/layout change.
- Validation: production TypeScript/Vite build and 18 isolated browser layouts passed (320/390/607/800/801/1672px, English/Myanmar/Thai), including visible unclipped labels, no outer dock chrome, fixed placement on scroll, timeline clearance, no horizontal overflow and no React exceptions. No customer media/provider request was run.

- Owner label correction (2026-11-18): restored Continue to Voice & Look on mobile as well as desktop. Independent labeled bottom-right mobile buttons remain. Production build and the same 18 responsive/localized browser layouts passed with full visible labels. This supersedes the short Continue Review Script label above.

## Latest checkpoint - 2026-11-18: open Editing tools and compact preview details

- Step 3 Editing tools and the normal video preview outer card now use transparent surfaces with the same subtle raised shadow as the approved Step 1 source card. The black video canvas remains for letterboxing.
- Removed viewport-measured fixed/sticky tool height and internal settings scrolling. All five tabs (Voice, Subtitles, Video, Audio, Export) display their full content naturally; the main page scrolls. Selected tabs have bright text and a primary-colored underline without a filled background; inactive labels are muted.
- Preview scene controls now have a compact heading with a small Render Preview pill above a shorter full-width scene selector. Scene labels retain planned duration and source range. Project details are always visible in a compact responsive two-column definition list rather than a collapsible section.
- Added English/Myanmar/Thai Preview scene labeling. Existing preview, save, export and processing handlers are unchanged.
- Validation: TypeScript/Vite production build passed. Isolated browser QA passed all five tabs at 1672/1024/607/390/320px in English/Myanmar/Thai (75 combinations): transparent surfaces, visible settings without internal clipping, selected-tab treatment, always-visible details, compact controls and no horizontal overflow or React exceptions. These checks use mock media; no customer media or provider generation was run.

## Latest checkpoint - 2026-11-18: mobile Step 3 scrolling and bottom actions

- Removed the old mobile sticky preview positioning and its 104px top offset. Normal preview now remains in document flow and scrolls away with the page, preventing the transparent Editing tools controls from overlapping the video. Explicit expanded/fullscreen preview is preserved.
- At <=800px, Step 3 Back / Save Draft / Edit Script / Export actions use independent labeled pill buttons fixed at the bottom-right, matching mobile Review navigation. No surrounding dock background, border or shadow. Buttons wrap when translated labels require room; safe-area offsets and bottom content clearance prevent covering the last controls. Desktop actions remain in the workflow toolbar.
- Validation: TypeScript/Vite production build passed. Mock browser checks passed 36 combinations (320/390/607/800/801/1672px, English/Myanmar/Thai, completed and awaiting-review states), including preview movement on page scroll, no overlapping tools, visible action labels, bottom content clearance, unchanged desktop placement and no horizontal overflow or React exceptions. No customer media/provider job was run.

## Latest checkpoint - 2026-11-18: compact Review timeline toolbar and flat cards

- Moved the existing Script card editing controls (undo/redo, add/replace/reorder when applicable, split, exclude and AI Tools) into the Timeline header beside Zoom. The Script table starts directly with its column headings. Excluded/included filter is beside the timeline source/recap summary. Existing handlers, selected scene identity and edit history are reused.
- AI Tools trigger now contains only its label and down chevron, without its decorative icon, border or filled background. The compact options menu has no border, opens upward above the timeline and clamps horizontally to the viewport; outside click and Escape dismissal remain.
- Script, Review preview and Timeline outer cards now use transparent backgrounds, no outer border and the approved Step 1 raised shadow. Video letterboxing, readable table headings/selected rows and track visuals remain. Timeline height can expand for wrapped mobile controls without shrinking its tracks.
- Reduced mobile-only spacing between the step bar and Review summary; desktop workflow placement and mobile bottom-right labeled navigation remain.
- Validation: TypeScript/Vite production build passed. Isolated mock browser checks passed 36 combinations (320/390/607/800/801/1672px, English/Myanmar/Thai, subtitles/movie modes), checking a single moved toolbar, all three transparent raised cards, reduced mobile gap, compact AI trigger/menu, viewport-safe menu placement, Escape dismissal and no horizontal overflow or React exceptions. No customer media/provider request was run.

- Review preview-tab refinement (2026-11-18): Recap Preview / Original Source now use transparent tabs, muted inactive labels and bright selected text with a 3px primary underline, matching Step 3. Only styling changed. Production build and 18 mock browser checks passed across 320/390/1672px, English/Myanmar/Thai and both selected states; no overflow or React exceptions.


## Latest checkpoint - 2026-11-18: All Projects flat list and media grid

- Redesigned the customer All Projects page to match the approved list/grid references. The heading is text-only; six flat status tabs use count pills and a primary underline. Search keeps an outer focus border; desktop Mode/Source/Time/Sort controls are compact and unboxed. Existing shared sidebar/header and theme tokens remain.
- List view uses landscape thumbnails, compact mode/source tags, unboxed semantic status, updated date, contextual project link and an ellipsis action menu, separated by fine horizontal dividers without row cards or redundant table headings. Narrow screens stack status/action text beside the thumbnail.
- Grid view uses media-first vertical cards: four columns on wide desktop, three/two at intermediate widths and one on small phones. Downloads remain in the action menu and preview rather than separate inline links on each card. Pending changes, failure details and actual processing progress are preserved.
- Reused existing ownership-scoped server search, status/mode/source/date filters, sorting, polling, project destinations, preview, rename, retry/cancel and confirmed R2 deletion. First/previous/direct-page/next/last pagination remains functional. No backend or media engine behavior was changed.
- Validation: TypeScript/Vite production build passed; existing ProjectLibraryTest passed (8 tests, 214 assertions). Isolated browser QA passed 54 responsive/localized list/grid layouts across 320/390/480/607/800/1024/1200/1391/1672px in English/Myanmar/Thai, plus mobile filter application, status/view switching, menu viewport placement/download availability/Escape/outside dismissal and first/last page navigation. No horizontal page overflow or React exceptions. Browser fixtures use synthetic projects; no customer files were generated, deleted or sent to providers.

- Thumbnail refinement (2026-11-18): All Projects grid now uses equal 16:9 preview frames with centered object-fit: contain rather than cover. Portrait (9:16), landscape (16:9), square and ultrawide poster images retain their original proportions and show the entire image with dark letterboxing as needed. List thumbnails also contain the full image. This supersedes the earlier shallow cropped grid preview. Build passed; 10 isolated desktop/mobile list/grid browser layouts passed with synthetic 9:16/16:9/1:1/ultrawide images, no clipping or page overflow.

## Agreed scope - 2026-11-18: Version 1 customer workspace and My Plan & Usage

This records an agreed product/design scope, NOT a claim that the following pages are implemented.

- Customer sidebar scope: Dashboard, Recap Studio, All Projects, My Plan & Usage, Billing & Payments, Support, Preferences. Notifications live in the header bell; My Account & Security lives in the profile menu to avoid duplicate navigation.
- Recap Studio retains Source & Style -> Review Script -> Render & Download, supporting long-movie narrated recap, translation/dubbing of an existing recap and subtitles. Preserve the current approved UI, queue processing and project library.
- Planned implementation order: My Plan & Usage -> Billing & Payments -> customer Support -> customer Notifications -> Preferences / Account -> customer Dashboard.
- Admin creates customer accounts with Free Trial by default. No customer self-registration. Normal paid access is granted/scheduled only after payment approval. A pending payment keeps the current entitlement; an information request updates the same payment. Renewal creates a fresh period, not an automatic debit.
- Customer Support must never expose Admin internal notes. Include linked project/payment context, useful error-to-support links and customer-safe notifications.
- Preferences include en/my/th UI, narration/subtitle defaults, reusable presets and persistent Name Dictionary. Account/security exposes profile and password/session controls, never editable package/status/allowances.
- Studio package restrictions are temporarily bypassed during new-flow testing. Align and re-enable the package feature mapping before customer launch; do not silently enforce obsolete flags or describe bypassed enforcement as live.

My Plan & Usage is the next DESIGN brief; do not implement it until requested:
- Read-only personal overview of effective current package, access status, validity, scheduled next period, allowance totals/used/reserved/available, actual stored bytes and enabled features/limits. No Save Configuration or direct package-grant action.
- Reuse authoritative subscription/Usage Ledger calculations: successful exports settle reservations; failed/cancelled work releases them; idempotent retries do not charge twice. Processing is accounted in seconds; project video counting follows the existing per-period rules. Storage is actual current inventory, not a monthly counter. Renewal does not clear stored files. Latest published package entitlements apply to active/upcoming assignments; historical usage/payment snapshots remain historical.
- Plan cards use the actual allowance period, not a generic last-30-days window. History may filter past periods separately. Recent Usage shows successful exports by default, without repeated per-stage rows. Reserved work is visibly separate from charged usage.
- Upgrade / Renew opens Billing & Payments; Manage Files opens All Projects. These controls do not activate paid access. Do not add placeholder success flows for unimplemented destinations.
- Distinguish account suspension, subscription expiry, upcoming access, pending payment, exhausted quota, unlimited allowance and temporarily bypassed enforcement. Clamp available values to zero while explaining over-limit usage; never fabricate progress/usage or divide by zero.
- Preserve the shared sidebar/header and compact dark customer theme: Iris #B8A8FF, Mint #72E2C3, background #0B0D14, transparent raised surfaces, minimal borders, bright selected text with purple underline. Responsive desktop/mobile, compact type, shared feedback, real-request loading and first/previous/direct-page/next/last history pagination.
- Current request asks for a copyable UI/UX note in chat, not a new standalone note file or implementation.


## Implemented checkpoint - 2026-11-18: Customer My Plan & Usage

This implementation supersedes the design-only status above. Account -> My Plan & Usage is now available at `/account/plan`.

- Added `CustomerPlanController`, `CustomerPlan` service, `pages/account/plan.tsx`, `types/plan.ts`, `lib/plan-i18n.ts`, and scoped `my-plan.css`. Shared navigation, frame link and en/my/th labels include the Account group. Existing sidebar/header behavior remains intact.
- Personal read-only overview uses effective published package terms and authoritative UsageLedger balances. Shows validity/access state, next scheduled period, pending payment, processing/video total-used-reserved-available, actual current stored bytes and pending storage allocations, concurrency and queued work. Uses the preserved allowance anchor after date corrections; no quotas or subscriptions are changed by reading this page.
- Features and Limits tabs display current entitlements, upload/duration limits, retention period and actual cleanup configuration. Unknown, unlimited, exhausted, over-limit, expired/upcoming and no-plan cases are handled. Studio testing bypass remains unchanged and is disclosed; this page does not re-enable package enforcement.
- Successful export history is customer-scoped and deduplicated to the latest successful generation per project, with literal search, workflow and current/all-period filters. Current balance cards remain independent of history filters. Real output types include styled ASS when present. Legacy exports without a ledger entry show usage as unrecorded, never fabricated zero.
- History includes first/previous/direct-page/next/last navigation and an animated detail drawer. `/account/plan/exports/{run}` only returns the signed-in customer's successful generation, safe usage event kinds/timestamps and historical package name/version. No raw settings, secrets or Admin notes/reasons are exposed; detail responses are private/no-store.
- Manage Files and active work links open the existing All Projects library. Upgrade/Renew currently opens a read-only published-plans dialog and the configured Admin support contact. Customer Billing & Payments is still deferred: connect these actions to that page when implemented. No payment submission, fake checkout, paid activation or renewal is performed here.
- Compact responsive dark UI, en/my/th text, shared tooltips/dialogs/dropdowns/toasts, contained thumbnails, internal drawer scrolling and genuine request loading. Active work refreshes in the background every 10 seconds while the page is visible.
- Validation: production build passed; 12 CustomerPlan tests plus existing customer usage-summary and queue suites passed (28 tests, 224 assertions). Scoped Pint passed. Isolated browser checks covered 15 locale/viewport combinations (en/my/th at 1672/1024/768/390/320), tabs, plans dialog, detail drawer and last-page navigation, with no horizontal overflow or browser errors. Browser fixtures were synthetic; no customer payments, media or stored records were modified.


### My Plan & Usage UI refinement - 2026-11-18

- Removed the main heading icon. Current-plan strip is now borderless/transparent with raised shadows. Replaced duplicate Upgrade and Renew controls with one compact pill, Plans & Renewal. Refresh uses the familiar circular-arrow icon.
- Narrowed the Project column and allow long names to wrap. Completed times use localized dates plus explicit 12-hour AM/PM time on this page; charged processing shows minutes and seconds, including sub-minute usage (for example 0 min 7 sec), rather than decimal minutes.
- Usage Details is a centered responsive dialog matching the supplied reference: full contained poster/duration, completion status/date, six-item usage summary and connected activity timeline; header/footer stay visible and the body scrolls with a hidden scrollbar.
- Plans & Renewal uses two-column desktop/one-column phone plan cards with real published pricing, validity and allowances; the assigned package is highlighted by stable package ID as Current Plan. It remains a read-only Admin-contact flow until customer Billing is implemented; no access/payment logic changed.
- Added only safe package ID, package type and output/source duration to the existing read-only response for accurate highlighting/poster duration. Customer scoping and event sanitization are unchanged.
- Shared ActionTooltip now dismisses on pointer leave/click and suppresses focus-restoration reopening after dialogs close. Keyboard Tab focus still shows the tooltip and Escape dismisses it; explicit timed tooltip visibility remains supported.
- Validation: scoped Pint and all 12 CustomerPlan tests passed (107 assertions); production build passed. Browser checks verified 15 locale/viewport layouts, dialogs, current-plan highlight, AM/PM and min/sec formatting, last-page navigation, mouse hover/click/leave/dialog-close behavior and keyboard tooltip access without overflow or browser exceptions.

### My Plan follow-up - 2026-11-18

- Supersedes the centered Usage Details checkpoint: details now slide in from the right as a full-height responsive panel, preserving the reference poster, six-item summary, activity timeline, fixed header/footer and separately scrolling body.
- Plans & Renewal cards are substantially smaller with compact type, no outer borders and raised 3D shadows; current-plan highlighting uses a soft tint/glow. Close buttons are pills. Removed the decorative crown from the current-plan strip.
- Refresh animation follows the counterclockwise arrow direction. User explicitly prefers compact borderless raised cards for future work; recorded in AGENTS.md. Business rules and read-only plan behavior are unchanged.
- Validation for this follow-up: TypeScript/Vite build passed. Reused browser QA passed 15 locale/viewport layouts, tooltip lifecycle, pagination, compact borderless card shadows, page scroll locking, right-edge full-height panel placement, opening/closing and mobile body scrolling with no browser errors.

### My Plan compact Usage Details - 2026-11-18

- Supersedes the previous poster/six-item/timeline detail layout: the right-side Usage Details drawer is now 480px maximum, full width on smaller phones, square at every outer corner, with compact 14px title and 10–12px content. Fixed header/footer are shorter; only View Project remains in the footer, with the header X/Escape/backdrop available for closing.
- Removed the duplicate video/title hero and raw Reserved/Released/Charged timeline from this customer view. Show actual processing and video allowance used as two compact borderless raised metrics, a localized explanation that allowance use is not a money charge, plus small Type/Output/Package/Period rows. Unknown historical usage remains Not recorded; zero video deduction is explained without inventing a reason. No accounting, subscription or payment rules changed.
- Plans & Renewal now places Free Trial as small green plain text alongside the Free plan name; the price, validity and allowance rows remain aligned with paid cards.
- Validation: production TypeScript/Vite build passed. Reused synthetic browser fixtures passed 15 English/Myanmar/Thai viewport combinations (320–1672px), checking drawer width, square corners, compact header/footer/title, removed poster/timeline/footer Close, unknown usage, overflow, plan display, tooltip lifecycle and pagination; no browser exceptions. Existing missing static-asset build warnings remain unrelated to this change.

### My Plan history filters - 2026-11-18

- Recent Successful Exports Type and Period dropdowns now sit directly beside the capped-width search field with an 8px gap on desktop. Removed the expanding spacer column. Dropdown triggers match All Projects: transparent, borderless, compact, with a soft hover/open state and keyboard focus indicator. Narrow layouts stack search above two adjacent filters.
- Validation: production TypeScript/Vite build and existing browser QA passed all 15 locale/viewport combinations without overflow or browser exceptions. Filtering/business logic is unchanged.

### Borderless raised filter dropdowns - 2026-11-18

- All Projects toolbar/mobile filter sheet and My Plan & Usage history filters now have subtle surface tint and raised 3D shadows, keeping outer borders removed. Hover/open states deepen the shadow with a soft primary tint; keyboard focus indicators remain. My Plan filters stay beside search on desktop.
- Validation: production build passed; My Plan browser checks passed 15 locale/viewport combinations, and All Projects checks passed four desktop/mobile widths including filter-sheet triggers (zero borders, visible shadows, no horizontal overflow or browser exceptions).


### Customer Billing & Payments — 2026-11-18

- Added User Account → Billing & Payments at /account/billing, using the existing user frame. Small type, pill actions, borderless raised cards/filter triggers, underline tabs, compact payment history and a right-side detail drawer. My Plan's Plans & Renewal modal now links to Make a Payment.
- Make a Payment is a three-step wizard: choose a published paid package → transfer to an active receiving method in the same currency and upload receipt → review/confirm/submit. Plan prices, currencies, periods, receiving details, QR and required evidence rules come from existing Admin configuration; no fabricated monthly/yearly discounts or free-trial purchase. MMK/THB/USD remain separate; no automatic conversion.
- The server derives new/renewal/change purpose and period from actual access. Free accounts remain Admin-created. Submitting or correcting evidence does not grant access. Existing Admin Payments approval remains the only paid activation path. Existing scheduled periods prevent overlapping new purchases; expired/ended access can still reach billing and renew.
- Customers may correct their own pending/needs-information records and replace the current receipt. Old evidence remains available only to Admin audit, never alongside the new current customer receipt. Under-review/approved records are read-only. Admin information requests are shown and customer resubmission returns the same record to review.
- All customer payment, receipt and historical QR routes enforce ownership. Explicit safe DTOs omit internal notes, audit metadata and configuration secrets. Receipt MIME/size/image dimensions are validated; private file routes disable caching/sniffing. CSRF/session protection, submission throttles, optimistic revisions, configuration/package version checks and idempotency reuse the existing Payments transaction path. Failed/stale submissions cannot silently use changed terms.
- Existing receiving-account snapshots and payment pricing stay fixed during correction; new records use current published data. Refund history is read from Admin refund records; this form does not execute transfers or issue refunds.
- Source map: CustomerBillingController, CustomerBilling service, SubmitCustomerPaymentRequest, Payments::submitCustomer, resources/js/pages/account/billing.tsx, billing-i18n.ts, types/billing.ts and resources/css/billing.css. No new schema or dependency is required.
- Validation: CustomerBilling, CustomerPlan, PaymentTransactions and PaymentConfiguration suites passed 81 tests / 813 assertions. Production TypeScript/Vite build passed. Synthetic browser fixtures checked the page, all three wizard steps and detail drawer in English/Myanmar/Thai at 320, 390, 768, 1024 and 1672px without overflow or browser exceptions; a synthetic end-to-end submission passed. No real transfer, customer email or live financial record was created for verification. Existing unresolved login-background/Padauk asset build warnings are unrelated.

### Customer payment edit blank-screen fix - 2026-11-18

- Reproduced Edit Payment → Save → blank screen in the built browser app. The save succeeded, but the shared success toast called native showPopover() after its closing dialog host had become disconnected, throwing InvalidStateError and unmounting the React page.
- ToastViewport now opens its popover in a layout effect and only while the DOM element is connected. The existing portal observer continues moving toast feedback from closing dialogs to the page. Payment validation, evidence replacement, approval and accounting rules are unchanged.
- Validation: production TypeScript/Vite build passed; CustomerBilling feature suite passed 8 tests / 106 assertions. Synthetic edit/save browser regressions passed English/Myanmar/Thai at desktop 1400px and mobile 390px: modal closes, payment list stays visible, success toast appears and dismisses, no horizontal overflow and no browser exceptions. No live payment records were modified for testing.

### Empty states and filter reset controls - 2026-11-18

- My Plan & Usage Recent Successful Exports now shows only the empty icon and heading when no results exist; removed the redundant hint and below-empty Clear Filters button. Added a shared compact round filter-clear icon after Type/Period. It enables only for changed search/type/period and restores the default Current period.
- All Projects now uses a transparent, borderless empty state with a compact heading. Search is capped to a shorter width with adjacent dropdowns; a matching clear-filter icon follows the dropdowns. Reset clears search, mode, source, custom date range, sort and status tab while retaining grid/list preference. Default filter states disable the icon; requests in progress prevent duplicate reset calls.
- Shared ClearFiltersButton uses the existing localized tooltip and StudioIcon system, subtle raised shadows and keyboard focus styling. Responsive toolbars stack cleanly on narrow screens. No backend, subscription, storage or billing rules changed.
- Validation: production TypeScript/Vite build passed. Synthetic browser checks passed both pages in English/Myanmar/Thai at 1400, 768, 390 and 320px (24 page/locale/viewport combinations), testing default-disabled and changed-enabled reset controls, search-to-empty and clear-to-results, dropdown/date/sort/status reset, removed My Plan empty actions, transparent project empty state and no horizontal overflow or browser exceptions. No live records changed.

### User header Support chat ? 2026-11-18

- User Support is a compact bottom-right chat widget opened from a headphones icon in the header; no Chat/Support sidebar menu was added. Close/Escape hides the widget with animation. It uses the existing Admin Customers ? Support inbox and customer support APIs, not a separate messaging store.
- Customer messages appear on the right; Administrator replies appear on the left with a shield, Admin label and sender name. Existing conversations/history, earlier messages, unread conversation badge, text and private PNG/JPG/WEBP/PDF attachments are connected. Composer sends with Enter; Shift+Enter inserts a newline; empty submissions are disabled. Failed sends preserve the draft and request UUID for idempotent Retry.
- Existing ownership, customer/admin session separation, CSRF, message/file validation, throttles and private attachment authorization remain in force. Internal Admin notes stay hidden. Resolved conversations reopen when the customer replies. Support remains available to active accounts whose subscription has expired; suspension still prevents access. Chat does not send emails.
- Polling pauses while the browser tab is hidden and slows when the chat is closed; no fabricated online presence is displayed. Read acknowledgement occurs only with the chat open, the page focused, and the newest messages visible. Customer detail polling now accepts loaded_from so older loaded messages deleted by Admin are redacted too.
- UI uses small text, rounded compact controls, borderless raised surfaces, en/my/th labels, and a responsive viewport/keyboard-aware popup. Queue and Support icons share a mobile header group to prevent extra header rows. Widget state is local to its mounted layout; saved messages are server-backed and survive reload/navigation.
- Source map: resources/js/components/customer-support.tsx, lib/customer-support-i18n.ts, resources/css/customer-support.css; AppLayout header, SharedData.supportFeed and HandleInertiaRequests; CustomerSupportController::show loaded_from support.
- Validation: SupportInbox feature suite passed 26 tests / 405 assertions; Pint and production TypeScript/Vite build passed. Synthetic browser fixtures covered en/my/th at 1400/768/390/320px, popup open/close, header containment, empty-send disabled, Enter send, failed-send draft persistence through polling and retry, unread acknowledgement and no horizontal overflow/browser exceptions. No live customer message, email or financial record was created for verification.


### Unified customer Support chat — 2026-11-19

- The user header Support popup opens a single timeline with date separators. The conversation chooser, new-conversation button and status strip were removed from the popup. Previous customer-visible messages appear in the same timeline, with older messages loaded on scroll.
- General sends reuse a support conversation. Replies to a message in a linked case go back to that case in the Admin inbox. Internal Admin notes remain private. The header badge counts unread Admin replies, and opening the newest part of the chat acknowledges only the messages the customer can see.
- Customers can use the ellipsis beside their own messages to edit text, delete or reply. Admin messages can be quoted in replies. Deleted messages leave a tombstone in both interfaces, and quotes redact the deleted content. Attachments stay subject to the existing private download checks.
- The unified feed uses /support-api/chat and its message and read endpoints. AppLayout receives the feed through HandleInertiaRequests. The implementation is in SupportInbox, CustomerSupportController, the customer-support component, its translations and CSS. The existing support tables are reused.
- Verification passed 28 SupportInbox tests with 462 assertions, the production build, and synthetic Chrome checks in English, Burmese and Thai at 1400, 390 and 320 pixels. Browser checks exercised edit, reply, delete and outside-click dismissal.


## Version 1 launch hardening - 2026-11-19

Supersedes the "Legacy implementation caveat" in section 8 and the "re-enable package feature mapping" note in the 2026-11-18 agreed scope.

- **Paid access only through Payments.** `SubscriptionActionRequest` now accepts only `cancel` (scheduled period) and `end` (current period); renew/change/extend are rejected with a 422 on `action`, and `Subscriptions::mutate()` only closes periods. The Admin Subscriptions editor is a small reason/confirm dialog for those two actions. Account Edit corrections may fix dates of the customer's current package or move them to a Free Trial; assigning a *different paid* package is rejected (`package_id`), and the Users edit dropdown shows only trial packages plus the current one. The unrouted `CustomerAccounts::upgradePackageFeatures()` was removed; the latest-published-version policy already covers feature upgrades.
- **Package feature mapping matches Recap Studio.** `PackageCatalog::FEATURES` adds `movie` (Summarize a Long Movie). `dub` now means Translate an Existing Recap and `subs` means Subtitles; `srt` covers SRT and ASS files. `PackageCatalog::features()` normalizes every definition; definitions without a `movie` key inherit it from `dub`, so existing packages and snapshots were not rewritten. Admin package payloads expose the normalized map. Publication requires at least one of movie/dub/subs; narration-dependent features need movie or dub.
- **Server enforcement.** `UsageLedger::featureErrors()` (used by settings save and `reserve()`) checks workflow (`settings.workflow` for movie, `settings.mode` otherwise), output, background, and the previously unenforced `subtitle_style`, `cover_subtitles` and `layout`. A customization value is allowed without the feature when it equals the Admin System Project Defaults or the value already saved on that project (so older projects keep their saved look). New uploads and YouTube imports return 403 `feature_unavailable` before any file transfer when the package lacks `local`/`youtube`.
- **Customer messaging.** `UsageLedger::hasProcessingAccess()` is shared by `EnsureCustomerAccess` and the Studio props. `subscription_unavailable` responses include `billing_url`; Studio shows a plan-inactive notice with an Open Billing & Payments link, and the localized message no longer says "contact the administrator". Studio receives normalized `features`, `planActive` and `billingUrl`; locked workflow/source/output choices are disabled, and Subtitle style, Cover and Layout controls (including caption/cover dragging) are locked with a "Not included in your plan" note and Billing link.
- StudioTesting still bypasses package checks only in the local environment. With it off (production, or `MEDIA_STUDIO_TESTING=false` locally) the mapping above is live.
- Tests: new `PackageEntitlementMappingTest`; `SubscriptionManagementTest` now creates scheduled periods the way payment approval does; `CustomerPackageAssignmentTest` covers the paid-correction rule.

### Support chat paging, reply jumps and two-way edits - 2026-11-19

- Both chats load the latest 20 messages (`SupportInbox::PAGE_SIZE`). Scrolling near the top loads the previous 20 automatically with a shimmer "Loading earlier messages" pill; short threads keep filling until the panel scrolls. The top shows "Start of conversation" when nothing older remains.
- Clicking a quoted reply scrolls to and briefly highlights the original. When it is older than the loaded history, one request with `before` + `until=<id>` (max 200 messages) loads down to it. Works on Admin and customer sides.
- A round jump-to-latest button appears after scrolling up (~240–320px) and shows a count of new incoming messages. Opening a chat pins to the newest message; a ResizeObserver keeps it pinned while images/fonts load.
- Customer chat labels Admin messages as "Admin" (once per consecutive group). The loading spinner now animates (`.spin` was previously undefined).
- Edits are two-way: `SupportInbox::editMessage()` lets a sender edit their own text messages (customers: replies; Admins: replies and internal notes) via PATCH `/admin/support/{conversation}/messages/{message}` or the existing customer chat route. Payloads expose `can_edit`, `edited` and `edited_at`; both sides show "Edited". Admin detail polling now sends `loaded_from` + `since` and receives `changes`/`reset`/`snapshot`, like the customer chat, so the other side's edits appear without reloading.
- Tests: `SupportInboxTest` covers 20-message paging without gaps, `until` loading, Admin edit permissions and cross-side edit propagation.

## Implemented checkpoint - 2026-11-19: Customer Settings › Preferences

`/settings/preferences` replaces the former frame placeholder. It follows the four supplied screenshots: General, Studio Defaults, Saved Presets and Name Dictionary tabs, with one page-level Discard / Save Changes and an "Unsaved changes" indicator. My Account & Security is not built yet (no screenshots supplied).

- **Draft vs. immediate actions.** Language, Studio Defaults and the Name Dictionary form one draft saved by Save Changes (PUT `/settings/preferences`). Leaving with unsaved changes asks for confirmation (Inertia `before` + `beforeunload`). Preset actions (add, edit, duplicate, delete, reorder, default) save immediately with their own endpoints and toasts.
- **Resolution order for new projects:** Admin System Project Defaults → Studio V1 starting values (`StudioPreferences::STARTING`, mirrored by `studioStart` in `resources/js/lib/studio-defaults.ts`) → personal preferences (`users.studio_preferences`, only values that differ are stored) → default preset (`users.default_preset_id`) or the preset chosen with ▶ "Start a new recap with this preset" (`/projects/new?preset=ID`). Values the current package does not include (subtitle styling, quality/layout, workflow, output) are dropped from overrides, and saves reject them unless they equal the baseline or the already saved value. Studio receives `personalDefaults` and `startPreset`; `MediaSettings::defaults($userId)` applies the same overrides at project initialization.
- **Preference keys:** workflow (+ derived mode), narration/subtitle language, target duration, narration style, voice, speed, pitch, output format, quality, and a subtitle look (Classic/Modern/Boxed bottom, Classic top) that maps to the subtitle font/bold/outline/shadow/background/opacity/position fields.
- **Voice sample:** GET `/settings/preferences/voice-sample?voice=&lang=` generates a short sentence per voice/language/speech model once with `GeminiClient::speech` and caches the WAV under local `voice-samples/`. It requires the `voice_preview` feature and a ready provider; the browser plays it at the selected speed.
- **Presets:** up to 12, unique names, custom order (`media_presets.position`, pointer drag or Arrow Up/Down on the handle, only in Custom order without a search), Newest/Oldest/Name sorting, duplicate as "(copy)", delete clears the default via FK `nullOnDelete`. The old Phase 1 `/media/presets` endpoints remain for compatibility.
- **Name Dictionary:** entries now also carry optional `type` (character/place/term/other), `language` and `note` (`App\Support\NameDictionary`). The Studio dialog keeps these fields. Up to 50 names, case-insensitive duplicate check, search/type/language filters, column sorting, bulk delete, CSV import (merge, skip duplicates/invalid/over limit) and CSV export with BOM.
- Migration `2026_11_19_062123_add_studio_preferences_to_users_and_presets` (additive) was applied locally. Files: `PreferencesController`, `StudioPreferences`, `NameDictionary`, `pages/settings/preferences.tsx`, `lib/preferences-i18n.ts`, `lib/studio-defaults.ts`, `types/preferences.ts`, `css/preferences.css`; new icons grip/lock/book/map-pin/sort.
- Verification: `PreferencesTest` (10 tests) plus Studio/dictionary/upload/import suites; isolated SQLite + Playwright checks of all tabs in en/my/th at 1440/820/390/320px (no horizontal overflow, no page errors) and real flows: save + reload, leave guard, discard, duplicate-name validation, add name, add preset, default toggle, keyboard reorder, Thai switch keeping the tab, and Start with preset opening Studio with the preset notice. No live AI voice sample was requested during QA.

### Preferences › General becomes the customer account page - 2026-11-19

- Owner decision: Interface language was removed from Preferences (the header flag already switches it). General now holds the customer's own account: profile hero (photo, name, status, member since, last sign-in, current plan tile), Personal information (display name in the page draft; email via a password-confirmed dialog), Password & security (current password, live rule checklist and strength bar), Date & time display (personal time zone limited to Myanmar, Thailand, Japan and South Korea — `CustomerProfile::TIMEZONES`, shown as four cards with local time and UTC offset. There is no separate "Default" choice: until the customer picks one, the platform value is shown as the selected option; saved values outside the list fall back to it. Date and 12/24-hour formats work the same way, with a live preview) and Signed-in devices (browser/OS, masked IP, last active, sign out one device, password-confirmed "Sign out other devices"). This supersedes the separate "My Account & Security" page idea.
- Profile photos are stored only in the configured cloud storage profile (R2), never on the web server: the browser crops a 512px circle, the server re-encodes a square WEBP with GD (metadata removed) and serves it from an owned, private route (`/settings/preferences/avatar`). With a local media disk the photo controls are disabled with a Contact Support message. The header avatar shows the photo.
- Personal display settings override the platform `app.timezone/date_format/time_format` shared props for that customer only, so every customer page formats dates the same way. Stored in `users.display_preferences` (null = platform default).
- Email and password changes, and "sign out other devices", end other sessions (database session rows, a new remember token and `auth_version`) while keeping the current browser signed in (session version and password hash refreshed). Changes are recorded as `self_*` customer account events. Migration `2026_11_19_070208_add_profile_and_display_preferences_to_users` (additive) applied locally.
- Files: `CustomerProfile` service, `PreferencesController` account endpoints, `components/preferences-account.tsx`, CSS in `preferences.css`; tests `CustomerProfileTest`. Browser QA (isolated SQLite, local media disk) covered en/my/th at 1440/820/360px, time zone search, password dialog, photo cropper, saving name/display and the header refresh. Real R2 uploads were verified only with a faked disk in tests.

## Implemented checkpoint - 2026-11-19: Customer Notifications

- Header bell (`CustomerNotificationBell`) replaces the empty placeholder: unread badge, gentle ring when new items arrive (not with reduced motion), dropdown with All/Unread tabs, Today/Yesterday/This week/Earlier groups, Mark all as read and View all. Phones get a draggable bottom sheet. Polling is silent (45s, 15s while open, paused when the tab is hidden) and every bell/page instance syncs through a browser event after actions.
- Page `/notifications` (sidebar Account › Notifications): tabs All/Unread/Videos/Payments/Plan/Account with counts, search, status, period (7/30/all), Show archived, refresh, clear filters (Filters sheet on narrow screens), bulk selection bar (Mark as read/Archive), per-row ⋮ menu, swipe-left Read/Archive on touch, category chips (status chips inside a category tab), action pills (Download/Review/Retry/Renew/Provide info) and First/Previous/pages/Next/Last pagination (10 per page).
- Table `customer_notifications` (migration `2026_11_19_091250`): `type`, `category`, `data` JSON, unique `user_id+dedupe_key`, `read_at`, `archived_at`. Text is rendered client-side from `type` + `data` in my/en/th, so stored records never contain Admin notes or internal error codes. Admin accounts never receive customer notifications.
- Sources (`CustomerNotifications` service, hooked in `AppServiceProvider` model events): MediaTask analysis→awaiting_review (script ready), generate→completed (video ready, direct MP4 download action), generate/analysis→failed; PaymentTransaction status approved/needs_information/rejected; PaymentRefund completed; self or Admin password change and self email change. Plan notifications (expiring within 7 and 1 day when no next period, expired/ended within 30 days, payment-started plan within 14 days) are derived on demand with a 5-minute per-user cache. Deleted projects show as removed with no link.
- Billing now opens a payment drawer from `/account/billing?payment=ID` (used by payment notification links).
- Tests: `CustomerNotificationsTest`. Browser QA (isolated SQLite) covered dropdown, unread empty state, page tabs/filters, payments status chips, bulk read, archive, mark all, badge sync, mobile sheet, filters sheet and swipe in en/my at 1440 and 375px.

## Implemented checkpoint - 2026-11-19: Customer Dashboard

- `/` (`CustomerDashboardController@index`, page `workspace`) replaces the frame placeholder and follows the supplied dashboard image: greeting by the customer's display time zone, Action Needed strip, KPI row (Processing used/total with reserved and left, Videos this period, Storage, In progress), Continue Working, Recent Projects (6, 3-column), Processing Used chart (14 days / 30 days / All time by month) with Used/Reserved/Remaining for the current period, My Plan, Quick Start, Recent Notifications, Billing Snapshot and Help & Support. The sidebar footer now shows the customer's plan (`planBadge` shared prop) and links to My Plan.
- Data (`CustomerDashboard` service) reuses `CustomerPlan::overview`, project statuses, the usage ledger (`charged - returned` seconds of successful exports, dated by the run's finish time), `CustomerBilling::pending` and `CustomerNotifications::feed`. Each section is built separately: a failing section is reported in `errors` and shows "Couldn't load · Retry" only inside its card (`GET /dashboard/live?sections[]=…`).
- Action Needed priority: plan ended / no plan, payment information needed, failed projects (last 14 days), scripts ready, plan expiring (no next period), processing over limit or under 10%, storage at 90%+. Each item can be dismissed for the browser session; maintenance is already shown by the platform banner.
- Continue Working lists running, queued/retrying, awaiting review, recently failed and not-started projects (max 4) with real progress, cancel (confirmed) and retry. The "≈ min left" estimate appears only after progress has been observed moving. Live widgets refresh silently every 10 s while jobs run (30 s otherwise), pause in hidden tabs and refresh after queue actions.
- Quick Start opens `/projects/new?workflow=movie|translate|subtitles` (with the default preset when set); Studio now honours `workflow` and keeps the output valid. Workflows not in the package are locked and link to Billing. Contact Support opens the header chat (`open-support-chat` event); the card shows unread replies.
- New customers see a Getting Started checklist (5 steps from real data) until their first export; Hide is stored in `users.dashboard_checklist_hidden_at` (migration `2026_11_19_120000`, additive, applied locally).
- Tests: `CustomerDashboardTest` (4). Browser QA on isolated SQLite covered active, new, expired, over-limit and unlimited states in en/my/th at 1672/1440/1024/390px (no horizontal overflow, no page errors), chart ranges and tooltip, action dismiss, support chat, Quick Start preselect and cancel confirmation.

### Forgot / Reset password match the sign-in card - 2026-11-19

- Forgot password and Reset password now use `CinematicLoginLayout` (same film-ribbon background, compact card, language switcher) with a page badge (key "Reset Password" / lock "New Password"). The old `layouts/auth-layout.tsx` (separate title, description and "Private workspace" footer) was removed.
- Forgot: email + Send reset link + Back to sign in; the sent notice is a compact mint box. Reset: the email from the link is read-only (editable again if the server rejects it), new + confirm password, and five live rule chips (12+, Aa, 0-9, !@#, match) replace the long policy sentence. Server rules (`Password::defaults`) are unchanged.
- Browser QA (isolated SQLite): en/my/th at 1440x900, 1366x657, 390x844 and 360x640 - no vertical or horizontal scroll in sign-in, forgot, sent, reset and reset-error states.

### Premium account emails - 2026-11-19

- The only two outgoing emails (password reset from Forgot password, and the admin-created account welcome / resend login information) now share one premium dark template: `App\Support\BrandedMail` + `resources/views/mail/branded.blade.php` (HTML) and `branded-text.blade.php` (plain text). Text lives in `lang/{en,my,th}/mail.php` and follows the customer's `preferred_locale`.
- The ZATLAN logo (`resources/mail/zatlan-logo.png`, 480px copy of the brand logo) is embedded inline (CID attachment), so it shows even when `APP_URL` is local. The admin-uploaded General Settings logo is not used in email yet; name, tagline and support email/URL do come from General Settings.
- Reset uses `ResetPassword::toMailUsing` (same class and URL builder, so existing tests and the 60-minute expiry are unchanged). Welcome keeps the `platform` mailer. Test: `BrandedMailTest`.
