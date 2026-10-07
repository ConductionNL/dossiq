---
kind: config
depends_on: []
---

# Proposal: admin-inspect-entry

Competitor gap register, row 2.22 "Raw case inspection for admins (data,
variables, logs)" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
openregister, slug `admin-inspect-entry (dossiq)`, size S. This is
dossiq's half; the two surfaces it opens are OpenRegister's.

## Why

When a case misbehaves, an administrator opens three pages to see what it
is. `#StatusRecordDetail` shows `evaluatedGuards` and `dispatchedActions`,
the sidebar shows the audit trail, and the raw object and the flow run
variables are in OpenRegister. Nothing on the case page leads there.

The best competitor in the register: GZAC/Valtimo,
`frontend/projects/valtimo/case/src/lib/case-inspection/`
(`_round2/compare/M1-functionality.md`).

## What changes

- Header action Inspect on `#CaseDetail`, admin only, with two entries:
  Raw data, opening nextcloud-vue's `CnObjectMetadataModal` on the case
  object, and Flow runs, opening OpenRegister's runs page filtered to the
  case as subject.
- Nothing is copied into dossiq.

## Ownership

dossiq builds the action. It consumes `CnObjectMetadataModal`
(nextcloud-vue, shipped) and OpenRegister's flow runs page (shipped, the
one `case-flow-runs` on `#CaseDetail` already links rows into).

## ADRs

- Company ADR-022: raw data and run variables are OpenRegister surfaces.
- Company ADR-110: the Flow runs entry leaves the app and is labelled as
  such.

## Capabilities

- Modified: `case-management`: an admin inspects a case from its page.

## Impact

`src/manifest.json` `#CaseDetail` header action;
`tests/vitest/caseActionsMenu.spec.js`; one e2e spec. No PHP.
