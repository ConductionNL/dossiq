# Design — procest-objections-appeals-group

## Context

procest's left navigation is assembled by `src/main.js`:

1. All `src/manifest.d/*.json` fragments merge with the base `src/manifest.json` (`mergeMenuItems`)
   — fragments are the source of **what** menu entries exist (ADR-037).
2. `src/menu-layout.json#relocations` (`{ sourceId: targetGroupId }`) is then applied by
   `applyMenuRelocations(merged.menu, menuLayout.relocations)` — this is the single place deciding
   **where** entries live. A relocated leaf is spliced out of the top level and merged into the
   target group's `children`; a relocated *group* dissolves into the target.
3. `applyMenuRemovals(merged.menu, menuLayout.removals)` drops retired leaf ids (their pages stay
   routable).

So procest already has the exact machinery this change needs: a group header
(`BezwaarBeroepGroup`) plus a relocation map. The objections-and-appeals domain is **half-grouped**
today — four leaves are relocated, one (`BezwaarCommitteesMenu`) is not, and the `order` values are
inconsistent. This change completes the grouping; it writes no new mechanism.

## Current state (verified against live code)

`src/manifest.json` top-level `menu[]` declares the six entries:

| menu id | label | route | order | section |
| --- | --- | --- | --- | --- |
| `BezwaarBeroepGroup` | Bezwaar & Beroep | (none — group header) | 45 | — |
| `Bezwaren` | Bezwaren | `Bezwaren` | 45 | — |
| `Beroepen` | Beroepen | `Beroepen` | 46 | — |
| `BezwaarDecisions` | Beslissingen op bezwaar | `BezwaarDecisions` | 47 | — |
| `BezwaarAdviceRequests` | BAC-adviezen | `BezwaarAdviceRequests` | 76 | — |
| `BezwaarCommitteesMenu` | Bezwaaradviescommissies | `BezwaarCommittees` | 99 | settings |

`src/menu-layout.json#relocations` already contains:

```
"Bezwaren": "BezwaarBeroepGroup",
"Beroepen": "BezwaarBeroepGroup",
"BezwaarDecisions": "BezwaarBeroepGroup",
"BezwaarAdviceRequests": "BezwaarBeroepGroup",
```

…but **not** `BezwaarCommitteesMenu`. So `Bezwaaradviescommissies` renders as a lone settings entry,
disconnected from the group; and the leaf `order` values (45/46/47/76) do not read as one sequence.

The underlying pages (all in `src/manifest.json` `pages[]`, none in a fragment) are:

| page id | route | detail route |
| --- | --- | --- |
| `Bezwaren` (list) | `/bezwaren` | `BezwaarDetail` → `/bezwaren/:id` |
| `Beroepen` | `/beroepen` | `BeroepDetail` → `/beroepen/:id` |
| `BezwaarDecisions` | `/bezwaar-decisions` | `BezwaarDecisionDetail` → `/bezwaar-decisions/:id` |
| `BezwaarAdviceRequests` | `/bezwaar-advice-requests` | `BezwaarAdviceRequestDetail` → `/bezwaar-advice-requests/:id` |
| `BezwaarCommittees` | `/settings/bezwaar-committees` | `BezwaarCommitteeDetail` → `/settings/bezwaar-committees/:id` |

## Key decisions

### D1 — Group via the existing `menu-layout.json` relocation map, not by editing the fragments

procest already split "what exists" (fragments + `manifest.json` declarations) from "where it
lives" (`menu-layout.json`), exactly as ADR-037 prescribes for an app that has a canonical
layout file. Therefore the grouping is expressed by **completing the relocation map**, not by
restructuring the menu declarations into nested `children` by hand. This keeps the single source of
truth for placement in one file and matches how the other four leaves are already grouped.

Concretely: add one line to `relocations`:

```
"BezwaarCommitteesMenu": "BezwaarBeroepGroup",
```

`applyMenuRelocations` then splices `BezwaarCommitteesMenu` out of the top level and merges it into
`BezwaarBeroepGroup.children`, alongside the four already-relocated leaves. The group becomes the
single top-level entry for the domain.

### D2 — Normalise the leaf order so the group reads as one workflow

Set the five leaves to a contiguous `order` sequence so the children render in a sensible
objections-and-appeals workflow order rather than the current 45/46/47/76/99 scatter:

| menu id | new order | rationale |
| --- | --- | --- |
| `Bezwaren` | 45 | the objection itself — entry point |
| `Beroepen` | 46 | the appeal that follows an unresolved objection |
| `BezwaarDecisions` | 47 | the decision on the objection |
| `BezwaarAdviceRequests` | 48 | the advice requested for a decision |
| `BezwaarCommitteesMenu` | 49 | the committee that gives the advice (config-ish, last) |

The `BezwaarBeroepGroup` header keeps `order: 45` so the group sits where the domain currently
anchors. Order values are local to the group's children after relocation, so a contiguous 45–49 run
gives a stable, readable order.

### D3 — Every page stays routable (relocation moves the entry, not the page)

`applyMenuRelocations` operates on `menu[]` only; `pages[]` is untouched. All five pages (and their
`:id` detail routes) remain registered and reachable by direct URL and e2e specs. This change adds
nothing to `menu-layout.json#removals` — no page is retired, so deep links and existing e2e specs
that visit `/bezwaren`, `/beroepen`, `/bezwaar-decisions`, `/bezwaar-advice-requests`,
`/settings/bezwaar-committees` keep working unchanged.

### D4 — No decision-flow coupling

"Beslissingen op bezwaar" (`BezwaarDecisions`) and "BAC-adviezen" (`BezwaarAdviceRequests`) are
decision/advice surfaces. The sibling change `procest-delegate-remaining-decisions-to-decidesk`
retargets the *flow* behind these pages to decidesk. THIS change is strictly the menu placement of
their entries. The two are independent: relocating a menu entry does not touch the page's backing
flow, and delegating a flow does not touch where the menu entry lives. They compose cleanly.

## Alternatives considered

- **Hand-nest the entries as `children` in `manifest.json` and drop `menu-layout.json` relocations.**
  Rejected — procest's canonical pattern keeps placement in `menu-layout.json`; moving placement
  back into the declarations would fork the convention and split the source of truth.
- **Add `BezwaarCommitteesMenu` to `removals` and re-declare it as a child.** Rejected — `removals`
  is for retiring duplicate navigation; the committees entry is not a duplicate, it just belongs in
  the group. Relocation is the correct mechanism.
- **Group only the four already-relocated leaves and leave Bezwaaradviescommissies in settings.**
  Rejected — it leaves the domain half-grouped and the brief's sixth entry stranded; the committee
  surface is part of the same workflow (it produces the BAC advice).
- **Rename labels to a shared prefix.** Rejected — out of scope; labels are kept verbatim.

## Migration / rollout

This is a pure client-side IA change — no schema, no data, no migration step.

1. Add `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"` to `src/menu-layout.json#relocations`.
2. Normalise the five leaf `order` values in `src/manifest.json` (45–49 per D2); keep the
   `BezwaarBeroepGroup` header at `order: 45`.
3. Rebuild the procest bundle; verify the sidebar shows one "Bezwaar & Beroep" group with the five
   children in order and no stray top-level / settings-section bezwaar/beroep sibling.
4. Verify each page still loads by direct URL (deep-link smoke).

No `lib/Repair/*` step is needed (nothing persisted changes).

## Risks

- **A child renders both in the group and at top level (relocation not applied).** Mitigated:
  `applyMenuRelocations` already handles the four existing leaves correctly; adding the fifth uses
  the identical path. Verified by reading `applyMenuRelocations` in `src/main.js`.
- **`BezwaarCommitteesMenu` carried `section: "settings"`; relocating it changes its surface.**
  Intended — it joins the domain group. If a settings-area entry point is still wanted, that is a
  separate decision; this change deliberately groups it with its workflow. Its page route
  (`/settings/bezwaar-committees`) is unchanged and stays routable.
- **e2e spec depends on a top-level menu selector for a relocated leaf.** Mitigated: pages stay
  routable; specs that navigate by URL are unaffected. Any spec asserting a top-level menu position
  must assert the in-group position instead (flagged for the implementer).

## Exact edits (summary table)

| File | Edit |
| --- | --- |
| `src/menu-layout.json` | add `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"` to `relocations` (4 → 5 bezwaar/beroep relocations) |
| `src/manifest.json` | set `order` on `Bezwaren`=45, `Beroepen`=46, `BezwaarDecisions`=47, `BezwaarAdviceRequests`=48, `BezwaarCommitteesMenu`=49; keep `BezwaarBeroepGroup` header `order`=45 |
| `src/manifest.json` `pages[]` | **no change** — all pages stay routable |
