# Design: deelzaak-support

**Status:** pr-created
**Issue:** #136
**Change:** deelzaak-support

## Summary

Add sub-case (deelzaak) creation and linking to Procest, enabling hierarchical case structures
where a main case (hoofdzaak) can spawn child cases that follow their own workflows while
remaining linked to the parent. Includes vervolg-zaak (follow-up case) support and generic
zaak-relatie management.

## Architecture

All logic is built on top of the existing OpenRegister `case` data model which already has
`parentCase` and `relatedCases` fields, and `caseType` which already has `subCaseTypes`.

### New PHP Classes

| File | Purpose |
|------|---------|
| `lib/Service/DeelzaakService.php` | Core service: create deelzaken, inherit fields, validate allowed types, closure guard |
| `lib/Controller/DeelzaakController.php` | REST endpoints for deelzaak operations |
| `tests/Unit/Service/DeelzaakServiceTest.php` | PHPUnit tests |

### New Vue Components

| File | Purpose |
|------|---------|
| `src/views/cases/components/DeelzaakHierarchyTree.vue` | Recursive tree view of hoofdzaak → deelzaken with status badges |
| `src/views/cases/components/CreateDeelzaakDialog.vue` | Dialog to manually create a deelzaak from an allowed type |
| `src/views/settings/tabs/DeelzaakTypenTab.vue` | CaseTypeDetail tab for configuring allowed deelzaaktypen |

### Modified Files

| File | Change |
|------|--------|
| `appinfo/routes.php` | Add deelzaak REST routes |
| `src/views/cases/CaseDetail.vue` | Replace SubCasesSection with DeelzaakHierarchyTree; wire CreateDeelzaakDialog |
| `src/views/settings/CaseTypeDetail.vue` | Add DeelzaakTypenTab |
| `lib/AppInfo/Application.php` | Register DeelzaakController |

## Data Flow

1. User opens a case → `DeelzaakHierarchyTree` fetches child cases by `parentCase` filter
2. Handler clicks "Create Sub-case" → `CreateDeelzaakDialog` opens, showing only allowed `subCaseTypes`
3. On submit → `POST /api/deelzaak/{caseId}` → `DeelzaakService::createDeelzaak()` creates child, inherits deadline/archief, links `parentCase`
4. On case status change (workflow engine) → `DeelzaakService::createVervolgzaak()` called when trigger status reached
5. On case closure → `DeelzaakService::validateClosureAllowed()` blocks if open deelzaken remain

## Decisions

- Nesting is tracked via the existing `parentCase` field on `case` (supports n levels, no depth limit in data model; ADR-000 already defines this)
- The ZRC nesting guard in `ZgwZrcRulesService::validateHoofdzaakNesting()` applies to ZGW API calls only; UI allows multi-level via the Procest API
- Vervolg-zaak relationships use a `zaakRelatie` object with `aardRelatie=vervolg` (predecessor/successor)
