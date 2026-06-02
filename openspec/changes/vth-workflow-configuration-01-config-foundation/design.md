# Design: vth-workflow-configuration-01-config-foundation

## Architecture

This is a `kind: config` member (ADR-032). Its centre of mass is declarative JSON loaded by idempotent repair steps. It follows the procest convention of importing OpenRegister data via repair steps (`lib/RepairStep/*`) rather than migrations, so that peer-app autoloaders (OpenRegister) are available at import time.

## Declarative-vs-imperative (ADR-031)

| Concern | Declarative artefact | Why not a service |
|---|---|---|
| VTH workflow shape | `lib/Settings/templates/vth-*-workflow.json` | Statuses/roles/transitions are data, not code |
| LHSO matrix | `lib/Settings/lhso_matrix_seed.json` | 16 fixed lookup cells — pure reference data |
| Seed cases | `lib/Settings/vth-seed-cases.json` | Demo/reference objects materialised via ObjectService |

The repair steps are the thin imperative glue that loads the declarative artefacts; they contain no business logic beyond idempotency guards.

## Seed

- `VthWorkflowConfigRepairStep` orchestrates, in dependency order: (1) register the three workflow templates, (2) seed leges rule sets, (3) seed beschikking templates, (4) seed inspection checklists, (5) seed LHSO matrix, (6) seed VTH cases.
- `LhsoMatrixRepairStep` loads the 16-cell matrix; `VthSeedDataRepairStep` loads the 9 cases.
- All steps are idempotent: existence is checked (by template name / matrix cell key / case external id) before create.
- Objects are written via OpenRegister `ObjectService` (ADR-001), never custom mappers (ADR-022).

## Security (ADR-005)

Repair steps run in the install/upgrade context (admin-only by construction). No user-facing endpoints are added in this member.

## Data Model

Workflow templates are OpenAPI 3.0 + `x-openregister` extension documents. Statuses, roles, document-type requirements, and transition guards are expressed as template properties. The integration test asserts template validity and the materialised counts (3 templates, 16 LHSO cells, 9 cases).
