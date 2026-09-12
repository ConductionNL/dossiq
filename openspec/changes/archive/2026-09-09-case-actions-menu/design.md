# Design: case-actions-menu

## Context

`CaseDetail` (`src/manifest.json`, route `/cases/:id`, type `detail`) has one
header action, `log-hours` (type `open-form`), and a `case-related` widget in
the `case-panels` tabs. `CaseTypeCopyService::copy()` shows the shape a copy
service takes in this app. `DossiqTxCreateSubCaseNode` creates a case from a
flow and takes `caseType` as its input. `ShippedFlowAdoption` documents that
`TriggerScheduleNode` needs an explicit `runAs`. `@conduction/nextcloud-vue`
2.40.0 ships `CnCopyDialog` and `CnFormDialog`; header actions take the types
`open-form`, `run-action`, `navigate` and `handler`.

ADR-032 kind: **code**. The centre of mass is one service, one controller and
two dialogs. The schema property and the manifest entries ride along.

## Goals / Non-Goals

**Goals:**

- A handler copies a case, starts an allowed flow for it, and plans a
  follow-up case, all from the case page.
- Each write goes through an existing seam: `ObjectService` for the copy,
  OpenRegister's flow run endpoint for Start, a flow object for Plan.

**Non-Goals:**

- Status transitions, suspend, resume, extend and reopen
  (`case-lifecycle-on-the-page`).
- Bulk copy from the Cases index (`one-case-list`).
- A recurring case series. One planned case per Plan action.

## Decisions

### D1: Copy is a service with a fixed field list

`CaseCopyService::copy(string $caseId, array $options): array` reads the
source through `ObjectService`, builds a new `case` with `caseType`,
`requester`, `initiatorType`, `initiatorSourceId`, `initiatorDisplayName`,
`confidentiality`, `priority`, `intakeChannel` and `properties`, sets `status`
to the type's `initialStatus`, `startDate` to today and `relatedCases` to the
source, and writes it. With `options.documents` true it links every open
`caseDocument` of the source to the new case by reference, not by file copy.
`identifier`, `deadline`, `result`, `statusHistory`, `decisions` and
`publications` are never copied. The controller method `copy` on
`CaseActionsController` is `#[NoAdminRequired]` and guards the source case
with the same access check `CaseController` uses.

The Actions entry `copy-case` is type `handler`, bound in
`src/customComponents.js` to a handler that opens `CnCopyDialog` with a title
field prefilled as "Copy of <title>" and a checkbox Include documents, then
posts to `POST /api/case/{caseId}/copy` and navigates to the new case.

### D2: Start reads a list on the case type

`caseType` gains `startableFlows`: an array of `$ref` to OpenRegister `flow`
objects. Flows dossiq ships that are meant for a handler (create sub-case,
send letter) are listed there by the seed. `GET /api/case/{caseId}/startable-flows`
returns those flows for the case's type, with title and description. The
Actions entry `start-flow` (type `handler`) opens
`CaseStartFlowDialog.vue`, which lists them and posts the chosen one to
OpenRegister's run endpoint with the case as `subject`. The `case-flow-runs`
widget then shows the run. A flag on OpenRegister's `flow` schema
(`userStartable`) would replace the list; that is a blocked task.

### D3: Plan a follow-up is a scheduled flow

The entry `plan-follow-up` sits in the Actions menu and is also offered from
the Related cases tab. `CasePlanFollowUpDialog.vue` asks for a case type, a
date and a title, and posts to `POST /api/case/{caseId}/plan`. The controller
writes one OpenRegister `flow` object with a `TriggerScheduleNode` (`runAt`
the date, `runAs` the signed-in user, once) feeding a
`DossiqTxCreateSubCaseNode` with the chosen `caseType`, `title` and
`relatedCases` set to this case. Until it fires, the planned case shows on the
Related cases tab as a planned row read from the flow's schedule. After it
fires, the new case is an ordinary related case.

### D4: Related cases tab shows planned rows

`case-related` (type `related`) cannot list a flow. The planned rows render
from a small `custom` widget `case-planned` under the same tab, reading the
flows whose subject is this case and whose trigger has not fired. It is
deleted when OpenRegister's `related` widget can include scheduled flows.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Copy a case | imperative, `CaseCopyService` | Which fields carry over is a domain rule; no declarative copy exists. |
| Start a flow | declarative: `caseType.startableFlows` plus OpenRegister's run endpoint | The flow is data; the dialog only picks one. |
| Plan a follow-up | declarative: a flow object with a schedule trigger | OpenRegister runs it; dossiq writes it once. |

## Seed Data

- `caseType.startableFlows` on the seeded types points at the shipped
  sub-case and letter flows, so Start has entries on every demo case.
- No new demo cases.

## Risks / Trade-offs

- A copied case with `documents` true links files the copier may not be
  allowed to read on the new case. Mitigated: the link keeps the source
  document's `confidentiality`, and the service only links documents the
  copier can read.
- `TriggerScheduleNode` without `runAs` never fires (memory: or-gotchas). The
  controller sets `runAs` and the unit test asserts it.
- The three new routes are `#[NoAdminRequired]`; each guards the case
  (gate no-admin-idor).
