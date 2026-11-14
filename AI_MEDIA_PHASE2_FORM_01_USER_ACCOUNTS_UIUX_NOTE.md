# ZATLAN — Phase 2, Form 01: User Accounts

## 1. Approved scope — read this first

**Purpose:** Give an external UI/UX image generator the complete design and behavior brief for ONE customer-account management form in the existing ZATLAN Admin side.

**User-approved flow:** Admin creates a customer's account, assigns a package, gives the customer their login details, and the customer signs in and uses the service within that package.

- The operator is **Admin**. Do not introduce a separate Owner role.
- There is **no Roles/Permissions form, permission matrix, staff invitation flow, or admin-account creation flow** in this screen.
- These are **customer accounts**, not additional administrator accounts.
- Customers cannot register themselves. No Sign Up, public registration, mandatory invitation acceptance, or onboarding wizard.
- Admin supplies the initial password. After creation, the customer can sign in directly; no mandatory first-login password change in this approved flow.
- Package assignment is an Admin-controlled access grant. It does not, by itself, mean payment was received.
- This document is a design/implementation specification. The form, package catalogue, account states, and allowance controls described here are **not yet implemented** by producing this note.
- This task only renames/consolidates the menu and supplies this brief. It does not build the form or change authentication/business data.

မြန်မာအကျဉ်း — Admin က Name၊ Email၊ Password နဲ့ Package ရွေးပြီး User အကောင့်ဖန်တီးမယ်။ User က Login ဝင်ပြီး သတ်မှတ်ထားတဲ့ Package အတိုင်း သုံးမယ်။ Admin က အကောင့်ပြင်၊ Password ပြောင်း၊ Package ပြောင်း/သက်တမ်းတိုး၊ ပိတ်/ပြန်ဖွင့်နိုင်မယ်။ Permissions နဲ့ Owner role သီးခြား မပါပါ။

## 2. Menu, route and existing frame

**The single menu location is Customers → User Accounts.**

| Language | Main group | Menu/page name |
|---|---|---|
| English | Customers | User Accounts |
| Myanmar | အသုံးပြုသူများ | အသုံးပြုသူ အကောင့်များ |
| Thai | ลูกค้า | บัญชีผู้ใช้ |

- Rename the existing Customers → Users entry to User Accounts.
- Remove the obsolete Administration → Admin Accounts & Permissions entry. Do not create a second account-management destination.
- Keep Subscriptions and Support under Customers. Subscriptions will later provide an overview of package periods; it must use the same assignment records, not maintain a separate balance.
- Proposed future page route: `/admin/users`. This route is a design target, not a route created by the menu change.
- Customer login remains `/login`; Admin login remains `/admin/login`.
- Breadcrumb: **Customers › User Accounts**, plain text with a small chevron separator. No leading breadcrumb icon; no pill/border around the final label.
- Heading: users icon tile + **User Accounts**.
- Subtitle: **Create user accounts, assign packages, and manage access.**

Preserve the current Admin frame: the exact shared User-side AppLayout: 228px expanded / 64px collapsed desktop sidebar and 56px top header, with the 56px sidebar logo row aligned to the header bottom, ZATLAN logo, language selector, saved Light/Dark preference, account identity and sign-out. The sidebar can be hidden/shown with its existing animation. Groups remain expanded; hide scrollbar chrome while preserving scrolling and fixed sidebar branding/account footer.

All existing Admin menu groups remain: Overview; Customers; Monetization; Operations; Reports; Settings; Administration. Highlight only Customers → User Accounts in design images of this form. Do not invent extra sidebar destinations or in-form navigation tabs that duplicate those menus.

**Theme distinction:** Login pages are permanently dark. This Admin management form must support **both Dark and Light**, as the existing dashboard does. Keep Myanmar, English and Thai language support.

## 3. Visual contract

Use the existing premium Iris/Mint design, compact typography, subtle gradients and restrained shadows. Use the actual supplied ZATLAN logo; do not generate a replacement logo.

- Logo asset: `public/images/zatlan-3d-film-logo.png`.
- Source of truth: `AI_MEDIA_THEME_COLORS.md` and `resources/css/theme.css`.
- Typical text 11–12px; controls 12px; group labels 10–11px; page heading 18–22px. Myanmar/Thai need comfortable line height.
- Inputs/buttons about 36–40px high on desktop and at least 44px touch targets on mobile.
- Do not copy the cinematic login background into this management screen. Use the established Admin workspace canvas.
- Avoid a large welcome hero or decorative statistics that push the user table below the fold.
- Statuses require readable text and an icon; never communicate state using color alone.

| Token | Dark | Light |
|---|---|---|
| Primary | #B8A8FF | #6F5CE7 |
| Primary hover / active | #CABCFF / #9E8AFF | #806FEF / #5B4BD6 |
| Mint | #72E2C3 | #28B893 |
| Accent | #8B5CF6 | #8B5CF6 |
| Canvas | #0B0D14 | #F6F7FB |
| Card | #141927 | #FFFFFF |
| Elevated surface | #1C2334 | #F0F2F7 |
| Sidebar | #10131D | #FFFFFF |
| Input | #101622 | #F7F8FC |
| Border | #2C3549 | #DDE2EC |
| Primary text | #F5F6FB | #171A24 |
| Secondary / muted text | #BAC2D5 / #929FB9 | #596176 / #7B8498 |
| Text on primary | #1A1333 | #FFFFFF |
| Success | #7BE3AE | #28A86B |
| Warning | #F3CA78 | #C98A18 |
| Error | #FF929F | #D9445A |
| Info | #94BFFF | #477BD6 |

## 4. Main screen: User Accounts list

### Header and summary

- One primary action: **Create User Account**, at the right of the page heading. Do not duplicate it in the global header.
- Four compact clickable summary filters: **Total Users**, **Active Accounts**, **Suspended Accounts**, **Expired Packages**.
- Total Users excludes archived accounts by default; Active and Suspended are account-status subsets. Expired Packages is an independent package/access filter and can overlap Active Accounts.
- Counts come from real records across the defined scope, not the current page of results. Show loading placeholders while counts are unavailable; never invent live numbers.

### Toolbar

| Control | Behavior |
|---|---|
| Search | Search name/email; debounce; reset pagination when changed. |
| Account status | All current / Active / Suspended / Archived. |
| Package | All / enabled configured package names / Unassigned for legacy records. |
| Package access | All / Available / Not started / Expired / Allowance exhausted / Unassigned. |
| Sort | Newest created / Name A–Z / Expiring soon / Last login. |
| Clear filters | Reset search/filters to defaults; show only when filters differ. |

Use the existing themed dropdown/listbox treatment. No browser-native blue option menus. Wrap controls neatly on narrow desktop widths; use a compact Filters drawer on mobile.

### Table / mobile cards

| Column | Content |
|---|---|
| Account | Initials avatar, full name, email. Name opens details. Long email wraps safely. |
| Package | Package chip: configured Free Trial / Pro / Premium or other configured name. Never infer payment status from this chip. |
| Account status | Active / Suspended / Archived badge. |
| Package expires | Date/time with timezone context, or meaningful No package / No expiry if that policy exists. Expired date is clearly marked. |
| Allowance | Available minutes, with a small used/reserved indication or tooltip. Unavailable data is “—”, not an invented zero. |
| Last login | Actual timestamp or Never signed in. Can be omitted at medium widths and retained in details. |
| Actions | View details plus a compact three-dot menu; icon buttons have accessible labels/tooltips. |

Footer: accurate result range, total, page size and pagination. Do not display example values as live production data.

At mobile widths use stacked account cards with name/email, package, account status, expiry and available allowance. Keep the same actions in the card menu. Avoid horizontal page scrolling.

### Per-account actions

View details; Edit account; Manage package; Reset password; Revoke sessions; Suspend / Reactivate; Archive / Restore. Show only actions valid for the account's current state. Avoid hard-delete in the normal action menu.

Action menus reposition within the viewport. Give the small list enough height; scroll only when needed on very short screens, with hidden scrollbar chrome.

## 5. Create User Account drawer

Use a compact right drawer, about 520–560px wide on desktop and full width on mobile. Fixed, polished header with users icon, title, short description and close button. Only the body scrolls; hide its scrollbar chrome. Keep Cancel and Create Account in a fixed footer. Protect unsaved changes on close.

### Fields

| Field | Required | Default / validation / display |
|---|---|---|
| Full name | Yes | Empty; trim whitespace; max 100 characters. |
| Email | Yes | Empty; normalize case/whitespace; valid format; max 254 characters; unique. Do not auto-create if a duplicate exists. |
| Initial password | Yes | Empty; masked with reveal toggle. Use the app's strong new-password rules: at least 12 characters with upper/lowercase, number and symbol, and within its supported byte limit. |
| Confirm password | Yes | Must match; never prefilled. |
| Generate password | Optional action | Generate a strong random password; populate both fields. No universal/default password. |
| Copy initial password | Optional action | Explicit Admin action near that field; show Copied tooltip for about two seconds. Never expose an existing stored password. |
| Package | Yes | Choose from the real enabled package catalogue. No silent default to the most expensive plan. |
| Package starts | Yes | Now by default; optional scheduled start. Display configured system timezone. |
| Package ends | Derived | Calculate from the selected package's actual validity. Show read-only in the normal create flow. |
| Account status | Yes | Active by default. Admin may choose Suspended if access must be withheld. |
| UI language | Yes | Myanmar / English / Thai only; explicit selection. |
| Internal note | No | Short Admin-only note; not displayed to the customer. |

### Package summary inside the drawer

When a package is selected show its name, validity, included allowance, max upload size, max video duration, allowed processing modes, export formats and concurrency limit **only as defined by that package**. Summarize compactly; allow expanding the limits summary. The account form must not edit global package definitions.

Important dependency: a real package catalogue must exist for the normal create-with-package flow to work. Until Packages is integrated, show **No packages configured** and an actionable explanation; disable package-dependent submission. A design mockup may illustrate Trial/Pro/Premium, but production must not create fake hardcoded packages, prices or allowances.

### Save and handover

1. Validate fields on the server; preserve safe inputs on error, but do not persist passwords in browser storage or logs.
2. Create the customer, initial package period and allowance grant as one transaction. If package assignment fails, do not leave an unexplained half-created usable account.
3. Prevent double submission / duplicate account or allowance grants on repeated requests.
4. Show a success toast and a compact success state: account name, email, User Login URL, assigned package and start/end dates.
5. During this creation interaction only, Admin can copy the newly supplied initial credentials. After the dialog closes, clear password data from client memory; later details show no password/reveal option.
6. Admin gives the login details to the customer. No dependency on outgoing email, invitation acceptance or a first-login setup screen.
7. Customer signs in at `/login` and can use their currently available package immediately.

Do not label manual package assignment as Paid, create an invoice, or add revenue. Record the grant as Admin-assigned; payment confirmation is a separate future workflow.
## 6. User detail drawer

A fixed header shows initials, name, email, account status and close button. Details are contextual to the selected customer; do not add them as sidebar menus.

Use four compact contextual tabs, all backed by real data when implemented:

| Tab | Content |
|---|---|
| Overview | Account information, language, created/updated timestamps, created-by Admin, last login, internal note; Edit and Reset password actions. |
| Package & Usage | Current package/version, grant source, current period, access state, included/used/reserved/available allowance, package limits; Manage package action. |
| Projects | Compact read-only list/count of this customer's actual projects and statuses. Use authenticated, authorized access if opening a project is later supported. No raw/public R2 links. |
| Activity | Account creation, edits, package assignment/change/extension/renewal, password reset, session revocation, suspend/reactivate/archive/restore. Show actor, timestamp and reason; never passwords or session tokens. |

Load the detail successfully before opening the ready drawer using the existing shared loading flow. Do not open a finished-looking drawer and immediately blur it with a second full-screen loader. Background polling stays silent. On fetch failure, show the common error toast and keep the prior screen usable.

There is no existing-password field, reveal-password button, permission editor, API key, or storage credential in customer details. Password management is a separate reset action.

## 7. Edit account and reset password

### Edit account drawer

Fields: Full name, Email, UI language, Internal note. Put package changes in Manage Package and account state changes in their explicit actions rather than hiding them in a large edit form.

- Do not preload a password, a password hash or placeholder text that could be accidentally saved as a password.
- Email remains unique, including archived records and administrator accounts. Existing archived email should guide Admin toward Restore rather than creating a duplicate identity.
- Show a brief warning when changing login email: the user must use the new email, and current sessions will be revoked when saved. Do not falsely label a manually entered new email as verified by email delivery.
- Save validates all changes together. Unsaved close requires confirmation. A stale edit should ask Admin to refresh and review rather than overwrite a concurrent package/status change.

### Reset password dialog

Title **Reset Password**; selected customer's name/email; New password; Confirm password; optional Generate and Copy actions; Cancel and Reset Password.

- Reset means Admin sets a new password. It must work without an email provider.
- Apply strong password validation and confirmation; reveal only what Admin is currently entering.
- Save invalidates that customer's sessions, remember-me tokens and outstanding recovery tokens. It does not reset other users or alter the customer's package, allowance or suspended state.
- Record that a reset occurred, not the secret. No password is displayed after this interaction closes.
- Customer Forgot Password remains available for existing accounts when recovery email is configured. Recovery must never create an account or reactivate a suspended/archived account.

## 8. Manage Package dialog

Keep this flow simple to use while making the resulting allowance and dates explicit. Display current package, account/access state, period dates and allowance first.

Provide three actions inside this contextual dialog:

| Action | Fields / behavior |
|---|---|
| Change package | New enabled package; effective start; read-only calculated expiry; required reason. Show the resulting package and allowance before confirmation. |
| Extend expiry | New expiry date/time; required reason. New expiry must be later than the old expiry and in the future. **Extension preserves used/reserved allowance and does not refill minutes.** |
| Renew package | Configured package, new period start/end, package allowance summary and required reason. A new period/grant is created once; show whether it starts after the current period or is an explicit immediate replacement. |

Rules:

- Global package features and pricing are configured in Packages, not edited in this form.
- Distinguish remaining usable allowance from unused allowance in an expired period.
- Do not automatically refill or roll over minutes because Admin edits name, saves the same package, extends expiry, retries a request or reloads the page.
- Rollover, trial eligibility and renewal allowance follow configured package policy. Do not invent those business rules in the design.
- Package changes apply to new jobs. Running jobs retain the configuration/period and reservation captured when accepted; settle usage against that period once.
- Show a concise before/after summary and confirm before changing a package period or grant.
- A manual Admin grant is allowed without pretending a payment succeeded. Record its source and reason; do not generate payment receipts or fake financial records.
- Later payment/subscription integration must use these same package periods and allowance ledger, not create a competing source of truth.

## 9. Account state and package access are different

| Account status | Login | Generation / account behavior |
|---|---|---|
| Active | Allowed with valid credentials | New jobs also require a currently valid package, available allowance and permitted features. |
| Suspended | Blocked; existing sessions revoked | No new jobs and queued work must not start. Preserve projects, package history and usage records. |
| Archived | Blocked; excluded from normal list | Preserve history and files according to retention policy. Restore is an explicit Admin action. |

| Package/access state | What the customer can do |
|---|---|
| Available | Submit jobs within package features, size/duration/concurrency and allowance limits. |
| Not started | Sign in and see when access starts; no package-dependent new jobs yet. |
| Expired | Sign in and see the expired package/contact-Admin message; no new generation under that expired period. |
| Allowance exhausted | Sign in and view usage; no generation that needs more allowance. |
| Unassigned | Mainly a legacy/incomplete-record state; sign in to an explanatory screen, no generation. Normal new-account creation requires a package. |

Important details:

- Active account does not mean Active package. An expired package must not silently suspend the account or disappear from history.
- Package expiry does not itself delete projects. Existing file availability follows the real retention policy and authorized download rules.
- An accepted running job may finish safely under its captured settings; suspension blocks new/queued starts and login. Explicit cancellation belongs in Job Monitor, rather than destroying running media files from this form.
- Reset password / Reactivate / Restore must not reset usage or create a new package grant.
- Reactivating an account with an expired package makes login available but does not make that package current.
- Archived customer accounts can be restored; normal Archive never hard-deletes financial history, job records or R2 objects.

### Confirmation dialogs

Suspend: identify the customer, require a reason, explain session revocation and no-new-jobs behavior. Button **Suspend Account** uses the error/danger treatment.

Reactivate: identify the customer and show their real package/access state, especially if expired/exhausted. Button **Reactivate Account**.

Archive: identify the customer, require confirmation/reason and explain that login is blocked while records remain. Button **Archive Account**.

Revoke Sessions: identify the customer, explain they must sign in again; package and password stay unchanged. No secret/session IDs in the UI.

## 10. Required UI states and feedback

| State | Required treatment |
|---|---|
| No users | Clean users illustration/icon, short text and Create User Account action. |
| No search results | Keep toolbar/filter values, explain no match, Clear Filters action. |
| No configured packages | Explain the dependency; no invented plan options or silently usable account. Disable the relevant package-dependent submit action. |
| Initial navigation / blocking action | Existing centered circular video loading indicator with background blur. No new loading card, logo or large text box. |
| Background refresh | Silent; do not blur the list/drawer every polling interval. |
| Success / server error | Existing top-right common toast, about three seconds, animated entrance/exit, supported in both themes. |
| Validation error | Clear inline message under the field; focus first invalid input, retain safe values. Never rely solely on a disappearing toast. |
| Copy | Copied tooltip near the copy icon for about two seconds, not a full-width banner. |
| Saving | Disable duplicate submit, show progress in the action button, preserve a legible label. |
| Unsaved close | Confirm discard; Cancel returns to the same input state. |
| Data conflict | Explain that the account changed; preserve the draft and let Admin refresh/review. |
| Request failure | Keep existing account data and an actionable retry; do not optimistically claim success. |
| Open menus/drawers | Keyboard navigation, Escape, focus containment/restoration, backdrop handling, no clipped dropdowns. |

Drawer headers and footers stay fixed. Only their content scrolls, with hidden scrollbar chrome. Never hide overflow in a way that makes fields/actions unreachable. On short mobile/keyboard viewports, accessible scrolling is allowed; do not shrink controls into unusable targets.

## 11. Security and data rules for the later implementation

These are implementation requirements supporting the pictured UI, not permission-management features to add to it.

- All account-management endpoints will live under the authenticated Admin area and enforce the existing Admin boundary on the server.
- Customer records are ordinary users, with `is_admin = false` set by the server. Ignore/reject client attempts to create an administrator or change that flag through this form.
- List/detail/update/reset/status/package actions must exclude administrator identities even if someone substitutes an Admin user ID.
- Public GET/POST registration remains disabled; hiding a button is insufficient.
- Store only password hashes. Never return password hashes, stored passwords, API credentials, R2 secrets or recovery/session tokens in list/detail payloads.
- Protect changes with CSRF, validation and sensible rate limits. Login retains existing generic failure behavior and throttling.
- Use transactions and request deduplication for account creation, package grants and renewals. Concurrent generation must not overspend allowance.
- Allowance is read from the server ledger: included, consumed, reserved, released/adjusted and available. Do not accept a browser-supplied balance or editable Used Minutes field.
- Entitlement checks apply to local/YouTube inputs, analysis, previews, generation and rerender according to configured policy; retries must not double-charge.
- Historical package periods capture the package version/limits used for that grant. Editing a package definition later must not rewrite old usage history.
- Store timestamps consistently and display the configured timezone. Do not compare expiry using the customer's browser clock.
- Existing administrator accounts and their credentials must remain untouched when customer management is implemented.
- Existing Phase 1 customer records need an explicit migration/assignment policy. Do not invent paid packages, free allowance or payment history for them.

## 12. Current sidebar context for all image outputs

```text
Overview
  Dashboard
Customers
  User Accounts                 ← active
  Subscriptions
  Support
Monetization
  Packages
  Payments & Refunds
  Usage Ledger
Operations
  Job Monitor
  Worker Health
Reports
  Revenue
  Usage & Processing
Settings
  API Keys & Models
  System Project Defaults
  Storage & Retention
  Payment Configuration
  General Settings
Administration
  Audit & Security
  Notifications
```

Do not restore Admin Accounts & Permissions, Owner, role selection or permission checkboxes anywhere in these images.

## 13. Images to request from the UI/UX generator

Generate each image separately at a readable resolution. Keep the same frame, spacing, icons, typography, data and component styling between images. Desktop images should use approximately 1440×1000 or 1600×1000; mobile approximately 390×844, with drawer bodies scrollable as needed.

| Image | Required composition |
|---|---|
| 01 | Desktop Dark: User Accounts main list, four compact counters, filters, realistic table, selected User Accounts sidebar item. |
| 02 | Desktop Light: the same main list/data/layout with the locked Light palette and white sidebar. |
| 03 | Desktop Dark: Create User Account drawer open over the list; name/email/password/confirm, package selector and summary, dates/status/language/note, fixed footer. |
| 04 | Desktop Dark: User Detail drawer, Overview tab active with identity/status/package summary and actions; other contextual tabs visible. |
| 05 | Desktop Light: Edit Account drawer, safe profile fields, no existing password field; fixed Save/Cancel footer. |
| 06 | Desktop Dark: Manage Package dialog, Change Package selected; current and proposed package summary, dates, reason and confirmation. Also supply Extend and Renew variants when requested. |
| 07 | Desktop Light: Reset Password dialog, masked new/confirm password, generate/copy controls and session-revocation explanation. |
| 08 | Desktop Dark: Suspend Account confirmation dialog, customer identity, reason, clear consequences and safe Cancel button. |
| 09 | Mobile Dark: User Accounts card list, compact search/filter access, single Create action, existing header and collapsed sidebar. |
| 10 | Mobile Dark: Create User Account as a full-width drawer with fixed header/footer, comfortable touch controls and visible package section. |

Additional useful variants: no users; no packages configured; expired package on an Active account; validation error; creation success with initial-credentials handover; detail Package & Usage tab; Activity tab; Archived filter with Restore action.

Use fictional sample people/emails such as `May Thiri / customer01@example.com` and `Narin Chai / customer02@example.com`. Any illustrated dates, balances or counts are composition samples only, not agreed package quotas or live data. Do not display real customer data, real passwords, keys or server credentials.

Produce English images first for layout clarity and at least one Myanmar mobile variant to demonstrate line wrapping. The language selector must offer exactly Myanmar, English and Thai, with their existing flags.

## 14. Copy-paste request for the image generator

Attach this MD, a screenshot of the current Admin frame, and the supplied ZATLAN logo PNG to the external image-generation conversation. Local paths in this document do not give the external tool access to those images. Do not attach .env files or real account credentials.

> Use this entire attached brief as the source of truth. Design ZATLAN Admin → Customers → User Accounts. The Admin manually creates customer accounts with name, email, password and a configured package. Customers do not self-register; they simply sign in and use their package. There is no Owner role, Roles/Permissions screen, staff invitation flow or additional-admin creation in this form. Preserve the current ZATLAN Admin sidebar/header and the supplied logo. Create a compact premium interface with the exact locked Iris/Mint Dark and Light palettes, very small readable typography and responsive desktop/mobile layouts. Follow every field, state, confirmation and package/allowance distinction in this document. Do not add duplicate sidebar menus, invented payment success, fake live metrics or an oversized hero. Generate Image 01 only first; keep subsequent images consistent when requested. Return polished UI mockup images, not application code.

## 15. Acceptance checklist for reviewing the images and later form

- [ ] One destination: Customers → User Accounts; obsolete Admin Accounts & Permissions absent.
- [ ] Account creation is Admin-controlled, with initial password and package selection; User Login is `/login`.
- [ ] No Owner/Roles/Permissions or customer self-registration UI.
- [ ] List, Create, Details, Edit, Package management, Password reset and account-state actions are covered.
- [ ] Account status and package access are clearly separate; Expired does not mean Suspended.
- [ ] Extension does not refill minutes; renewal is explicit and recorded once.
- [ ] Manual package grants do not pretend a payment occurred.
- [ ] No hardcoded production plans/limits, invented balances, secret exposure or existing-password reveal.
- [ ] Empty/no-package/error/loading/success/copy/unsaved states are designed.
- [ ] Common toasts/loaders and fixed-header drawers follow the existing application conventions.
- [ ] Both themes and all three locales fit desktop/mobile; no horizontal page overflow.
- [ ] The current task only changes menu names/structure and creates this brief; form implementation follows design review.