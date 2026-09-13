---
kind: config
depends_on: []
---

# Proposal: case-followers

Competitor gap register, row 13.18 "Watchers on a case, separate from the
assignee" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner openregister,
slug `object-watchers`, size S. The register's second pick to build first:
"eight of eight non-Dutch systems pass it and dossiq is the only no". This
change is dossiq's half, opened in this lane because the half is a surface
(a Follow action and a Followed lens); the subscription itself is
openregister's `object-watchers`, to be specified there.

## Why

A teamleider who wants to follow a sensitive case without owning it has
no way to. The case knows an assignee and a team; a third person hears
nothing unless they are one of them.

The best competitors in the register: GLPI observers and Zammad My
Subscribed Tickets; eight of eight non-Dutch systems
(`_round4/compare/promoted-rows-batch3.md`).

## What changes

- Header action Follow and Unfollow on `#CaseDetail`, writing the
  platform's per-user, per-object subscription.
- A Followed lens on `#Cases` and a tile Cases I follow on `#MyWorkHome`.
- A Followers section on the People tab of the case.
- Followers receive the case's notifications as the platform's
  subscription defines; dossiq adds no dispatch.

## Ownership

dossiq builds the action, the lens, the tile and the section. It consumes
openregister `object-watchers`, to be specified in openregister under that
slug (row 13.18): the subscription object, the `@me`-scoped query and the
notification fan-out.

## ADRs

- Company ADR-022 and ADR-031: the subscription and the fan-out are the
  platform's.
- Company ADR-097: the lens and the tile add no menu entry.

## Capabilities

- Modified: `case-management`: you follow a case you do not own.

## Impact

`src/manifest.json` `#CaseDetail`, `#Cases`, `#MyWorkHome`;
`tests/vitest/caseActionsMenu.spec.js`, `caseListLenses.spec.js`; one e2e
spec. No PHP.
