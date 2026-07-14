## 1. Publish check (nc-vue)

- [x] 1.1 Verify whether the published `beta` dist-tag (`1.0.0-beta.210`) contains all
      four merged features — confirmed NO (npm publish timestamp predates PR #215/#216
      merge commits)
- [x] 1.2 Publish `1.0.0-beta.211` from a worktree of `origin/beta` (canonical checkout
      branch never switched), `npm ci` + `npm run build` + `npm publish --tag beta`
- [x] 1.3 Verify the publish landed (`npm view dist-tags`) and contains
      `CnSavedViewsControl`/`allowSavedViews`/`CnVersionHistory`/`mentionedUserIds`

## 2. Bump + build

- [x] 2.1 `package.json`: `@conduction/nextcloud-vue` → `^1.0.0-beta.211`
- [x] 2.2 `npm install`; `npm run build` succeeds

## 3. Saved views

- [x] 3.1 Add `allowSavedViews: true` to Cases, Bezwaren, Tasks, Voorstellen, Advice,
      Beroepen (`src/manifest.json` `config`) — the main business-object list pages

## 4. Multi-column sort

- [x] 4.1 Confirm procest has no sort-handling code shadowing CnDataTable/CnIndexPage
      (grep `src/` for `sortColumns`/`_order`/`applySort`/`shiftKey` — zero hits); no
      wiring or test needed, rides the library automatically

## 5. Mentions → real NC notification

- [x] 5.1 Verify at HEAD how procest surfaces notes — found: `CaseDetail`'s `case-notes`
      body-grid widget renders `CnNotesCard` (no `mention` event); only `CnNotesTab`
      (sidebar-tab component) emits `mention`
- [x] 5.2 Add `CaseNotesTab.vue` — thin sidebar-tab wrapper resolving `CnNotesTab` via
      `leafTab('notes')`, listening for `@mention` and POSTing to the new endpoint
- [x] 5.3 Register `CaseNotesTab` in `src/registry.js`; wire as a `component:` sidebar
      tab on `CaseDetail` (`src/manifest.json`)
- [x] 5.4 `lib/Controller/NotesController.php` — `POST /api/notes/mention`
      (`#[NoAdminRequired]`, auth check, payload validation, delegates to the service)
- [x] 5.5 `lib/Service/MentionNotificationService.php` — `IManager`-based notify loop,
      skips self-mentions and duplicates, per-recipient try/catch
- [x] 5.6 `lib/Notification/Notifier.php` — new `INotifier`, registered in
      `Application.php`; `setIcon()` uses an absolute URL
      (`IURLGenerator::getAbsoluteURL()`)
- [x] 5.7 Route registered in `appinfo/routes.php`
- [x] 5.8 i18n: 3 new English-source keys added to both `l10n/en.json` and
      `l10n/nl.json` (parity verified via `npm run test:l10n`)
- [x] 5.9 PHPUnit: `NotesControllerTest`, `MentionNotificationServiceTest`,
      `NotifierTest` (16 tests total)

## 6. Version history

- [x] 6.1 Verify at HEAD how `audit-trail` is registered — found it has NO
      `registry.js` entry (resolves via `CnObjectSidebar`'s hardcoded `BUILTIN_WIDGETS`
      map); `version-history` is NOT in that map, so the literal `widgets:[{"type":
      "version-history"}]` mirror would silently no-op
- [x] 6.2 Register `VersionHistoryLeafTab: { component: leafTab('version-history') }`
      in `src/registry.js` (the same `leafTab()` mechanism already used for
      calendar/forms/photos)
- [x] 6.3 Add a `version-history` `component:` sidebar tab beside `audit` on all 21
      detail pages (`src/manifest.json`)

## 7. Tests

- [x] 7.1 `npm run check:manifest` — Ajv PASS (0 errors)
- [x] 7.2 `npm run test:l10n` — key parity OK
- [x] 7.3 `npm run test:unit` (vitest) — 247/247 passed, 24 files
- [x] 7.4 PHPUnit full suite (php:8.3-cli, mounted at
      `/var/www/html/custom_apps/procest` against the real NC core volume) — 1300 tests,
      16 new + 1269 pre-existing pass; 15 pre-existing failures in
      `LibresignSigningAdapterTest`/`CaseEmailServiceTest` (unrelated `OCP\Files\IRootFolder`
      resolution gap, files not touched by this change — confirmed pre-existing via
      `git log`/`git diff`)
- [x] 7.5 `npm run build` succeeds

## 8. Ship

- [x] 8.1 Validate this change (`openspec validate ncvue-w2-leaves-adoption --strict`)
- [x] 8.2 Commit, merge latest `origin/development`, push, PR (base `development`),
      admin-merge
