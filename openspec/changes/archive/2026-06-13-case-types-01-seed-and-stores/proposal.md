---
kind: config
depends_on: []
chain:
  - case-types-01-seed-and-stores
  - case-types-02-backend-validation
  - case-types-03-result-role-tabs
  - case-types-04-property-doc-decision-tabs
---

## Why

This is **member 1 of 4** in the `case-types` chain (decomposed from the oversized
`case-types` change per ADR-032). Predecessor: none — this is the declare-first
member. Successors: `case-types-02-backend-validation`,
`case-types-03-result-role-tabs`, `case-types-04-property-doc-decision-tabs`.

Zaaktype (Case Type) Management is the highest-demand capability across Dutch
municipality tenders — required by 199 tender mentions with a demand score of 603.
The MVP tier (core case type CRUD, status type management, draft/published lifecycle)
is already functional, but the V1 admin tabs and backend enforcement are missing.

Before any tab UI or backend guard can be built, the feature needs its **data
declared and queryable**: the five sub-entity object stores registered so the
frontend can fetch them, the on-install seed data so QA and browser tests have
realistic Dutch case types to work against, and the translation keys the later
UI members will consume. This member declares all of that. The five sub-entity
schemas (`resultType`, `roleType`, `propertyDefinition`, `documentType`,
`decisionType`) already exist in `procest_register.json` — no schema changes.

## What Changes

This member is the **declarative foundation** the chain consumes:

- **REQ-CT-17**: Case type seed data — 4 realistic Dutch case types
  (Omgevingsvergunning, Subsidieaanvraag, Klachtbehandeling, Bezwaarschrift)
  with full sub-entity configuration (status types, result types, role types),
  imported via the standard `importFromApp()` repair step; idempotent on re-import.
- **Sub-entity store registration** — register the five missing sub-entity object
  stores (`result-type`, `role-type`, `property-definition`, `document-type`,
  `decision-type`) so the consumer members (03, 04) can fetch them.
- **Translations** — declare the en/nl translation keys that the tab UI members
  will reference (tab labels, form field labels, archival action options).
- **Deduplication check** — confirm all five sub-entity schemas are pre-registered
  and no duplicate stores/services/controllers are created (ADR-012).

## Impact

- **Seed data**: `lib/Settings/procest_register.json` — add 4 mock `caseType`
  objects plus 14 `statusType`, 12 `resultType`, and 13 `roleType` mock objects
  under `components.objects[]` with `@self` slug-based idempotency.
- **Frontend (declarative registration)**: `src/store/store.js` — register the
  five sub-entity object types (kebab-case, ADR-015).
- **i18n**: `l10n/en.json`, `l10n/nl.json` — add the new user-visible string keys.
- **No new schemas** — all entities are pre-defined in ADR-000 and already
  registered in `procest_register.json`.
