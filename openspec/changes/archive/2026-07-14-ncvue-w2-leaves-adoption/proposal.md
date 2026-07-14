# Proposal: ncvue-w2-leaves-adoption

kind: code — enablement/configuration of four already-merged nc-vue component-library
features (ADR-Leaf-First: procest enables/configures, it does not reimplement).

## Why

nextcloud-vue's beta branch merged four component-library features procest users cannot
yet see: multi-column sort (PR #202), notes `@mention` (PR #207), saved views (PR #215),
and version history (PR #216). Landing them in the shared library is necessary but not
sufficient — per ADR-Leaf-First, procest is a thin consumer of nc-vue, so each feature
needs an explicit enable/configure step in procest's own manifest, registry and (for
mentions, which needs a real Nextcloud notification) a small backend endpoint.

The published `beta` dist-tag (`1.0.0-beta.210`) predates PR #215 and #216 (npm publish
timestamp `2026-07-13T15:07:40Z`, both PRs merged after that point per
`git log origin/beta`), so a fresh beta had to be published first
(`1.0.0-beta.211`, worktree of `origin/beta` at `369cd763`) before procest could pick up
saved views or version history at all.

## What Changes

- **MODIFIED**: `package.json` — `@conduction/nextcloud-vue` bumped to `^1.0.0-beta.211`.
- **MODIFIED**: `src/manifest.json` — `allowSavedViews: true` added to the six main
  business-object list pages (Cases, Bezwaren, Tasks, Voorstellen, Advice, Beroepen);
  a `version-history` sidebar tab added beside the existing `audit` tab on all 21 detail
  pages; a `notes` sidebar tab (`CaseNotesTab`) added to `CaseDetail` alongside the
  existing `case-notes` body-grid widget.
- **NEW**: `src/views/cases/components/CaseNotesTab.vue` — thin `component:` sidebar-tab
  wrapper around the library's `CnNotesTab` (resolved via the existing `leafTab()`
  helper, the same mechanism already used for calendar/forms/photos), forwarding its
  `mention` event to procest's own notification endpoint. Zero note/mention UI logic is
  reimplemented.
- **MODIFIED**: `src/registry.js` — two new entries: `VersionHistoryLeafTab`
  (`leafTab('version-history')`) and `CaseNotesTab`.
- **NEW**: `lib/Controller/NotesController.php` (`POST /api/notes/mention`),
  `lib/Service/MentionNotificationService.php`, `lib/Notification/Notifier.php` — the
  one procest-side side-effect the shared notes component cannot own itself: turning a
  saved note's `@mention` tokens into real Nextcloud bell-menu notifications.
- **MODIFIED**: `lib/AppInfo/Application.php` — registers the new `Notifier`.
  `appinfo/routes.php` — registers the new route.
- **MODIFIED**: `l10n/en.json` / `l10n/nl.json` — three new English-source keys (both
  files) for the notifier's rendered subject/message text.
- **NOT CHANGED**: multi-column sort — procest has no sort-handling code of its own
  (confirmed via grep: no `sortColumns`/`_order`/`applySort`/`shiftKey` in `src/`); it
  rides `CnIndexPage`/`CnDataTable` automatically once beta.211 lands, no wiring needed.

## Impact

- Affected specs: new `ncvue-w2-leaves-adoption` capability spec (this change).
- Affected code: `src/manifest.json`, `src/registry.js`,
  `src/views/cases/components/CaseNotesTab.vue`, `lib/Controller/NotesController.php`,
  `lib/Service/MentionNotificationService.php`, `lib/Notification/Notifier.php`,
  `lib/AppInfo/Application.php`, `appinfo/routes.php`, `package.json`, `l10n/en.json`,
  `l10n/nl.json`.
- No breaking changes; all four features are additive (new manifest keys, new sidebar
  tabs, new endpoint). Existing `case-notes` body-grid widget and `audit` sidebar tab are
  untouched.
