---
kind: code
depends_on: [case-types-02-backend-validation]
chain:
  - case-types-01-seed-and-stores
  - case-types-02-backend-validation
  - case-types-03-result-role-tabs
  - case-types-04-property-doc-decision-tabs
---

## Why

This is **member 3 of 4** in the `case-types` chain (decomposed from the oversized
`case-types` change per ADR-032). Predecessor: `case-types-02-backend-validation`.
Successor: `case-types-04-property-doc-decision-tabs`.

The V1 admin tabs for configuring case-type sub-entities are missing from the UI.
Without them, administrators cannot configure result types (archival rules required
by the Archiefwet/Selectielijst) or role types (allowed participant roles) without
raw API calls. This member ships the first two tabs and wires the tab framework
into `CaseTypeDetail.vue` so the remaining tabs (member 04) can slot in.

It consumes the stores registered in member 01 (`result-type`, `role-type`) and
the seed data those stores display.

## What Changes

- **REQ-CT-07**: Result type management tab — CRUD for result types with archival
  rules (`archiveAction`, `retentionPeriod`, `retentionDateSource`).
- **REQ-CT-08**: Role type management tab — CRUD for role types with generic role
  classification.
- **Tab integration** — register the new tab components in `CaseTypeDetail.vue`
  and add the tab entries (this member establishes the integration point that
  member 04 extends).

## Impact

- **Frontend**: two new Vue tab components in `src/views/settings/tabs/`:
  `ResultTypesTab.vue`, `RoleTypesTab.vue`.
- **Frontend**: `src/views/settings/CaseTypeDetail.vue` — add the Results and Roles
  tab entries and the framework for the member-04 tabs (Properties, Docs, Decisions).
- **No new schemas, no backend changes** in this member.
- Consumes member-01 store registrations and seed data.
