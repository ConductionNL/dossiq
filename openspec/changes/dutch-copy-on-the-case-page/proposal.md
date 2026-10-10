---
kind: code
depends_on: []
---

# Proposal: dutch-copy-on-the-case-page

## Why

A Dutch reader on the review instance (10 Oct, finding F4) met English in four
places:

- the case's tabs (Overview, Documents, Contact, Tasks, History, Notes) and the
  panels under them (Core case data, Woo publication). Every one of these has its
  Dutch in `l10n/nl.json`; nextcloud-vue's CnTabsWidget draws `tabs[].label` as
  written, and dossiq's CaseSectionsWidget draws `sections[].label` as written,
  so the translation was never asked for;
- the dashboard captions "0 due today" and "met their deadline", which had no
  Dutch entry at all;
- the message after a lifecycle gesture the server could not save, which showed
  the server's English `error`. The backend now answers
  `{"error": "The case could not be changed.", "code": "change_failed"}`, and the
  code was not mapped.

## What Changes

- `translateBannerCopy()` (src/utils/translateLensLabels.js), which already runs
  widget captions and banner copy through the host translate before the manifest
  reaches the renderer, also translates `tabs[].label`, `sections[].label`,
  `moreLabel` and `ariaLabel` of every widget.
- `l10n`: "{tasksDueToday} due today" (Dutch "{tasksDueToday} vandaag af") and
  "met their deadline" ("haalden hun termijn"), as the board DqDashboard words
  them, and "Case details" ("Zaakgegevens") for the tab strip's label.
- `refusalMessage()` maps `change_failed` to "The case could not be changed."
  ("De zaak kon niet worden gewijzigd.").

## Not in this change

- The case page's Termijn tile reads "58 days left" because nextcloud-vue's
  CnCountdownWidget translates "{count} days left" against the library's own
  catalogue, which has no Dutch for it. That is a library catalogue fix.

## Impact

- `src/utils/translateLensLabels.js`, `src/utils/caseLifecycleHelpers.js`,
  `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`.
- Test: `tests/vitest/dutchCopyOnTheCasePage.spec.js` (new).
