# Proposal: Workflow Definition Model

## Summary

Procest needs a declarative workflow-definition format so that case workflows can be configured by tenant administrators instead of hard-coded by developers. Today, the lifecycle of a case is implicit in the `statusType` ordering plus a handful of ad-hoc transition rules scattered between the case controller and frontend buttons. This change introduces a single `workflowTemplate` entity (already drafted in ADR-000 and `lib/Settings/procest_register.json`, but not yet exposed via service, controller, or admin UI) that aggregates the steps, transitions, guards, and automatic actions for a given `caseType`. It establishes the canonical contract that `status-transition-engine` and `role-based-step-routing` consume.

## Problem

The `workflowTemplate` schema lives in `procest_register.json` and the `workflow-definition-model/spec.md` exists in `openspec/specs/`, but no CRUD service or UI exposes it. Case transitions remain implicit and untestable. Tenants cannot pin a case type to a specific workflow version, so any edit to the lifecycle silently mutates in-flight cases. There is no published / deprecated lifecycle, no immutability guarantee, and no migration path from the existing implicit configuration.

## Affected Projects

- [ ] Project: `procest` — Expose the existing `workflowTemplate` schema via `WorkflowDefinitionService` + `WorkflowDefinitionController`, add the draft → published → deprecated lifecycle, add a `caseType.workflowDefinition` pin, build a Vue admin component, and migrate legacy implicit workflows into seeded `workflowTemplate` objects.

## Scope

### In Scope (V1)

- **Declarative WorkflowDefinition entity** (REQ-WDM-1..3): exposing the existing `workflowTemplate` schema with steps, transitions, guards, allowedRoles, and automaticActions as a first-class CRUD object.
- **Draft → Published → Deprecated lifecycle** (REQ-WDM-4..6): published versions are immutable; deprecated versions cannot back new cases but continue to back existing ones.
- **Versioning and caseType pinning** (REQ-WDM-7): `caseType.workflowDefinition` + `case.workflowVersion`; new cases bind to the latest *published* version of the pinned definition.
- **Admin UI surface** (REQ-WDM-8): a settings tab listing definitions per caseType with create / publish / deprecate / clone actions.
- **Backfill migration** (REQ-WDM-9): a repair step that promotes the current implicit lifecycle of every existing caseType into a seeded `workflowTemplate` published as version 1.
- **Consumer contract** (REQ-WDM-10): a stable read-only API (`getActiveDefinitionFor(caseType)`, `getDefinition(id)`) that `status-transition-engine` and `role-based-step-routing` consume — no other path is supported.

### Out of Scope

- **Guard evaluation engine** — owned by `status-transition-engine` (already specced).
- **Role/step visibility filtering** — owned by `role-based-step-routing` (already specced).
- **Visual drag-and-drop editor** — V2 (`visual-workflow-editor` spec already exists; this change ships only a form-based admin UI).
- **Workflow import / export** — owned by `workflow-import-export` (already specced).
- **Cross-tenant template marketplace** — Enterprise / out of scope.

## Approach

1. Wrap the existing `workflowTemplate` register schema with a `WorkflowDefinitionService` (CRUD + lifecycle handlers) and a `WorkflowDefinitionController` (REST endpoints under `/api/workflow-definition`).
2. Add `caseType.workflowDefinition` (UUID reference) and `case.workflowVersion` (int) — both already partly drafted in ADR-000.
3. Build `WorkflowDefinitionsTab.vue` + `WorkflowDefinitionDialog.vue` for the admin settings page.
4. Add a repair step that backfills one published `workflowTemplate` per existing caseType from the implicit ordering of its `statusType` records.

## Cross-Project Dependencies

- **OpenRegister**: object storage, audit trail, relations.
- **`status-transition-engine` (procest)**: consumes the published transitions array.
- **`role-based-step-routing` (procest)**: consumes the `allowedRoles` on transitions and `assigneeRole` on steps.
- **`case-types` (procest)**: needs the new `workflowDefinition` reference field.
