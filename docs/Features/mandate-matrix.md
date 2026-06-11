# Mandate Matrix (mandaat-matrix)

> **Admin guide:** see [`docs/user/mandate-matrix-admin.md`](../user/mandate-matrix-admin.md) for the Dutch-language administrator runbook.

Automates the Dutch mandaatregeling (Awb art. 10:3): replaces static Word/Excel mandate tables with a relational, auditable matrix that authorises decisions, escalates ceiling breaches, and supports waarnemer (acting) assignments.

## Specs

- Chain `mandaat-matrix-01-schema-foundation` ... `mandaat-matrix-09-tests-and-docs`.
- `openspec/changes/mandaat-matrix-01-schema-foundation/specs/mandaat-matrix/spec.md`

## Features

### Schema foundation (V1, member 01)
- Six OpenRegister schemas: `MandateringsBesluit`, `Mandaat`, `OrganisatieRol`, `MedewerkerRolToewijzing`, `MandaatGebruik`, `MandaatEscalatie`.
- Idempotent seed of 7 organisatierollen, 5 toewijzingen (incl. one waarnemer), 2 mandateringsbesluiten, 4 mandaten.

### Authorization engine (V1, member 02)
- `MandaatCheckService::evaluate(zaak, handeling, bedrag)` returns `authorized | niet_bevoegd | plafond_overschreden | subdelegatie_niet_toegestaan`.
- Every authorised exercise produces an immutable `MandaatGebruik` snapshot.

### Escalation engine (V1, member 03)
- Plafond breaches and disallowed subdelegations create `MandaatEscalatie` records routed up the `OrganisatieRol` hierarchy.
- Approval logs back to `MandaatGebruik`.

### Decidesk import (V1, member 04)
- `DecideskImportService` fetches a mandateringsbesluit + attachment, parses the Excel/CSV mandate table with PhpSpreadsheet, validates referenced roles, and produces a NEW/CHANGED/REMOVED diff against the prior version.
- DIV admin approves the diff to finalise the new besluit.

### Case + decision integration (V1, member 05)
- Decision endpoints consult `MandaatCheckService` before persisting.
- Unauthorized actions are blocked and surfaced in the user UI with a link to the escalation flow.

### Temporal + conflict resolution (V1, member 06)
- Effective-dating queries (`asOf(date)`) for roles, assignments and besluiten.
- Waarnemer overlap detection: rejects double active assignments for the same role.

### Admin UI (V1, member 07)
- Rolboomweergave, toewijzingen-tabel, import-wizard met diff-viewer, audit-log.

### User UI (V1, member 08)
- Escalation inbox, "my mandates" view, decision-banner indicating which mandate authorises an action.

### Tests & docs (V1, member 09)
- Unit tests covering all check outcomes, integration tests for escalation/waarnemer/personnel-change, file-level and method-level `@spec` tags, admin documentation.

## Entities

- `MandateringsBesluit`
- `Mandaat`
- `OrganisatieRol`
- `MedewerkerRolToewijzing`
- `MandaatGebruik` (write-once audit)
- `MandaatEscalatie`

See [ADR-000](../../openspec/architecture/adr-000-data-model.md) for field definitions.
