# Bezwaar/Beroep Workflow

**Issue:** #86
**Branch:** `feature/86/bezwaar-beroep-workflow`
**PR:** #97

## Overview

Implements AWB-compliant (Algemene wet bestuursrecht) bezwaar (objection) and beroep (appeal) workflows for Dutch municipal case management. Includes pre-seeded case types with all required status types, role types, and workflow templates.

## Architecture

### Backend

**`lib/Service/SeedDataService`**
- Seeds bezwaar/beroep case types from `lib/Settings/bezwaar_seed_data.json`
- Idempotent: checks for existing objects by `identifier` before creating
- Resolves name-to-UUID references in workflow steps/transitions after creation
- Returns a summary with counts: `caseTypes`, `statusTypes`, `roleTypes`, `workflows`, `skipped`

**`lib/Repair/SeedBezwaarBeroepData`**
- `IRepairStep` executed during app installation or upgrade
- Delegates to `SeedDataService::seedBezwaarBeroepData()`
- Skips if OpenRegister is not available (`SettingsService::isOpenRegisterAvailable`)
- Exceptions are caught and logged — never propagated

**`lib/Settings/bezwaar_seed_data.json`**
- Pre-defined case types: `bezwaar` and `beroep`
- Each includes: status types (e.g. `ontvangen`, `in-behandeling`, `beslissing-genomen`), role types, and a workflow template with steps, transitions, and guards

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

Unit tests are in:
- `tests/Unit/Service/SeedDataServiceTest.php` — ObjectService unavailable → failure, missing config → failure, happy path summary structure, idempotency (existing → skipped), seed data file integrity
- `tests/Unit/Repair/SeedBezwaarBeroepDataTest.php` — getName, skip when OpenRegister unavailable, call seed service, info output on success, exception handling
