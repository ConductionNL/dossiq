# Design: inspection checklists onto OpenRegister's Task

Decision 175 (Q-dossiq-L5-3, 10 Oct 2026) chose option 1: one template schema
in dossiq, every run on OpenRegister's `Task`, answers in `Task.responses`,
GPS and offline state in the task's `metadata`, the mobile app moved in the
same change, and no OpenRegister change. This file says how.

## D1. One template schema (3a)

`inspectionChecklistTemplate` survives. `inspectieChecklist` (stack A) and
`inspectionChecklist` with its `checklistItem` rows (stack C) fold into it.

The template schema already holds almost everything the other two carry. It
grows four things:

| Added | Where | Why |
|---|---|---|
| `id` | each item | the key an answer names; stack A answered by label, stack C by `checklistItem` uuid, stack B by order |
| `weight` | each item | stack C scores items |
| `parent` | each item | stack C nests items |
| `legacyRef` | template | `<slug>/<uuid>` of the folded source, so the fold runs once per source |

Field by field:

| Source | Template |
|---|---|
| A `name`, `caseType`, `version` | same |
| A `status` draft, active, archived | draft, active, retired |
| A `items[]` | one section named after the checklist, items carried in order |
| A item `type` | `responseType` (same enum values) |
| A item `photoRequired: true` | `photoRequired: if_no`; false is `nooit` |
| A item `options` | `choices` |
| A item `label` | `label`, and `id` = the label, because stack A's reports name items by label |
| C `caseTypeRef` | `caseType` |
| C `active` | `status` active, else retired |
| C `items[]` (uuids) | each `checklistItem` resolved: `question` to `label`, uuid to `id` |
| C item `type` boolean, enum, text, photo | `yes_no_na`, `meerkeuze`, `text`, `photo` |
| C item `weight`, `parent`, `required` | same |

A folded template keeps its version. A source that cannot be read is
reported and skipped, never half-written.

## D2. Every run is a Task (3b)

A run is one task on the case. `kind: inspection`, so an inbox can ask for
runs without reading every row.

| Run | Task |
|---|---|
| case | `objectUuid`, `registerId`, `schemaId` |
| inspector | `assignee`, then `completedBy` |
| external inspector | `performerType: external`, `assignee` = the portal party reference |
| template, version | `templateId`, `templateVersion` |
| frozen template | `templateSnapshot`, written at create |
| started, completed | `startAt`, `completedAt` |
| answers | `responses` |
| photos | `evidence` (file ids; the files stay on the case) |
| overall result | `outcome` |
| remarks | `comment` |

An answer is `{itemId, value?, numericValue?, choice?, comment?, photos?,
gpsAtAnswer?}`. A `yes_no_na` value is `ja`, `nee` or `nvt`, the vocabulary
`ChecklistService` already validates. The legacy vocabularies map onto it
once, at the boundary: `pass`, `conform` to `ja`; `fail`, `non_conform` to
`nee`; `nvt`, `not_applicable` to `nvt`.

One rule decides the outcome, replacing the three that disagreed at the edges.
Over the answered items that apply (`nvt` excluded): no `nee` is `conform`, no
conforming answer is `non_conform`, anything else is `partly_conform`. It
equals stack C's rule always and stack A's whenever every item is answered.

The photo gate and the required-item check are `ChecklistService`'s, run
against the frozen snapshot before the task is completed. A refused run
writes nothing.

`followUpType` does not move. A follow-up is its own task or case (proposal,
"not in scope"), so its existence says what the field used to say.

Immutability after submit comes from OpenRegister: a completed task is
terminal. `ChecklistRunImmutabilityListener` retires with the run schema.

## D3. GPS and offline state live in metadata (3c)

`Task.metadata` is set at create and never interpreted. It carries:

| Key | Meaning |
|---|---|
| `location` | `{lat, lon, accuracy, source}` where the run was done; `source` is `gps`, `sensorless` or `manual` |
| `capturedOffline` | true when the answers were collected without a connection |
| `capturedAt` | the device time of the first answer |
| `inspection` | the `fieldInspection` uuid, when the run belongs to a visit |
| `legacyRef` | `<slug>/<uuid>` of a carried-over run |

No OpenRegister verb changes metadata after create, so nothing in it may need
changing. That is why `syncState` does not move: a run the server holds is
synced by definition, and "queued" and "local" are states of the device, not
of the record. Per-answer GPS sits in the answer (`gpsAtAnswer`), not in
metadata.

## D4. The mobile app moves in the same change (3d)

The store `src/store/modules/inspection.js` and `InspectionPanel.vue` move:

- templates: read `inspectionChecklistTemplate` (active, the case's type)
  through the object store, as they read `inspectieChecklist` today;
- reports: `GET /api/vth/cases/{id}/inspection-results`, now answered from
  the case's inspection tasks;
- submit: `POST /api/vth/cases/{id}/inspection-result`, which creates the
  task and completes it in one request.

An offline client queues one submit per finished checklist and replays it
when it reconnects. It never queues object writes against a result schema,
because there is none. dossiq ships no offline queue today (no IndexedDB code
in `src/`); the delta on `mobiel-inspectie-offline` says what a queue syncs
when one is built.

## D5. The forms-leaf answer is superseded (3e)

`inspection-forms-via-forms-leaf` routed checklist items through the forms
leaf. A native task form writes fields of the subject object, and a case has
no property per checklist question, so that answer cannot hold. Its delta
narrows the forms-leaf requirement to advice forms and points the checklist at
this change. Photos stay files on the case, as that spec already says.

## D6. The external inspector's portal

portaliq lists OpenRegister objects, and a task is not one. OpenRegister's
portal task seam (`PortalTaskService`, flow-portal-task) already lists a
subject's open external tasks and shows one. So an external inspector's run
is a task with `performerType: external` and `assignee` set to their party
reference. It appears in their portal task list without a collection.

Submitting answers goes through a dossiq endpoint action, forwarded with the
verified assertion: dossiq checks the task's stored party reference against
the assertion's subject before it completes the task, the same rule the
assignee check applies inside Nextcloud. The `inspectieRapporten` and
`checklistRuns` collections and the `submitChecklistRun` action retire with
their schemas.

## D7. Order

Step 4 moves in this order, each a pull request:

1. 4.1 the template fold (schema, repair step, every template reader on the
   one schema);
2. 4.2 runs onto Task (`InspectionRunService`, the two VTH endpoints, the
   store and panel), with legacy runs carried over by a repair step keyed on
   `legacyRef`;
3. 4.3 the portal inspector onto the task seam.

Step 5 removes the six schemas, the listener, the stack C template service
paths and the portal collections. It lands one release after step 4, the way
tenancy 6.4 does: OpenRegister keeps a schema's rows only while the schema is
declared, so the carry-over has to have run on every instance first.
