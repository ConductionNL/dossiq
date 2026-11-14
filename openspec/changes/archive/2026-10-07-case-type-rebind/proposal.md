---
kind: code
depends_on: [case-type-version-chain]
---

# Proposal: case-type-rebind

Competitor gap register, row 2.13 "Change case type or version of a
running case" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner dossiq, size
L, one of the register's seven L rows.

## Why

A case filed under the wrong type is closed and refiled, with a new
number, a new term and a broken paper trail. The nearest thing is
`RoutingController::reroute`, which re-routes the assignee role, not the
type. `case.workflowTemplate` and `case.workflowVersion` pin a running
case to its version, and `openspec/specs/zaaktype-versioning/spec.md`
REQ-ZV-02 requires that a running case stays on its version. That rule is
right by default; the coordinator's deliberate exception to it, with a
reason and a mapping, does not exist. Round 2 D12 rules that rebinding a
running case to another type or version is case semantics over the
engine's pinned run.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_case_management_http/routes/routes.py`
(case/casetype/update) (`_round2/compare/M1-functionality.md`).

## What changes

- Header action Change type or version on `#CaseDetail`, for
  `dossiq-coordinators`: pick the target (another version of the same
  type, or another type), map the current status to a status of the
  target, give a reason.
- dossiq validates the mapping: every property the target requires at
  the mapped status is present or asked in the dialog; the result type
  and the decision types are compatible or cleared with a note.
- dossiq writes `caseType`, `workflowTemplate`, `workflowVersion` and
  `status`, records a status record with the reason, and re-arms the
  case's terms against the target's definitions keeping each instance's
  start date (a rebind moves no statutory clock).
- The engine migrates the pinned run to the target's flow definition
  (openregister `migrate-run-between-versions`, row 3.16, paired with
  this row in the register).
- The audit trail carries the old and new binding; the old number stays.

## Ownership

dossiq builds the action, the mapping, the validation, the writes and the
term re-arm: a rebind is case semantics. The case's process is an engine flow since
`workflow-definitions-to-flow` (dossiq, archived 2026-09-09). It consumes
openregister `migrate-run-between-versions`, to be
specified in openregister under that slug (row 3.16), for the run.
`case-type-version-chain` gives the target picker its versions.

## ADRs

- Company ADR-022 and dossiq ADR-005: the run is the engine's; the status
  machinery is dossiq's documented exception until it is not.
- Company ADR-099: the migration runs as the coordinator, recorded as
  `runAs`.
- Company ADR-105 and ADR-050: a refused rebind names the rule with a
  4xx.

## Capabilities

- Modified: `zaaktype-versioning`: a coordinator may rebind a running
  case, with a mapping and a reason; REQ-ZV-02 stays the default.

## Impact

New `lib/Service/CaseRebindService.php` and a controller method;
`src/dialogs/CaseRebindDialog.vue`; `src/manifest.json` `#CaseDetail`;
`lib/Service/TermijnService.php` (re-arm); unit tests and fixture pairs;
one e2e spec.
