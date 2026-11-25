# Help & User Guide

An in-app, searchable user manual for the completed phases (1 – Setup & Authentication, 2 – Super Admin Control System, 3 – Partner Management & Capital). Open it from **Help & User Guide** at the bottom of the sidebar (`/help`).

## Updating an existing install

```bash
git pull
composer install                 # refreshes the autoloader
php bin/console migrate          # 1 new migration (000028): 7 documentation tables
php bin/console db:seed          # adds the documentation module/permissions and the bundled guides (47 for Phases 1–3, 10 more for Phase 4, 14 more for Phase 5)
```

`db:seed` is safe to repeat. It never overwrites a category or guide whose URL name (slug) already exists, so edits made in the app survive.

## Who can do what

| Action | Needs |
|---|---|
| Read published guides, search, view screenshots | Nothing but being signed in. There are no module, role, record or field checks, so every role (including custom roles and users with no role) reads everything that is published. |
| Create and edit drafts, steps, screenshots, FAQs and related guides; reorder; preview; revision history | `documentation.manage` |
| Publish and unpublish | `documentation.publish` |
| Delete guides, empty categories and screenshots | `documentation.delete` |

The three `documentation.*` permissions belong to the new **Help & User Guide** module in the existing permission system. They can be granted to roles, users or templates in the Control Center like any other permission. Super Admins hold them automatically, and **no role gets them by default**. Drafts (and their screenshots) return 404 to everyone without `documentation.manage`.

## Structure

| Table | Holds |
|---|---|
| `documentation_categories` | The categories, one per phase (slug, name, description, icon, order) |
| `documentation_guides` | One guide per row in the standard structure: introduction, what, why, who, prerequisites, example, expected result, notes, common mistakes; plus keywords, status (draft/published), featured flag, view count, audit columns |
| `documentation_steps` | Numbered steps, each optionally linked to a screenshot |
| `documentation_media` | Screenshots with caption, alt text and numbered markers (`annotations` JSON, x/y as % of the image) |
| `documentation_faqs` | FAQs per guide; `guide_id NULL` = general FAQs on the Help home page |
| `documentation_related_guides` | Related-guide links |
| `documentation_revisions` | A full JSON snapshot on every create, save, publish and unpublish |

Guide text is plain text formatted by `App\Support\DocText`. Everything is HTML-escaped first; then `- ` bullets, `1. ` numbered lists, `| a | b |` tables, `> ` notes, `**bold**` and `` `code` `` are recognised. No HTML from authors is ever output.

## Content and screenshots

- **Content:** the guides live in `database/seeders/documentation/<category>/NN-<slug>.txt` (47 files) plus `general-faqs.txt`, and are loaded by `DocumentationSeeder`. The format is described at the top of the seeder.
- **Screenshots:** 56 screenshots (WEBP, about 2.8 MB) are in `resources/help/screenshots`, outside `public/`. They were captured from the running application with demo data. `manifest.json` holds each one's caption and its numbered markers, measured from the real positions of the buttons and fields. All images are served by `HelpController` after the access check.
- **Uploads:** screenshots uploaded in the app (PNG, JPG or WEBP, max 5 MB) are checked for real image content and stored with random names in `storage/uploads/documentation/YYYY/MM`.
- **Replacing a screenshot:** in **Manage guides → edit guide → Screenshots**, upload the new image. Click on it to place numbered markers, label them and save, then select it on the step. Delete the old one.
- **Note:** the bundled screenshots were taken in a build environment that could not load the Lucide icon library. During capture, each icon was drawn with the closest **Material Design Icons** shape, so some icons differ slightly from what you see in the app. Layout, text and data are real.

## Verification

- `tests/Feature/HelpCenterTest.php` (6 tests) covers:
  - every role (Super Admin, Partner, Accountant, Manager, Viewer, a custom role, no role) can read every category, guide and screenshot, and gets 403 on management;
  - guests are redirected to sign-in;
  - drafts and their screenshots return 404 for readers, and draft text never appears in search;
  - a custom role with `documentation.manage` can edit but gets 403 on publish and delete;
  - publishing makes a guide visible to everyone;
  - author text is escaped;
  - search covers step text and FAQs;
  - uploads are validated and markers clamped to the image;
  - the seeder is idempotent and keeps edits, with no guides for future modules;
  - the capital exception-policy setting can be saved (bug fix below).
- Full suite: 125 tests passed (PHP 8.3, SQLite).
- Crawls: 316 help and management pages as a Super Admin and 120 as a Viewer returned no errors or PHP warnings; the Viewer got 403 only on management.
- No JS console errors; the annotation editor and step/FAQ lists were tested in a browser.

## Bug fixed along the way

The **Partner capital** card on *Control Center → Security settings* (the setting *Exception policy: allow withdrawals beyond available capital*) was empty, so the setting could not be changed. `ControlCenterController::security()/saveSecurity()` now load and save the `finance` settings group as well.
