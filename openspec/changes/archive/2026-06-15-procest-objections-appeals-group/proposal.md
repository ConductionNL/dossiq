# Proposal: procest-objections-appeals-group

kind: information-architecture / nav-grouping refactor — cites **ADR-037** (modular config
fragments: nav/pages live in `src/manifest.d/*.json`, and the canonical nav layout — relocations
and removals — lives in `src/menu-layout.json`, applied after fragments merge) and **ADR-012**
(deduplication: a change must prove it is not re-implementing an existing capability). Pure IA;
no schema, controller, or decision-flow change.

## Summary

procest's left-hand navigation currently surfaces **six flat entries that all belong to the one
Dutch objections-and-appeals (bezwaar & beroep) domain**, declared across the manifest as
top-level (and one settings-section) menu items:

| # | menu id | label | route → page |
| --- | --- | --- | --- |
| 1 | `BezwaarBeroepGroup` | Bezwaar & Beroep | (group label, no route) |
| 2 | `Bezwaren` | Bezwaren | `Bezwaren` → `/bezwaren` |
| 3 | `Beroepen` | Beroepen | `Beroepen` → `/beroepen` |
| 4 | `BezwaarDecisions` | Beslissingen op bezwaar | `BezwaarDecisions` → `/bezwaar-decisions` |
| 5 | `BezwaarAdviceRequests` | BAC-adviezen | `BezwaarAdviceRequests` → `/bezwaar-advice-requests` |
| 6 | `BezwaarCommitteesMenu` | Bezwaaradviescommissies | `BezwaarCommittees` → `/settings/bezwaar-committees` |

A `BezwaarBeroepGroup` group header already exists and `src/menu-layout.json#relocations` already
moves four of these leaves (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`)
under it — but the grouping is **incomplete and incoherent**: `BezwaarCommitteesMenu` is a separate
`section: "settings"` entry that never joins the cluster, and the group + leaves carry scattered
`order` values (45, 45, 46, 47, 76) that do not read as one ordered family. The result is a flat,
duplicated-looking band of bezwaar/beroep entries in the sidebar instead of one collapsible group.

**What changes (IA only):** collapse all six into **one coherent top-level group "Bezwaar &
Beroep"** (`BezwaarBeroepGroup`), with the transactional leaves as its children in a sensible order
(Bezwaren, Beroepen, Beslissingen op bezwaar, BAC-adviezen, Bezwaaradviescommissies), by completing
the `src/menu-layout.json#relocations` map (add `BezwaarCommitteesMenu` → `BezwaarBeroepGroup`) and
normalising the leaf `order` values. **What stays:** every page stays routable at its existing route
for deep links and e2e specs (relocation moves the menu entry, never the page).

**Coordination — this change does NOT delegate any flow.** "Beslissingen op bezwaar" and
"BAC-adviezen" are decision/advice surfaces; the *delegation of their decision flow to decidesk* is
the concern of the sibling change `procest-delegate-remaining-decisions-to-decidesk`. THIS change
only groups them in the nav. The two changes are independent and compose: the delegation change
retargets the flow behind those pages; this change relocates their menu entries under the cluster.
After both ship, the pages may be backed by decidesk decisions but still live under the same nav
group.

**Dedup rationale (ADR-012):** this change does NOT introduce a new capability, schema, or page —
it groups existing, already-declared menu entries. The `BezwaarBeroepGroup` header and the
relocation machinery (`applyMenuRelocations` in `src/main.js`) already exist; this change completes
their use for the bezwaar/beroep family rather than building anything new. No bezwaar/beroep page is
removed; none is duplicated.

**Depends on:** nothing. Relates to (does not block) `procest-delegate-remaining-decisions-to-decidesk`
(decision-flow delegation for the two decision/advice surfaces — independent concern).

## Why

The six flat entries make the objections-and-appeals domain read as scattered top-level noise in a
sidebar that already groups its other domains (`CasesGroup`, `AnalyticsGroup`, `BesluitvormingGroup`,
`SubsidiesGroup`, `PortaalGroup`). The cost:

- **Sidebar clutter / weak IA.** Five-to-six sibling entries for one domain crowd out the other
  top-level groups and obscure that they are one workflow (a bezwaar leads to a beslissing, an
  advice request goes to a commissie, an unresolved bezwaar becomes a beroep).
- **Half-applied grouping.** A `BezwaarBeroepGroup` header and four relocations already exist, but
  `Bezwaaradviescommissies` sits unrelated in the settings section and the `order` values are
  inconsistent — so the intended group never fully forms. This is an incomplete prior refactor, not
  a greenfield decision.
- **Inconsistent with the fleet IA pattern.** The canonical model (docudesk) groups a domain's
  surfaces under one header; procest already does this for Cases/Analytics/Besluitvorming. The
  bezwaar/beroep domain is the outlier.

## What

1. **Complete the canonical nav layout.** In `src/menu-layout.json`, add
   `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"` to `relocations` so the
   Bezwaaradviescommissies entry joins the cluster (it leaves the `settings` section presentation
   and becomes a child of the group). The four existing relocations
   (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests` → `BezwaarBeroepGroup`) are
   retained.
2. **Normalise the leaf order under the group.** In `src/manifest.json`, set the five leaves to a
   contiguous, workflow-meaningful `order` sequence under the group (Bezwaren → Beroepen →
   Beslissingen op bezwaar → BAC-adviezen → Bezwaaradviescommissies), and keep the
   `BezwaarBeroepGroup` header as the single top-level entry for the domain.
3. **Keep every page routable.** No `pages[]` entry is touched: `/bezwaren`, `/beroepen`,
   `/bezwaar-decisions`, `/bezwaar-advice-requests`, `/settings/bezwaar-committees` (and their
   `:id` detail routes) all stay registered and reachable by direct URL and e2e specs. Relocation
   moves only the menu entry, never the page.
4. **No decision-flow change.** The "Beslissingen op bezwaar" and "BAC-adviezen" pages are grouped,
   not delegated; their flow is the sibling change's concern.

## Capabilities

### New Capabilities

- `objections-appeals-nav-group`: the procest sidebar presents the objections-and-appeals
  (bezwaar & beroep) domain as one coherent top-level group "Bezwaar & Beroep" containing the
  Bezwaren, Beroepen, Beslissingen op bezwaar, BAC-adviezen, and Bezwaaradviescommissies leaves,
  with every underlying page still routable.

## Affected Projects

- [x] Project: `procest` — all changes are in this repo (`src/menu-layout.json`, `src/manifest.json`).
- [ ] Project: `openregister` — no change.
- [ ] Project: `decidesk` — no change here (decision-flow delegation is the sibling change).

## Out of Scope

- Delegating the "Beslissingen op bezwaar" / "BAC-adviezen" decision flow to decidesk — that is
  `procest-delegate-remaining-decisions-to-decidesk` (independent change).
- Any schema, controller, route, or page change — pages stay exactly as declared; only menu entry
  placement and order change.
- Renaming the bezwaar/beroep pages or their labels (the labels are kept verbatim).
- Removing any bezwaar/beroep page (none is removed; all stay routable).

## Success Criteria

- `openspec validate procest-objections-appeals-group --strict` exits 0.
- The sidebar shows exactly **one** top-level entry for the domain — the "Bezwaar & Beroep" group —
  with Bezwaren, Beroepen, Beslissingen op bezwaar, BAC-adviezen, and Bezwaaradviescommissies as its
  children in that order; no flat top-level (or stray settings-section) bezwaar/beroep sibling
  remains.
- `/bezwaren`, `/beroepen`, `/bezwaar-decisions`, `/bezwaar-advice-requests`, and
  `/settings/bezwaar-committees` (plus their `:id` detail routes) remain routable by direct URL.
- No schema, controller, or decision-flow change is introduced by this change.
