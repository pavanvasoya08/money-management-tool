# UI Redesign: analysis and component mapping

This document covers the reference screenshots (`ui-design.zip`, 55 images) and how each pattern maps onto the existing Phase 1–3 screens. The redesign is presentation only: routes, controllers, services, permissions and the database are unchanged.

## 1. Screenshot groups

| Group | Files | What they define |
|---|---|---|
| Application shell | every page | White 290px sidebar with uppercase section labels, collapsible groups, light-blue active item; 64px white top bar (menu toggle, search, notifications, settings, avatar); `#f5f5f5` canvas; copyright footer |
| Dashboards | 01–04 | White 16px-radius cards, KPI tiles with round pastel icons, smooth blue line charts, compact tables |
| Lists | 05, 10, 13, 21 (accounts) | Page title and actions *inside* the card; full-width filled search plus outlined Filter button; uppercase grey table headers; avatar, name and email cells; pastel status pills; plain-number pagination |
| Create / edit forms | 06, 07, 11, 12, 14, 15 | Title above; section cards with bold headings; filled grey inputs (48px, 12px radius); right-hand side card; sticky white action bar at the bottom (outlined red *Discard*, blue primary) |
| Detail pages | 08, 09, 16 | Left profile card (avatar, name, label/value list, full-width actions); right card with underline tabs |
| Account settings | 17–19 | Card with a left vertical menu (icon, label, grey active row) and a form on the right |
| Activity log | 20, 28 | Day headings in uppercase; vertical timeline of avatars and icons; grey quoted blocks |
| Roles & permissions | 21–23 | Grey role cards with a description and "Edit role →"; permission rows with an icon tile and outlined toggle buttons |
| Feedback | 24, 29, discard and remove screens | Filled pastel alerts with coloured text; tooltips; modals with a grey footer and a red warning icon |
| Form controls | 30–43 | Checkbox, radio, segment, select (open state), switch, slider, upload drop zone, steps, tabs (underline and pill) |
| Buttons, tags, progress | buttons, 25, 27 | Default (white, grey border), Solid (blue), Plain; sizes 32/40/48/56; 12px radius; pastel tags |
| Auth | 46–48 | Single centred column (400px) on white: logo, 32px heading, filled inputs, full-width blue button |
| Errors | 49 | Centred illustration, "Access Denied!" title, muted subtitle |
| Menus | notification and profile screens | 16px-radius dropdowns: notification list with unread dots and a blue "View all" button; profile header with grouped menu items |

## 2. Design tokens (measured from the PNGs)

| Token | Value | Source |
|---|---|---|
| Font | Inter 400/500/600/700 | All screens |
| Canvas | `#f5f5f5` | Page background, filled inputs, grey tiles |
| Surface | `#ffffff` | Sidebar, top bar, cards |
| Border | `#e5e5e5` (cards, dividers), `#d4d4d4` (default buttons) | Card edge and table rows |
| Text | `#171717` heading, `#404040` body, `#525252` navigation, `#737373` muted, `#a3a3a3` placeholder | Pixel sampling |
| Primary | `#2a85ff`, hover `#1f6fe0`, subtle `#e9f3ff` | Solid button, active nav, info alert |
| Success | `#00bc7d`, soft `#a4f4cf`, alert `#dcfcec` | "Paid" dot, "Active" pill, success alert |
| Warning | `#fe9a00`, alert `#fff3ba` | "Pending" dot, warning alert |
| Danger | `#fb2c36`, soft `#ffc9c9`, alert `#fff0ee` | "Failed" dot, "Blocked" pill, error alert |
| Accent tiles | sky `#b8e6fe`, emerald `#a4f4cf`, purple `#e9d4ff`, amber `#fee685` | KPI icon circles |
| Radius | 12px (controls), 16px (cards, menus, modals), 999px (pills) | Measured corner curves |
| Heights | 64px top bar, 48px inputs and buttons, 40px small, 48px nav rows | Measured |
| Shadow | none on cards; `0 10px 30px -8px rgb(0 0 0 / .12)` on menus and modals | Dropdown screenshots |
| Spacing | 4px scale (4, 8, 12, 16, 20, 24, 32, 40, 48) | Card padding 20–24px, page gutter 32–48px |

## 3. Component → existing screen mapping

| Reference component | Implemented as | Used on |
|---|---|---|
| Sidebar with collapsible groups | `layouts/app.php` and `config/navigation.php` (`group` key) | All app pages |
| Top bar search | Quick-navigation dialog listing the pages *you* may open (client-side filter, no new endpoint) | All app pages |
| Notification menu | Real pending approvals assigned to you (`ApprovalEngine::pendingFor`) and pending access requests (Super Admin) | Top bar |
| Profile menu | Profile, Security, Activity log (if permitted), Sign out | Top bar |
| List card (title, actions and filters in the card) | `.list-card` pattern | Users, partners, capital registers, approvals, audit, activity, sessions, reports |
| Status pill / status dot | `.badge-soft-*` (pill), `.status-dot` (dot + text) | Account and partner states (pill); capital, approval and workflow states (dot) |
| Filled form controls and sticky action bar | `.form-control`/`.form-select` tokens, `.form-actions-bar` | User, partner, capital, role, template, settings forms |
| Profile side card + tabbed card | `.profile-card` + `.app-tabs` | Partner profile, user profile, Control Center user |
| Settings menu card | `.side-menu` | My account (profile and security), Control Center navigation |
| Role cards | `.role-card` | Control Center → Role management |
| Timeline | `.activity-list` (vertical line) | Dashboard activity, partner and user activity, activity log |
| Modal with grey footer | `partials/confirm-modal.php` | Every confirmation (`data-confirm`) |
| Alerts | Bootstrap `.alert-*` restyled | Flash messages, warnings, info notes |
| Auth column | `layouts/auth.php` | Login, forgot, reset, forced password change |
| Access denied | `errors/error.php` (own illustration, not copied) | 403, 404, 419 and 500 pages |

## 4. Constraints kept

- Bootstrap 5 and custom CSS only (no Tailwind); Lucide icons; Chart.js; Inter served from the already-allowed jsDelivr CDN.
- No inline `style` attributes (the strict CSP blocks them); sizes go through classes or `data-width` / `data-height`.
- Hiding a menu item or button is cosmetic only. Every route keeps its server-side `can:` middleware, record scope and field rules.
- No placeholder links. Footer links to non-existent Terms or Privacy pages from the reference were not added.
- The reference brand, logo and illustrations are not reused. The ERP keeps its own name (`app_name` setting) and a neutral logo mark.

## Result & verification

- Design system: `public/assets/css/ui/{tokens,layout,components,modules}.css` (old `app.css` retired). Inter, Bootstrap 5.3.3, Lucide 0.469.0 and Chart.js only.
- Redesigned: auth (login/forgot/reset), error pages, app shell (sidebar groups, mini mode, top bar with quick navigation, live notifications, profile menu), dashboard, partners (list, profile + tabs, forms), capital (contributions, withdrawals, adjustments, ledger), profit sharing, partner reports, approvals, users, activity timeline, account settings, settings, and every Control Center page.
- No business logic, routes, permissions or schema changed. Navigation still hides anything the user may not open, and every server-side check is unchanged.
- Checks: 119/119 automated tests pass; all PHP files lint clean; a crawl of 400 internal pages as Super Admin returned 200 with no PHP warnings or broken links; no JS console errors; desktop (1440px) and mobile (390px) screenshots reviewed against the references.
