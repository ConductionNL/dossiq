---
kind: config
depends_on: []
---

# Proposal: lists-that-answer

Phase 3 of the round 2 competitor programme, change DQ3. Rows 3.5, 9.11 and
13.6 of `round2-data.json#m1`. Rung 3 on the placement ladder: manifest
configuration on the two index pages `one-case-list` already built, `Cases`
and `Tasks`.

## Why

The lists show rows and do not answer questions. That is the area where
general purpose trackers are furthest ahead of municipal software, and the
round 2 comparison puts us behind all three systems on filtering, saved views
and bulk work.

`one-case-list` closed most of the case-side gap. It left the Tasks index
with three chips against the Cases index's five, and it left the Tasks row
without the priority that REQ-TASK-004's own first scenario has required
since the spec was written. So the two lists a handler moves between all day
are configured to different standards, and the one that decides what to do
next cannot say which task jumps the queue.

## What changes

- **9.11, on Tasks.** Three chips: Closed (`isTerminalStatus` true), Overdue
  (`dueDate` before `@today`, open only) and Due this week (`dueDate` in the
  half-open window `[@today, @today+7d)`, open only).
- **9.11, on Tasks.** The `priority` column, after `dueDate`. REQ-TASK-004
  named it in the row and no page ever declared it.
- **Symmetry, on Cases.** The Due this week chip over `deadline`, so both
  pages carry the same six labels in the same order and only the field
  underneath differs.

## Decision D1: the window is half-open, and the far edge is `lt`

A case due on day seven belongs to next week. `lte` on the far edge would put
it in two chips at once and make the two counts disagree by design. The near
edge is `gte`, so a task due later today is in this week rather than in
nothing.

## Decision D2: no new page, no new menu entry, no new component

Everything here is a `quickFilters` entry or a `columns` entry on a page that
already exists. Top-level menu entries before: four. After: four. Tabs on any
page before and after: unchanged. The nearest thing to code is the vitest that
holds the chip shapes.

## Decision D3: the Team chip is NOT in this change, and the reason is not the one the plan gave

Rows 3.5 and 13.6 ask for a Team lens on both indexes. The phase 3 plan
recorded it as blocked on a nextcloud-vue `quickFilters` value that resolves
to the caller's groups. That diagnosis is wrong in a way that matters, because
it would have been dispatched to the library and would not have helped.

`case.assignedGroup` and `caseTask.assigneeGroup` are `$ref`s to an
`organisatieRol`, not to a Nextcloud group. A library token resolving Nextcloud
group ids could never match one. The membership that would answer "which teams
am I in" is `register.d/61-mandaat-matrix.json#medewerkerRolToewijzing`
(`userId`, `roleId`, `validFrom`, `validUntil`), and it has zero readers and
zero writers anywhere in `lib/` or `src/`. So the blocker is dossiq's own: the
app cannot say which teams a person belongs to, by any path.

The seam, when the data exists, is already in the library and needs no change
there: `useSelfFetchList` injects `cnWorkspaceContext` and resolves
`@workspace.<key>` inside a quick filter's own filter map. An app root that
provides the caller's `organisatieRol` ids under that key makes the chip a
one-line manifest entry.

Until then the Team FACET in the sidebar is the honest interim, and it already
ships on both pages: `assignedGroup` and `assigneeGroup` are both `facetable`,
and both indexes carry the Team column that reads the expanded `roleName`.

## What this does not close

13.6's visibility half stays OpenRegister's, as the plan says. 3.5's Team
quarter is deferred to a change that gives dossiq a membership read. 3.5's
other three quarters (My, Unassigned, All) shipped in `one-case-list` and are
re-rated to yes here rather than rebuilt.
