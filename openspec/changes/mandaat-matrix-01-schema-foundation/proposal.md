---
kind: config
depends_on: []
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

# Proposal: Mandaat-matrix — Member 01: Schema Foundation (config)

Member **1 of 9** in the `mandaat-matrix` chain. Predecessor: none (this is the
declare-first config member). This member declares the six OpenRegister schemas the whole
mandate-matrix feature reads and writes — `MandateringsBesluit`, `Mandaat`, `OrganisatieRol`,
`MedewerkerRolToewijzing`, `MandaatGebruik`, `MandaatEscalatie` — together with a seed-data
repair step and an integration test that proves the materialised records and their relations
are correct. Once this merges, every downstream code member (02–09) can read these fields
without re-declaring them (ADR-032 expand-then-contract).

## Why

In Dutch local government, municipalities handle hundreds to thousands of delegated decisions
annually (mandaat under Awb art. 10:3). Mandate tables today are static Word/Excel documents
managed by Legal Affairs, producing risk of illegal decisions, lost audit trails, and
operational friction on personnel changes. The mandate-matrix automates this; everything starts
from a verifiable, relational data model — which this member establishes declaratively
(ADR-031 declarative-first, ADR-001 OpenRegister ObjectService).

## What Changes

1. **Six new OpenRegister schemas** registered via the procest register on install.
2. **Idempotent seed data** — 7 OrganisatieRol, 5 MedewerkerRolToewijzing (incl. 1 waarnemer),
   2 MandateringsBesluit (current + predecessor), 4 Mandaat — created through a repair step.
3. **Integration test** verifying materialised records exist with correct cross-references.

## Out of Scope (this member)

All behaviour — authorization checks, escalation, import, UI — lands in members 02–09. This
member only declares the schema metadata and seeds reference data.

## Dependencies

- **procest base** (REQUIRED) — zaaktype, zaak, decision infrastructure
- **openregister** (REQUIRED) — schema registration + ObjectService
