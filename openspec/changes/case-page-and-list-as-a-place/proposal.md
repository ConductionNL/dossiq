---
kind: config
depends_on: []
---

# Proposal: case-page-and-list-as-a-place

Round 4 discovery, cluster 58 "The case page and the list as a place"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Seventeen candidates,
seventeen passers, fifteen driven, proving system OTOBO. Owner
nextcloud-vue, size M. This change is dossiq's half: the declarations, and
nothing else.

dossiq is `no` on fourteen of the seventeen. `found-and-lacking.md` lists
the cluster fourth in the heaviest failures, at 14 of 17.

## Why

A handler triaging four hundred cases opens a case, reads two fields, goes
back, loses their place in the list, and scrolls again. OpenProject calls
the fix a split view (`/work_packages/:id/split_view`,
`with_split_view`). Frappe calls it navigation
(`get_navigation_tickets`). Both are the same observation: triage is a
list-plus-detail act, not a navigate-and-return act.

dossiq's `#Cases` and `#Queue` are `index` pages and `#CaseDetail` is a
`detail` page (`src/manifest.json`). Opening a case leaves the list.

## The candidates, and which of them this change carries

The cluster is `should` at its highest, with no `must` in it. **D6 was
answered relevance-led**, which admits every `must` whatever its passer
count and therefore admits nothing here: this cluster has none. Its
members enter the corpus on the two-driven-passers bar, and most of them
do not clear it. This proposal says which do, rather than treating
seventeen candidates as seventeen commitments.

Carried here, two or more driven passers:

| candidate | driven passers | what it asks |
|---|---|---|
| C-configuration-55 | glpi, otobo, valtimo, znuny | each person sets their own language, notifications and interface options |
| C-search-19 | forgejo, gitea, gitlab | items are dragged into an order a person chooses, and the order is kept |
| C-search-4 | openproject, tuleap | a reference shows its summary in place, without leaving the page |
| C-configuration-27 | freescout, gitlab | a person chooses where the product opens and what sits in their navigation |

Carried here on their own merit, one driven passer each, because they are
the two the clause argues hardest for and both are a declaration rather
than a build:

| candidate | driven passer | the lane's clause |
|---|---|---|
| C-search-30 | openproject | "triage of a 400-case queue is a list-plus-detail act, not a navigate-and-return act" |
| C-search-25 | frappe-helpdesk | "a handler working a queue of forty" |

Recorded, not carried: C-case-core-36 (tab in the URL, opencase),
C-search-14 (per-user ordering of shared lists, zammad), C-search-24
(results as cards, documented only), C-search-34 (last used view
remembered, taiga), C-search-40 (absolute or relative dates, tuleap),
C-configuration-35 (per-user setting override, otobo), C-configuration-68
(switch personal customisation off, xxllnc), C-access-and-privacy-4 (skip
link to the primary case action, xxllnc), C-access-and-privacy-37 (high
contrast marking on a widget, valtimo). Each has one driven passer and
none is a `must`. They stay candidates until a second column passes them
or a customer asks.

Two members need no work at all. C-case-core-9, renaming a case after
creation, already passes: `found-and-lacking.md` records it among the
twenty the sweep overturned, proved by `src/manifest.json#CaseDetail`.
C-access-and-privacy-78, WCAG conformance as a named expertise, is
`partial` because the fleet already gates on WCAG AA.

**D17 was answered for a broad market**, so a candidate rated `not` for a
gemeente can be a `could` for an MKB buyer. None of the twenty `not`
candidates is in this cluster, so nothing here changes on that count.

## The decision this rests on

None. `build-plan.md` names no decision for cluster 58. D5 governs the
saved-views cluster beside it, not this one.

## What changes

Declarations on `src/manifest.json`, and no component:

- `#Cases` and `#Queue` declare that a case opens beside the list rather
  than instead of it, and that the list keeps its position.
- Both declare next and previous within the list the handler came from,
  so a queue of forty is walked without going back.
- `#CaseDetail` declares that a reference to another case or an object
  offers its summary in place.
- dossiq's personal settings (`src/personalSettings.js`) declare the
  per-person options this cluster names, so they sit where a Nextcloud
  user already looks for them.
- `#Cases` declares that a handler may hold their own order on a list
  where the data has none.

## Ownership

nextcloud-vue owns every one of these: the split view, the list
navigation, the preview card, the personal-preference plumbing and the
held order. Its change is `saved-view-as-a-place` extended, to be
specified in nextcloud-vue, cluster 58. dossiq declares which pages want
them and ships no component.

This is the same split dossiq's existing change `cases-views-are-places`
already makes for row Q9.16. That change makes a saved view a place. This
one makes the case and the list a place to work from. They are siblings
and neither contains the other.

## Capabilities

- Modified: `case-management`: the case list is somewhere a handler works
  from, not somewhere they pass through.

## Impact

`src/manifest.json` (`#Cases`, `#Queue`, `#CaseDetail`),
`src/personalSettings.js`, `tests/vitest/`, one e2e spec. No PHP.

## Out of scope

- Saved views as places. dossiq `cases-views-are-places`, open.
- The action menu on a list row and the state indicator icons. Cluster 15,
  and C-search-28 and C-search-31 are its `must` members, not this one's.
- Keyboard operation of the daily loop. C-search-33, cluster 15.
- The nine recorded candidates above. They are named so nobody rediscovers
  them in six months, per D17's own recommendation for the `not` bucket.
