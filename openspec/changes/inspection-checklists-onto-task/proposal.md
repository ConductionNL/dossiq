# Inspection checklists onto OpenRegister's Task

## Why

`dossiq-duplication-to-abstractions` task 3.2 names seven inspection schemas
and grades the cluster `mechanics only`: nobody had compared it field by
field. This change does that comparison, records what it found, and lists the
decisions the move needs before any data goes anywhere.

It follows the five-step shape of `tenancy-onto-openregister-organisation`:
pin, map, decide, move, remove. Only the map is done here. Nothing is
deleted by this change until its decisions are made.

## What the map found

### Dossiq runs three checklist stacks, not one

The seven schemas are three separate stacks, each with its own writer and its
own reader. They never meet.

| Stack | Schemas | Written by | Read by |
|---|---|---|---|
| A, mobile (Dutch) | `inspectieChecklist`, `inspectieRapport` | `src/store/modules/inspection.js` | `InspectionPanel.vue`, the portal's `inspectieRapporten` contribution |
| B, versioned runs | `inspectionChecklistTemplate`, `inspectionChecklistRun` | `VthChecklistSeeder` and the config store in `StoreController` (templates); runs only by the portal's `submitChecklistRun` update | `ChecklistRunImmutabilityListener`, `ChecklistPayloadReader`, the portal's `checklistRuns` contribution |
| C, admin CRUD (VTH) | `inspectionChecklist`, `inspectionResult`, `checklistItem` | `InspectionChecklistService` via `/api/vth/checklists` and `/api/vth/cases/{id}/inspection-result` | `ChecklistsTab.vue` in settings |

Each stack has a template and a result. So the cluster holds three template
schemas and three result schemas for one concept. Stack B creates no run
anywhere in `lib/` or `src/`: the portal can only update a run that already
exists.

The main specs split the same way. `inspection-checklists` specifies stacks A
and C. `mobiel-inspectie-offline` names A and B.

### What OpenRegister's `Task` covers

Measured against `lib/Db/Task.php` on openregister `development`, 10 Oct 2026.

**The run maps onto `Task`.** Every result schema is "one inspector worked
through one template on one case". That is a task on the case.

| Run field (stack B name) | `Task` column | Fit |
|---|---|---|
| `case` | `objectUuid` + `registerId` + `schemaId` | clean |
| `inspector` | `assignee`, `completedBy` | clean |
| `assignedInspectorRef` (external) | `performerType` + `candidateRole` | partial: the portal scope reads this field today |
| `template`, `templateVersion` | `templateId`, `templateVersion` | clean |
| `templateSnapshot` | `templateSnapshot` | clean, same meaning: frozen at start |
| `startedAt`, `completedAt` | `startAt`, `completedAt` | clean |
| `status` (draft, in_execution, submitted, archived) | `state` (available, active, completed, terminated) | clean once mapped |
| `responses` | `responses` (append-only log) | partial: see items below |
| `photos` | `evidence` | clean |
| `overallResult` | `outcome` | clean |
| `followUpType` | `outcome` bag, or a follow-up task | partial |
| `location` (GPS) | none | **no column** |
| `syncState` (offline) | none | **no column** |
| `inspection` (mobile session) | `parentTaskId` | partial: only if the session is a task too |

The append-only rule after submit (REQ-IC-8, enforced today by
`ChecklistRunImmutabilityListener`) comes free: a completed task is terminal.

**The template does not map.** `Task.templateId` is a free string. OpenRegister
has no task template entity, no template version lineage and no published or
retired state. The `flow-task-forms` spec rules a form definition out on
purpose: "The system SHALL NOT introduce a form-definition record, a form
version lineage, or a field type vocabulary of its own."

**The checklist item does not map.** `Task.checklist` is a list of
`{id, label, description, checked}`. A checked box is all it holds. Dossiq's
item carries a typed answer (`boolean`, `enum`, `text`, `photo`), a `weight`
for the score and a `parent` for nested items. The DqInspectie board draws
three answers per question (Ja, Nee, Niet van toepassing) and a photo with GPS
per question. None of that fits a checked box. A native task form does not
help either: it writes fields of the subject object, and a case has no
property per checklist question.

### The grade

`mechanics only` becomes **partial, run only**. The run moves onto `Task`. The
template and its items stay in dossiq, as one schema instead of three, until
OpenRegister grows a typed checklist.

The internal duplication is most of the win, and it needs no OpenRegister
change at all: three template schemas become one, and three result schemas
become `Task`.

## Decisions (step 3, decision 175, 10 Oct 2026)

Ruben chose option 1 of Q-dossiq-L5-3. design.md says how.

- **3a.** `inspectionChecklistTemplate` is the one template schema. The other
  two fold into it (design D1).
- **3b.** Typed items stay in dossiq's template. Answers go into
  `Task.responses`. OpenRegister's `Task.checklist` is not extended (D2).
- **3c.** GPS and offline state go into the task's `metadata`, written once at
  create (D3).
- **3d.** The mobile app (store and panel) moves in this change (D4).
- **3e.** `inspection-forms-via-forms-leaf` is narrowed to advice forms; its
  delta is in this change (D5).

## What is not in scope

The inspection itself (`fieldInspection`, the visit and its planning) is not a
checklist and is not touched. Nor are handhaving follow-ups: a run may start
one, but the follow-up stays its own case or task.

## Impact

- Schemas: `inspectionChecklistTemplate` grows item `id`, `weight`, `parent`
  and template `legacyRef`. Step 5 retires `inspectieChecklist`,
  `inspectieRapport`, `inspectionChecklistRun`, `inspectionChecklist`,
  `inspectionResult` and `checklistItem`.
- Runs: OpenRegister `Task`, kind `inspection`. No OpenRegister change.
- Code: `InspectionChecklistService`, `InspectionChecklistController`, the
  inspection store and panel, the settings checklists tab, the portal
  inspector contribution; `ChecklistRunImmutabilityListener` retires.
- Specs: deltas on `inspection-checklists`, `mobiel-inspectie-offline` and
  `inspection-forms-via-forms-leaf`.
