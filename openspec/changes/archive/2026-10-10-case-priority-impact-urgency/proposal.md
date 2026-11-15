---
kind: code
depends_on: []
---

# Proposal: case-priority-impact-urgency

Round 4 discovery, decision D14 "The priority model"
(`procest/_round4/discovery/decisions.md` in
ConductionNL/market-intelligence, 2026-09-14). Two clusters name it:
cluster 15 "The working list and what you can do from a row" and cluster
42 "Escalation, reminders and the once-a-day guard". Owner dossiq, size M,
wave 1.

## Why

D14 opens on the measurement: "All 225 rows and all 48 pending proposals
were checked and not one asks whether a case carries a priority. Every
system in the GitLab lane has one. Kanboard scores it with a colour,
Vikunja fields it, GitLab sorts the queue by a label, YouTrack raises it
as the term runs out."

So the corpus has no row for a thing every competitor has. That is a
matrix hole of a rarer kind than the 47 already counted: not a `must` with
no row, but a capability nobody thought to ask about.

## What dossiq has, read against development

Two things called priority, and neither is a case priority a rule can
read.

- `case.priority` exists: `lib/Settings/dossiq_register.json:1798`, a
  string enum `low`, `normal`, `high`, `urgent`, defaulting to `normal`,
  `facetable: true`. Nothing derives it. It is written as the literal
  `'normal'` by `DsoIntakeService.php:197` and `:237` and by
  `ComplaintService.php:128`, seeded by `DemoCaseloadSeedDataService.php`,
  and copied by `CaseCopyService.php:67` and `CaseSharingService.php:75`.
  No list sorts by it.
- `DeadlineEscalationService::DEFAULT_MATRIX`
  (`lib/Service/DeadlineEscalationService.php:48`) carries its own
  `priority` per threshold, `low`, `medium`, `high`, `critical`. That is
  the urgency of a notification, on a fourth vocabulary, and it never
  touches the case.

The lanes were close. C-search-6's note reads "a value in a demo seed with
nothing behind it", which understates it: there is a schema field. It is
the sort and the derivation that are missing. C-deadlines-15's note is
exact: "dossiq_register.json:1798 has case.priority and nothing moves it".

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-search-6 | should | partial | a weight or a priority on the case orders and colours the list |
| C-deadlines-15 | should | partial | priority rises on its own as the term approaches, and a rule can fire on it |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-search-6, `search.tsv:39`):
"gitlab: To-Do List filters and Recommended, Updated and Label priority
sorts". Three driven passers: GitLab, Kanboard and Vikunja. The lane's
clause: "a priority that orders a list is missing from all 206 rows and
every system in this lane has one."

On C-deadlines-15, `deadlines.tsv:24`: "request-tracker: Ticket, Basics:
priority, lib/RT/Action/EscalatePriority.pm, LinearEscalate.pm,
lib/RT/Condition/PriorityExceeds.pm". The lane's own note names the hole:
"This is the priority hole the lanes name: dossiq has no priority object
for a rule to read."

Both candidates are `should`, so they enter the corpus on the
two-driven-passers bar rather than on relevance. **D6 was answered
relevance-led**, which admits every `must` whatever its passer count and
changes nothing here: C-search-6 has three driven passers already.
**D17 was answered for a broad market**, and none of the twenty `not`
candidates is in either cluster.

## The row the corpus is missing

D14 ends: "It also needs a row: the corpus has none, and that is a matrix
hole D6 should close." This change does not create ledger rows, so it
states the ask plainly for whoever runs the promotion pass: propose a row
reading "priority derived from impact and urgency, ordering the working
list, and raised by a rule as the term approaches", and rate every driven
column for it.

## The decision this rests on

D14, answered as recommended: **option 2, with option 3 as a rule on top.**
Verbatim: "Store impact and urgency, derive the priority, and let a rule
raise it as the term approaches. That gives the list a sort order, gives
escalation something to fire on, and keeps a human able to override."

The rule engine is openregister's under D3, so dossiq declares the rule
and does not build an engine.

## What changes

- A case stores `impact` and `urgency`. Priority is derived from the two
  through a matrix an administrator maintains per case type.
- The derived priority has an order, so a list can sort by it, and a
  colour, so a row can carry it.
- A person may override the derived priority, and the override is recorded
  with who set it and why. Derivation does not then quietly undo it.
- A rule may raise the priority as the term approaches. It may raise and
  never lower, so an approaching term cannot make a case less urgent.
- The existing `case.priority` becomes the derived value. Nothing reading
  it today breaks.

## Ownership

dossiq owns impact, urgency, the matrix and the derived value, because
they are properties of a case. openregister owns the rule that raises it
(D3, `field-rules-by-state` and `lifecycle-declarative-conditions`, to be
specified in openregister, wave 1) and the sortable list. nextcloud-vue
owns the list component that renders the order and the colour, cluster 15,
which dossiq consumes and does not build.

## Capabilities

- Added: `case-priority`: what a case's priority is, where it comes from,
  and who may change it.

## Impact

`lib/Settings/dossiq_register.json` (the `case` schema and the case-type
matrix), `lib/Service/` (the derivation), `src/manifest.json` (the list
column and its sort), `lib/Service/DeadlineEscalationService.php` (the
escalation reads the case's priority instead of its own vocabulary),
Dutch and English strings.

## Out of scope

- The escalation matrix itself and the once-a-day guard. Cluster 42,
  dossiq, `termijn-escalation` and `case-reminder-as-task`.
- The list row's action menu and its indicator icons. Cluster 15,
  nextcloud-vue.
- A per-case reminder relative to a date field. Cluster 42,
  C-deadlines-16.
