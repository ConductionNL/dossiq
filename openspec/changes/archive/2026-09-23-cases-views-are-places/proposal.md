---
kind: config
depends_on: []
---

# Proposal: cases-views-are-places

Competitor gap register, row Q9.16 "Does a saved search behave as a place
of its own, with its own views and a place in the navigation"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner nextcloud-vue, slug
`saved-view-as-a-place`, size M. This change is dossiq's half: one
declaration per page and the views that ship with the app.

## Why

A team lead builds the right lens for the handling desk, saves it, and
then everyone has to open Cases, open the dropdown and pick it again. It
is not somewhere you go, it is a setting you reapply.

The register's note: "rows 9.3 and 10.10 record `allowSavedViews` on the
Cases, Queue and Tasks pages (`src/manifest.json:1014,1115`), a personal
saved view inside a page; nothing makes it a page".

The best competitor, verbatim from the register's `best` column: "Vikunja
2.6.0: a saved filter is a project with a negative id
(saved_filters.go:74) carrying List, Gantt, Table and Kanban views, a
favourite flag and a place in the navigation, measured
(`_round4/compare/proposed-rows-batch7.md`)".

## What changes

- `#Cases`, `#Queue` and `#Tasks` declare that their saved views are
  places, so each view gets a route, its own presentations and a
  navigation entry when pinned.
- The views dossiq seeds get a presentation config, so Overdue opens as a
  list and the desk board opens as a board.
- Nothing new in the navigation until a user pins something. The pinned
  views hang under the page they came from.

## Ownership

dossiq declares. It consumes nextcloud-vue `saved-view-as-a-place`, to be
specified in nextcloud-vue under that slug (row Q9.16): the route, the
presentation switching, the pinned entries and the manifest key. The view
entity, its presentation config and its favourite flag are openregister's
`saved-search-views`, already shipped. The register's `dossiq_half`:
"declare the Cases views as saved-view places once the host renders them".

## ADRs

- Company ADR-024: the declaration is a manifest key on the page.
- Company ADR-097: pinned views are children of the page's entry and count
  towards the navigation budget; dossiq adds no top-level entry.
- Company ADR-022: the view and its presentation config are the platform's.

## Capabilities

- Modified: `case-management`: a saved view of cases is a place you can go
  to and link to.

## Impact

`src/manifest.json` `#Cases`, `#Queue`, `#Tasks` and the seeded views;
`tests/vitest/caseListLenses.spec.js`; one e2e spec. No PHP.

## Out of scope

- Sharing a view with a department. That is
  `saved-views-shared-by-role`, and a shared view becomes a place by the
  same rule.
- A view across case types and tasks at once. A view is one query over one
  schema.
