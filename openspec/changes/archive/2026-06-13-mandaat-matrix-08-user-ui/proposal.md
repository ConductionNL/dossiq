---
kind: code
depends_on: [mandaat-matrix-07-admin-ui]
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

# Proposal: Mandaat-matrix — Member 08: User Bevoegdheden UI (code)

Member **8 of 9** in the `mandaat-matrix` chain. Predecessor: `mandaat-matrix-07-admin-ui`.
This member builds the user-facing bevoegdheden view on the case detail page:
`BevoegdhedenPanel.vue` and `MandaatMatrixWidget.vue` — showing the user their authority for the
current zaaktype, filtered by their role(s), with role-holder detail and a "What can I do?" filter.

## Why

Zaakbehandelaars need to self-serve their authority without triggering an escalation: which
mandaten their role(s) hold for this case type, the conditions, the legal basis, the current role
holders, and (when acting as a waarnemer) that relationship — reducing manual mandate lookups.

## What Changes

1. **`BevoegdhedenPanel.vue`** — side panel/modal on case detail showing the filtered mandate matrix.
2. **`MandaatMatrixWidget.vue`** — row-detail expansion (legal basis, role holders, waarnemer note,
   besluit source) + "What can I do?" filter.

## Out of Scope (this member)

Tests + documentation (member 09).

## Dependencies

- **mandaat-matrix-02-authorization-engine** (REQUIRED) — matrix + role resolution endpoints
- **mandaat-matrix-06-temporal-and-conflict** (REQUIRED) — current-validity display
