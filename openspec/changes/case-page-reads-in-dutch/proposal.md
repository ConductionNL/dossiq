---
kind: code
depends_on: []
---

# Proposal: case-page-reads-in-dutch

## Why

Rehearsal R2 (15 Oct, Woo case on an nl_NL instance) found the case page reading English or raw codes, and a Files tab without file names:

- The Files tab listed icon-only rows. Its `columns` were declared as `{id, property}`. The library drops a column without `key`, and a non-empty list replaces its default (name, size, modified), so nothing was left to render.
- "Afgeleide prioriteit normal", "Woo-publicatie ready", "Intakekanaal website": enums without `x-enum-labels` show their stored code.
- "No locations linked to this case yet": the map's configured empty text skipped `t()`.

## What Changes

- The Files tab declares `name`, its four row columns in the library's shape (`key`, `source: row`) over `rowDataUrl` (the dossier listing keyed by `fileId`), and `modified`.
- `priorityDerived`, `priorityFloor`, `priorityOverride`, `intakeChannel` and `wooPublicationStatus` carry `x-enum-labels` with Dutch entries.
- `CaseLocationMap` reads a configured empty text through the app catalogue.

Library-side findings from the same round (dates in the browser's locale, the assignee shown as a uid, the action chrome in English, row-action labels untranslated, the Files tab not re-reading after an outside change) are fixed in nextcloud-vue.

## Impact

- `src/manifest.json`, `lib/Settings/dossiq_register.json`, `lib/Settings/register.d/82-woo-publication-on-the-case.json`, `src/components/case/CaseLocationMap.vue`, l10n.
