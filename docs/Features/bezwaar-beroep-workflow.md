# Bezwaar/Beroep Workflow

**Issue:** #86
**Branch:** `feature/86/bezwaar-beroep-workflow`
**PR:** #97

## Overview

Implements AWB-compliant (Algemene wet bestuursrecht) bezwaar (objection) and beroep (appeal) workflows for Dutch municipal case management. Includes pre-seeded case types with all required status types, role types, and workflow templates.

## Architecture

### Backend

The case types ship as the case definition package `lib/Settings/packages/bezwaar-beroep/` (hydra ADR-118). The package holds both case types, their status and role types, and one flow each. Every row carries a fixed id, so a second install recognises its own rows.

The package is `install: on-request`: no upgrade writes it. An administrator installs it with `occ dossiq:package:install bezwaar-beroep`, or through the setup action `POST /api/setup/action/seed`. The package installer writes every row or none and records each one in the shipped-set ledger.

The package was converted once from the old seed by `scripts/packages/seed-to-package.py`, which resolved status names into fixed status ids. The seeder classes that used to do this at runtime are gone.

### Frontend (Vue)

- Pinia store: `src/store/modules/bezwaar.js`
- Case detail components: `BezwaarIntakeForm`, `BezwaarTimeline`, `BezwaarDecisionForm`, `HearingPanel`, `AdvisoryReportPanel`, `DeadlineIndicator`
- Beroep escalation: `BeroepEscalationPanel`, `CourtProceedingsPanel`

## AWB Timeline

| Phase | Status types | Key deadlines |
|-------|-------------|---------------|
| Ontvangst | `ontvangen` | 14-day acknowledgement |
| In behandeling | `in-behandeling` | 6-week standard, 12-week extended |
| Hoorzitting | `hoorzitting-gepland`, `hoorzitting-gehouden` | Date from case |
| Beslissing | `beslissing-concept`, `beslissing-genomen` | Within deadline |
| Beroep | `beroep-ingediend`, `bij-rechtbank` | 6-week filing window |

## Testing

- `tests/integration/packages/bezwaar-beroep.postman_collection.json`: the packages workflow installs the package on a clean instance and reads the case types, statuses, roles, flows and ledger rows back.
- `tests/e2e/packages/bezwaar-beroep.spec.ts`: an administrator installs the package through the setup action and finds both case types in the case type list.
