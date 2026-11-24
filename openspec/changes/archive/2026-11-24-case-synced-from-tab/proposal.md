# A synchronized case says where it came from

## Why

Board `integriq/IqGesynchroniseerdVan` draws a "Synced from" tab on a dossiq case: the source system via Integriq, the record there, the last synchronization, who owns the fields, and the latest log entries for this record. integriq now answers this per record (`GET /apps/integriq/api/provenance`, integriq change `record-provenance-tab`). dossiq only has to show it.

## What changes

- A tab "Synced from" on `CaseDetail`, two library widgets over that endpoint: a facts card and a log table. Configuration only, no dossiq code.
- The tab is there only for a case a synchronization wrote: its `visibleWhen` asks the same endpoint, so without integriq, or for a case made in dossiq, there is no tab.

## Impact

`src/manifest.json`, `l10n/`, `tests/vitest/caseSyncedFromTab.spec.js`. Needs integriq with `record-provenance-tab`.
