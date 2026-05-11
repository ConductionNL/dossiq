# Design: workflow-definition-model

## Architecture Overview

A `workflowTemplate` (aliased `WorkflowDefinition` in code and docs) is a single OpenRegister object that aggregates the lifecycle of one `caseType`: ordered steps, allowed transitions, guards, allowed roles, and automatic actions. It lives behind a thin `WorkflowDefinitionService` that enforces the draft → published → deprecated lifecycle and version pinning. `status-transition-engine` and `role-based-step-routing` are *consumers only* — they never mutate definitions.

```
AdminSettings.vue
└── WorkflowDefinitionsTab.vue (list per caseType: version, status badge, isActive)
    └── WorkflowDefinitionDialog.vue (form: title, description, steps[], transitions[])
        ├── WorkflowStepsEditor.vue (ordered steps, assigneeRole, checklist)
        └── WorkflowTransitionsEditor.vue (from/to status, label, guards, allowedRoles)

CaseTypeDialog.vue
└── workflowDefinition reference field (picker of published definitions)

Backend
├── WorkflowDefinitionService.php (CRUD + lifecycle: publish, deprecate, clone)
├── WorkflowDefinitionController.php (REST: /api/workflow-definition/*)
└── Migration/BackfillWorkflowDefinitions.php (repair step)
```

## Entity Model

`workflowTemplate` (already in `procest_register.json`):

| Property | Type | Description |
|----------|------|-------------|
| `title` | string | Human-readable name |
| `description` | string | Purpose and usage notes |
| `caseType` | uuid ref | Owning case type |
| `version` | integer | Monotonically increasing per `caseType` |
| `isActive` | boolean | Convenience flag; one active version per caseType |
| `isDraft` | boolean | If true: editable; if false: published and immutable |
| `lifecycleStatus` | enum | `draft` \| `published` \| `deprecated` (new — replaces the boolean pair semantically) |
| `steps` | json string | Array of `WorkflowStep` (id, title, status, order, assigneeRole, isRequired, checklist, automaticActions) |
| `transitions` | json string | Array of `StatusTransition` (id, fromStatus, toStatus, label, guards, allowedRoles, automaticActions) |
| `nodePositions` | json string | x/y map for the future visual editor |
| `parentWorkflow` | uuid ref | Inheritance (Enterprise) |

`caseType.workflowDefinition` (new): UUID reference to the active `workflowTemplate`. New cases pin to whichever version was `published` and `isActive` at the moment of case creation.

`case.workflowVersion` (already in ADR-000 as `workflowVersion`): the integer version this case is locked to. Determines which definition `status-transition-engine` loads when displaying transition buttons.

## Lifecycle

```
            publish()                  deprecate()
   draft  ─────────────►   published  ────────────►   deprecated
     ▲                          │
     │       clone()            │
     └──────────────────────────┘
```

- **draft** — editable; cannot back new cases; can be `publish()`ed.
- **published** — immutable; can back new cases; only one published+`isActive` version per caseType at a time; can be `deprecate()`d or `clone()`d to a new draft.
- **deprecated** — immutable; cannot back new cases; existing cases continue to use it (frozen).

Publishing a new version automatically deprecates the previous active version for the same `caseType`. Cloning a published version produces a new draft with `version = previous + 1`.

## Storage Decision

`workflowTemplate` is stored as a single OpenRegister object with two large JSON-encoded string fields (`steps`, `transitions`). This matches the existing schema in `procest_register.json`. We considered exploding steps and transitions into separate entities (`workflowStep`, `workflowTransition`), but this would: (a) require joins on every read in a hot path; (b) duplicate the immutability discipline across three tables; (c) make publish-time snapshotting harder. The single-object approach keeps the published version trivially immutable — the whole record is locked.

## Editor UI (Low-Fidelity)

```
┌─ Settings › Workflows ────────────────────────────────┐
│  Case type:  [Omgevingsvergunning ▼]                  │
│                                                       │
│  ┌─ Versions ───────────────────────────────────────┐ │
│  │ v3 ● Published (active)    [Clone] [Deprecate]   │ │
│  │ v2 ○ Deprecated                                   │ │
│  │ v1 ○ Deprecated                                   │ │
│  └──────────────────────────────────────────────────┘ │
│                                                       │
│  [+ New draft from v3]   [+ Empty draft]              │
└───────────────────────────────────────────────────────┘

┌─ Edit draft v4 ────────────────────────────────────────┐
│  Title: [Omgevingsvergunning — strenger toezicht    ]  │
│                                                        │
│  Steps                                  [+ Add step]   │
│  ┌────────────────────────────────────────────────────┐│
│  │ 1. Intake-controle    status: Ontvangen  role: …  ││
│  │ 2. Inhoudelijke beoor status: In behand  role: …  ││
│  └────────────────────────────────────────────────────┘│
│                                                        │
│  Transitions                          [+ Add transition│
│  ┌────────────────────────────────────────────────────┐│
│  │ Ontvangen → In behandeling  label: "Start"  guards:││
│  │                                  [edit] [remove]   ││
│  └────────────────────────────────────────────────────┘│
│                                                        │
│  [Save draft]   [Publish…]                             │
└────────────────────────────────────────────────────────┘
```

Form-based only in V1. The visual editor (`visual-workflow-editor` spec) is V2 and consumes the same model.

## Integration with Other Specs

- `status-transition-engine` calls `WorkflowDefinitionService::getActiveDefinitionFor($caseType)` (or `getDefinition($id)` when a case is pinned) and reads `transitions[]` + `guards[]` to compute available transitions for the current user.
- `role-based-step-routing` reads `steps[].assigneeRole` and `transitions[].allowedRoles` from the same object to filter visibility.
- `visual-workflow-editor` (V2) reads/writes the same `workflowTemplate` object plus `nodePositions` for layout.
- `workflow-import-export` (V2) serialises and deserialises `workflowTemplate` JSON.

## Consumer API (Read-Only)

| Method | Purpose |
|--------|---------|
| `getActiveDefinitionFor(string $caseTypeId): ?array` | Latest published+active definition for a caseType |
| `getDefinition(string $id): array` | Specific definition by UUID (used when a case is pinned) |
| `getDefinitionForCase(string $caseId): array` | Resolves through `case.workflowVersion` to the exact pinned version |
| `listVersions(string $caseTypeId): array` | All versions of a definition for the admin UI |

## Migration Plan

The repair step `BackfillWorkflowDefinitions` runs once per app upgrade and for every existing `caseType` that has no `workflowDefinition` reference:

1. Read all `statusType` records for the caseType ordered by `order`.
2. Synthesise a `steps` array (one step per non-final status, no assigneeRole).
3. Synthesise a `transitions` array linking each status to the next in order, with no guards.
4. Create a `workflowTemplate` with `version: 1`, `lifecycleStatus: published`, `isActive: true`.
5. Set `caseType.workflowDefinition` to the new template UUID.
6. For each existing open case, set `case.workflowVersion = 1`.

The repair step is idempotent (skips caseTypes that already have `workflowDefinition` set).
