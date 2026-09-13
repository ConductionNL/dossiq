---
kind: config
depends_on: [remove-casetask]
---

# Proposal: task-search-fields

Competitor gap register, row 9.11 "Task search fields"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S. The register writes the
slug as `task-search-fields (inside remove-casetask)`; `remove-casetask` is
another lane's open change with 3 tasks left, so this lane opens the slug as
its own change that depends on it rather than editing it (re-read file,
deliberate no's section).

## Why

The Tasks page filters on what the generic sidebar offers. `#Tasks` reads
`entitySource: tasks` with six lenses (All, Mine, Unclaimed, Closed, Overdue,
Due this week) and a sidebar with no declared fields. You cannot narrow to
one case, one assignee, a due window, a state or a priority without a lens
that happens to match.

The best competitor in the register: GZAC/Valtimo,
`frontend/projects/valtimo/task-management/src/lib/components/task-management-search-fields/`
(`_round2/compare/M1-functionality.md`).

## What changes

- The `#Tasks` sidebar declares five filters: case (a reference picker),
  assignee (a user picker), due between (a date range), state and priority
  (facets).
- The engine task store maps each to the inbox filter the engine answers.
- Where the engine's inbox has no filter for one of the five, the task
  records it for openregister instead of filtering client-side.

## Ownership

dossiq builds the sidebar declaration and the store mapping. It consumes
OpenRegister's `/api/flow-tasks` inbox filters (`remove-casetask` task 6.4
names the named-argument shape). A filter the inbox lacks is to be
specified in openregister; the register carries no slug for it, so the
task names the parameter.

## ADRs

- Company ADR-058: every filter is a server-side bounded query, never a
  client-side reduce over all rows.
- Company ADR-096: the Tasks page stays a `CnIndexPage` index configured by
  the manifest.

## Capabilities

- Modified: `task-management`: five search fields on the Tasks index.

## Impact

`src/manifest.json` `#Tasks` sidebar; `src/store/engineTasks.js` filter
mapping; `tests/vitest/caseListLenses.spec.js` (the sidebar block); one
e2e spec.
