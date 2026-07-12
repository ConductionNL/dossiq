---
kind: feature
---

# Case-list CSV/Excel export via the OR export leaf

## Why

CSV/Excel export on list views is a consensus gap: the 2026-02 feature-counsel report ranked it MUST (management reporting, ENSIA evidence, AVG art. 20 data portability), the 2026-07 market research confirms competitors advertise it, and procest today only exports termijn quarterly reports, mandaat CSVs and dossier ZIPs — the case list itself cannot be exported.

OpenRegister already ships the export leaf: `GET /apps/openregister/api/objects/{register}/{schema}/export?format=csv|json|excel` (ObjectsController::export → ExportService), honouring request filters and the current user. Per ADR-022 procest must consume this, not rebuild it.

## What changes

- New `src/components/export/CaseListExportAction.vue` — a self-contained actions-slot component rendering an "Export" menu (NcActions) with "Export as CSV" and "Export as Excel" entries. On click it builds the OR export URL for `(procest, case)` with the current route query passed through as filters, and triggers the browser download.
- `src/registry.js` — register the component as `CaseListExportAction`.
- `src/manifest.json` — set `"actionsComponent": "CaseListExportAction"` on the `Cases` page (nc-vue `CnPageRenderer` resolves it into `CnIndexPage`'s `actions` slot).
- Vitest unit test for URL construction and menu rendering.

Scope: `Cases` page only in v1. Other index pages (Tasks, Bezwaren, Voorstellen, Beroepen) can adopt the same component with a schema prop in a follow-up once the pattern proves out.

## Impact

Frontend-only; no PHP, no schema changes. Access control is enforced by the OR leaf (export runs as the current user through the OR pipeline). Degrades gracefully: if openregister is absent the Cases page (OR-manifest rendered) is unavailable anyway, so the action never renders without its backend.

## Capabilities

### New Capabilities
- `case-list-export-via-or-export-leaf` — behandelaren export the (filtered) case list as CSV or Excel from the Cases page header via the OpenRegister export leaf.
