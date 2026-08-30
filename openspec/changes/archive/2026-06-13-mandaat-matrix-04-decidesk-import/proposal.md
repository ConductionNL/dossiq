---
kind: code
depends_on: [mandaat-matrix-03-escalation-engine]
chain:
  - mandaat-matrix-01-schema-foundation
  - mandaat-matrix-02-authorization-engine
  - mandaat-matrix-03-escalation-engine
  - mandaat-matrix-04-decidesk-import
  - mandaat-matrix-05-case-decision-integration
  - mandaat-matrix-06-temporal-and-conflict
  - mandaat-matrix-07-admin-ui
  - mandaat-matrix-08-user-ui
  - mandaat-matrix-09-tests-and-docs
---

# Proposal: Mandaat-matrix — Member 04: Decidesk Import (code)

Member **4 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-03-escalation-engine`. This member implements `DecideskImportService` and
`MandaatImportController`: fetch a mandateringsbesluit from decidesk, parse its Excel/CSV mandate
table, validate referenced roles, create concept `MandateringsBesluit` + `Mandaat` records,
generate a NEW/CHANGED/REMOVED diff against the prior version, and finalise on approval.

## Why

Legal Affairs maintain the mandaatregeling in decidesk. Importing it automatically — with a
diff-review-then-approve flow — replaces manual mandate-table transcription and keeps the
authorization data model in sync with the legislative source.

## What Changes

1. **`DecideskImportService`** — `importFromDecidesk()`, table parsing (Excel/CSV via
   PhpSpreadsheet), role validation, diff generation, approval finalisation.
2. **`MandaatImportController`** — `POST /api/mandate/import` (preview/diff),
   `POST /api/mandate/import/{importId}/approve`.

## Out of Scope (this member)

Admin UI for the import workflow (member 07). Effective-dating temporal queries (member 06).

## Dependencies

- **mandaat-matrix-01-schema-foundation** (REQUIRED) — MandateringsBesluit + Mandaat schemas
- **decidesk** (REQUIRED) — source besluit + attachment
- **PhpSpreadsheet** — Excel parsing
