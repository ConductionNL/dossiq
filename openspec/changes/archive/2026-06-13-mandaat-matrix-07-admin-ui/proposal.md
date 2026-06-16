---
kind: code
depends_on: [mandaat-matrix-06-temporal-and-conflict]
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

# Proposal: Mandaat-matrix — Member 07: Admin UI (code)

Member **7 of 9** in the `mandaat-matrix` chain. Predecessor:
`mandaat-matrix-06-temporal-and-conflict`. This member builds the admin panel for managing the
mandate matrix: `MandaatMatrixSettings` (tabs Besluiten | Rollen | Toewijzingen | Import),
`MandaatMatrixTable`, `MandaatEditor`, and `OrganisatieRolManager`. It consumes the REST surfaces
declared in members 03–06 and the schemas from member 01.

## Why

Legal Affairs and functional admins need a UI to view/edit mandaten per besluit, manage the
OrganisatieRol hierarchy, manage person-to-role assignments (including waarnemers), and run the
decidesk import — without editing JSON or the database directly.

## What Changes

1. **`MandaatMatrixSettings.vue`** — admin settings page with four tabs.
2. **`MandaatMatrixTable.vue`** + **`MandaatEditor.vue`** — view/edit Mandaat records per besluit.
3. **`OrganisatieRolManager.vue`** — hierarchical role tree CRUD + MedewerkerRolToewijzing CRUD
   (incl. waarnemer assignments, end-assignment).

## Out of Scope (this member)

The user-facing bevoegdheden dashboard (member 08). Tests + docs (member 09).

## Dependencies

- **mandaat-matrix-04-decidesk-import** (REQUIRED) — import endpoints for the Import tab
- **mandaat-matrix-01-schema-foundation** (REQUIRED) — schemas the UI edits
