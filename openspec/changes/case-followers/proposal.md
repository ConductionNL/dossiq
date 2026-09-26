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
change is dossiq's half: a surface. The subscription itself is
openregister's `object-watchers`, which has since landed on openregister
`development`.

## Why

A teamleider who wants to follow a sensitive case without owning it has
no way to. The case knows an assignee and a team; a third person hears
nothing unless they are one of them.

The best competitors in the register: GLPI observers and Zammad My
Subscribed Tickets; eight of eight non-Dutch systems
(`_round4/compare/promoted-rows-batch3.md`).

## What changes

- A Follow strip on `#CaseDetail`, beside the star, writing the platform's
  per-user, per-object subscription. A strip and not a header action
  (D-1): `api-call` writes POST or PUT only, and stopping is a DELETE.
- A Followed lens on `#Cases` and a tile Cases you follow on `#MyWorkHome`,
  both over the platform's `_watching` lens.
- A Followers section on the People tab of the case.
- The case schema addresses its watchers on a status move and on an
  escalation, through the platform's `{"watchers": true}` recipient block.
  dossiq adds no dispatch.

## Ownership

dossiq builds the strip, the lens, the tile, the section and the recipient
block. It consumes openregister `object-watchers` for the subscription
object, the `@me`-scoped query and the notification fan-out.

## ADRs

- Company ADR-022 and ADR-031: the subscription and the fan-out are the
  platform's.
- Company ADR-097: the lens and the tile add no menu entry.

## Capabilities

- Modified: `case-management`: you follow a case you do not own.

## Impact

`src/manifest.json` `#CaseDetail`, `#Cases`, `#MyWorkHome`;
`src/services/watcherApi.js`, two components, `src/registry.js`,
`src/icons.js`; `lib/Settings/dossiq_register.json` (the case schema's
notification rules, version 1.24.0); `tests/vitest/caseFollowers.spec.js`,
`caseListLenses.spec.js`; `tests/e2e/case-followers.spec.ts`. No PHP.
