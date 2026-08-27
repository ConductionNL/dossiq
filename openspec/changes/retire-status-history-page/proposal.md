---
kind: config
---

# Retire the standalone Status history page

## Why

Dossiq shipped a standalone `StatusRecords` page (menu entry "Status history", relocated into the Reports group) that listed status-change records app-wide. But status/change history is inherently **per-case** context, and `CaseDetail` already surfaces it as a sidebar tab — an `audit-trail` tab titled "Change history". A separate top-level page is redundant navigation: it duplicates data the case detail already shows and adds a menu item that isn't a real workspace.

This change removes the standalone page and its menu entry, leaving the case-detail "Change history" audit-trail tab as the single, in-context surface for a case's history.

## What changes

- `src/manifest.json` — delete the `StatusRecords` page and the `StatusRecordsMenu` menu entry. Add a **"Change history" sidebar tab** to `CaseDetail` (`id: history`, `widgets: [{ type: 'audit-trail' }]`), and remove the equivalent audit-trail **body widget** (`case-audit`) + its layout entry — moving the change log from the crowded overview grid into a dedicated, on-demand sidebar tab.
- `src/menu-layout.json` — remove the `StatusRecordsMenu → AnalyticsGroup` relocation (the entry no longer exists to relocate).
- e2e specs — update `tests/e2e/spec-coverage/settings-pages.spec.ts` (and any other spec that navigated to the retired page) to assert the page is gone rather than present.

Depends on the nc-vue `audit-trail` sidebar-tab widget alias (change `manifest-override-children-merge`) so `widgets: [{ type: 'audit-trail' }]` resolves to `CnAuditTrailTab` in the sidebar.

## Impact

Config-only (manifest + menu layout). No PHP, no schema, no new component — the audit-trail widget already ships in nc-vue. Backward compatible for data: status records remain OpenRegister objects, now surfaced per-case via the CaseDetail "Change history" sidebar tab (verified live: create/read events render with timestamps + users).

## Capabilities

### Modified Capabilities
- `case-history-surface` — change history is a case-detail sidebar tab, not a standalone page/menu item.
