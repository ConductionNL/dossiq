# Design

## Context

`CaseDetail` already registers an `audit-trail` sidebar tab (`id: case-audit`, title "Change history"), and `main.js` registers the `audit-trail` widget type (`AuditTrailWidget`). The standalone `StatusRecords` page therefore duplicated an existing, in-context surface.

## Decisions

### D1 — Delete the page rather than hide it

The `StatusRecords` page has no deep-link or e2e value that the per-case audit-trail tab doesn't already cover, so it is removed outright (page + menu entry + relocation) rather than kept routable-but-hidden. This keeps the manifest honest — no orphan page behind a removed menu item.

### D2 — Reuse the existing audit-trail tab as-is

No new component or label change is required: the `CaseDetail` tab is already titled "Change history" and backed by the registered `audit-trail` widget. This change is config-only.

## Declarative-vs-imperative decision (ADR-031)

N/A — manifest/menu configuration only. No OpenRegister schema, lifecycle, aggregation, or notification behaviour is introduced or modified. Status records remain OpenRegister objects; only their navigation surface changes.

## Risks

- **A test or deep-link references the retired page** — mitigated by sweeping `tests/` for `StatusRecords` and updating the assertions to expect its absence.
