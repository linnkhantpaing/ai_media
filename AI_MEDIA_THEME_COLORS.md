# AI Media - Locked Theme Colours

The sidebar and header layout are confirmed. Use the POS-derived geometry and interaction with the original approved Iris/Mint palette below. The owner's latest dark/light reference images confirm this combination.

## Confirmed frame and colour contract

- Layout source: resources/js/layouts/app-layout.tsx and resources/css/frame.css, based on ../pos/resources/js/layouts/shop-layout.tsx.
- Keep the confirmed layout: 228 px expanded sidebar, 64 px rail, 56 px desktop header, 36 px controls, always-visible icon submenus and the existing responsive drawer.
- Canonical colours: resources/css/theme.css. The same approved semantic tokens drive authentication and the workspace.
- Do not reintroduce POS yellow/black colour overrides. POS supplies the layout; the locked table below supplies the colours.
- Dark mode uses the approved deep navy sidebar/surfaces, lavender primary and mint accents.
- Light mode uses the approved white sidebar/surfaces, light gray canvas and iris primary. The sidebar must change theme too.
- Compose selection backgrounds, hover states, gradients, shadows and glows from these tokens at reduced opacity. Do not add new base colours.
- Main action buttons use Primary with the matching inverse text; selected navigation uses a translucent Primary/Accent treatment.
- Preserve all Phase 1 menu destinations, small typography, my/en/th language switching and saved Dark/Light preference.
- Images guide the theme; subscription/usage figures and notification counts must come from real implemented data.

## Navigation and dropdown behavior

- Show all Phase 1 child links at all times. Group headings are labels, without expand/collapse controls.
- Place Dashboard and New Project together under Workspace. Every child link has its own icon.
- The whole desktop sidebar animates between 228 px and 64 px. The compact rail retains every destination as an icon.
- The mobile drawer animates both opening and closing; preserve native modal focus handling, Escape and backdrop dismissal.
- Respect prefers-reduced-motion.
- Language selection uses the themed custom listbox in resources/js/components/locale-switcher.tsx and resources/css/locale.css. No browser-native blue option list.
- Support Arrow keys, Home/End, Enter/Space, Tab, Escape and pointer/touch, with a visible selected language and exactly my/en/th.
- Keep all dropdowns within narrow viewports.
- Language flags are local SVG artwork: Myanmar (mm), English / United Kingdom (gb), Thai (th), in public/images/flags. Show flags in the trigger and options; keep native language text and accessible labels. Artwork source: flag-icons; license is included alongside the assets.
- Hide the sidebar scrollbar while retaining vertical scrolling with mouse, touch and keyboard.

## Locked palette

| Token | Dark mode | Light mode |
|---|---|---|
| Primary / Iris | #B8A8FF | #6F5CE7 |
| Primary hover | #CABCFF | #806FEF |
| Primary active | #9E8AFF | #5B4BD6 |
| Secondary / Mint | #72E2C3 | #28B893 |
| Accent / Purple | #8B5CF6 | #8B5CF6 |
| Background | #0B0D14 | #F6F7FB |
| Surface / Card | #141927 | #FFFFFF |
| Surface elevated | #1C2334 | #F0F2F7 |
| Sidebar | #10131D | #FFFFFF |
| Input / Field | #101622 | #F7F8FC |
| Border / Divider | #2C3549 | #DDE2EC |
| Text primary | #F5F6FB | #171A24 |
| Text secondary | #BAC2D5 | #596176 |
| Text muted | #929FB9 | #7B8498 |
| Text inverse / on primary | #1A1333 | #FFFFFF |
| Icon default | #BAC2D5 | #596176 |
| Icon active | #B8A8FF | #6F5CE7 |
| Success | #7BE3AE | #28A86B |
| Warning | #F3CA78 | #C98A18 |
| Error | #FF929F | #D9445A |
| Info | #94BFFF | #477BD6 |
| Background wave start | #161A2D | #F6F7FB |
| Background wave end | #0B0D14 | #EDE9FF |

## Shared colours from the reference

| Purpose | Value |
|---|---|
| White | #FFFFFF |
| Black | #000000 |
| Overlay | #00000080 |
| Focus ring | #B8A8FF33 |
| Selection | #7C6FF833 |

The image does not specify numerical stops for every brand gradient. Compose those effects from the approved primary, primary-hover and accent tokens; do not treat inferred gradient stops as additional approved base colours. Decorative waves may use approved colours at reduced opacity.

## Implementation

- Canonical CSS: resources/css/theme.css. Use semantic CSS variables in resources/css/frame.css and all future components.
- Theme selector offers Dark and Light. Dark is the initial default.
- Persist the visual preference in localStorage under ai-media.theme; this is a display preference, never an authentication token.
- Apply the saved theme before the application paints; keep login and workspace consistent.
- UI locales remain exactly Myanmar (my), English (en), Thai (th).
- Compact typography is an owner requirement: most UI text 11–12 px, inputs about 12 px, headings about 18–22 px. Preserve Burmese/Thai line-height and comfortable touch targets.
- Desktop supports sidebar collapse. Mobile uses a focus-managed drawer and a compact wrapping header.
- Use text-secondary for essential small descriptions. Muted/status colour alone must not carry essential meaning.
- Phase 1 sidebar: Workspace (Dashboard, New Project); Projects (All Projects, Drafts, Awaiting Review, Processing, Completed, Needs Attention); Operations (Job Monitor, Worker Health); Settings (Preferences, API Keys & Models, Project Defaults, Storage & Retention).
- Project Setup, Script Review, Preview Studio, Processing and Result are contextual project-workflow tabs under New Project; do not invent separate unrelated modules.
- Frame destinations are navigation shells until their forms/processing are implemented.
- The reference's subscription/expiry card is Phase 2. Phase 1 shows real private-workspace/admin identity instead of fictional plan dates or remaining credits.
