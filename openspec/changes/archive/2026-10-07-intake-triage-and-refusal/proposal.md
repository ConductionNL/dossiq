---
kind: code
depends_on: []
---

# Proposal: intake-triage-and-refusal

Round 4 discovery, cluster 35 "Intake routing, refusal and triage"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Seven candidates in the
cluster, six of them dossiq's; hermiq took C-intake-30. Four `must`, of
which three are dossiq's. Seven passers, six driven, proving system GLPI.
Owner dossiq, size M, depends on nothing. The register's reason for the
cluster carrying nothing: "no change opened beyond hermiq taking
C-intake-30".

## Why

What happens to a case in its first five minutes decides everything after
it. A case that arrives with no channel recorded cannot answer a Woo
request later. A case refused at intake with nowhere to go is a lost case,
and Awb 2:3 says it has somewhere to go. A melding that should open two
cases in two departments opens one, and the second department never hears.

## What is actually there

Read against `development` at `172d364f`.

- `case` already carries `intakeChannel`, `communicationChannel` and
  `confidentiality` (`lib/Settings/dossiq_register.json`). Its `required`
  list is `["title", "caseType"]`. So the fields exist and nothing asks
  for them, which is exactly C-intake-8's gap and nothing more.
- `lib/Service/Routing/Strategy/` holds `HierarchicalStrategy`,
  `LeastLoadedStrategy`, `OrSetStrategy`, `RoundRobinStrategy` and
  `SingleRoleStrategy`, reached through `lib/Controller/RoutingController.php`.
  Routing at intake is real. Refusal is not one of the outcomes.
- `caseType.defaultAssignee` presets who gets the case. Nothing narrows
  who may be chosen.
- Nothing sleeps a triage item until a date, nothing fans one submission
  out into several cases, and nothing requires a classification before a
  case can exist.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-intake-8 | must | partial | a communication channel and a confidentiality designation are required on every new case |
| C-intake-42 | must | partial | the case type decides which groups and employees may be chosen when the case is created |
| C-case-core-10 | must | no | a classification, a sensitivity, an action facet and an insight level are required before a case can exist |
| C-intake-5 | should | partial | a case refused at intake goes to a named department and role |
| C-intake-22 | should | no | a triage item is put to sleep until a date and comes back to the queue then |
| C-intake-33 | should | no | one submitted form opens several cases in different departments, tracked together |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-intake-8, `intake.tsv:38`: "dimpact-zac: Zaak toevoegen
  (browser-walkthrough-notes.md)". Its clause: "both are required fields
  here and optional or absent for us, and both are what the Woo and the
  Archiefwet later ask about".
- C-intake-42, `intake.tsv:23`: "dimpact-zac: Zaak toevoegen
  (browser-walkthrough-notes.md)". Its clause: "the case type narrowing
  the assignable groups at creation is the half we do not have".
- C-case-core-10, `case-core.tsv:42`: "opencase: Create case modal
  (create-case-anatomy.md)". Its clause: "the classification is the access
  rule here, and an unclassified case is unreachable rather than merely
  untidy".
- C-intake-5, `intake.tsv:43`: "xxllnc-zaken: Configuratie > Zaken
  (Configuratie.md)". Its clause: "a refused case that goes nowhere is a
  lost case".
- C-intake-22, `intake.tsv:17`: "plane: Intake, intake.py:63
  snoozed_till". Its clause: "'niets doen tot 1 maart' is a decision a
  behandelaar makes daily and a status nobody has a name for".
- C-intake-33, `intake.tsv:6`: "glpi: Form destinations
  (src/Glpi/Form/Destination/CommonITILField/, ~30 fields, Destinations
  tab)". The lane's note: "Both fan one submission out and keep the
  relation", and its own distinction from ledger row 2.10: "2.10 is a
  hierarchy after the fact, this is the fan-out at intake".

**D6 was answered relevance-led**, so all three dossiq `must` candidates
enter; each carries one driven passer. **D17** does not reach this
cluster.

## What changes

- A case type declares which fields must be answered before a case can
  exist, and the channel and the confidentiality are on that list by
  default. Creation without them is refused, naming the field.
- A classification, a sensitivity, an action facet and an insight level
  are declarable as required, and where a case type declares the
  classification as an access rule, an unclassified case cannot exist.
- A case type narrows which groups and which people may be chosen as the
  handler when a case is created, and a choice outside the narrowing is
  refused rather than filtered out silently.
- Refusal at intake is an outcome of routing: the case goes to a named
  department and role, with the refusal reason recorded, and it stays
  findable.
- A triage item is slept until a date, with a reason, and returns to the
  queue on that date.
- One submitted form opens several cases in different departments, related
  to each other and to the submission, and each department sees only its
  own.

## Ownership

dossiq owns the intake requirements, the narrowing, the refusal outcome
and the sleep. They sit on dossiq's schemas and in
`lib/Service/Routing/`.

What dossiq consumes:

| half | app | artefact |
|---|---|---|
| the field-level rules a declared requirement compiles into | openregister | `row-field-level-security`, a spec, and `field-rules-by-state`, the register's row 11.25 slug; dossiq's consumer halves are the open `sensitive-fields-declared` and `field-rules-declared` |
| the access rule a classification compiles into | openregister | `permission-provenance-and-deny` and `rbac-inherits-to-children`, both existing, named by the wave 1 list |
| the relation that keeps fanned-out cases together | openregister | `relation-types-with-inverses`, the register's row 2.26 slug, to be specified |
| the form whose submission fans out | buildiq | `forms-per-case-type`, the register's row Q1.15 slug, to be specified |

### Needs a change in openregister and buildiq

`relation-types-with-inverses` (openregister, row 2.26) and
`forms-per-case-type` (buildiq, row Q1.15) are both named by the register
and neither has an artefact on its repo's `development`. C-intake-33
needs both: the form declares its destinations, the relation holds the
fanned-out cases together. A follow-up lane should open them. Until then
dossiq builds the fan-out over its existing related-cases link and says so
in the requirement.

## ADRs

- Company ADR-050: the error envelope is `{message, error}`. A refused
  creation and a refused assignee both name their rule in `error`.
- Company ADR-102: config absence fails closed with a status. A case type
  declaring a classification as an access rule whose scheme cannot be
  resolved refuses creation rather than creating an unreachable case.
- dossiq `openspec/specs/semantic-case-intake/spec.md` and
  `openspec/specs/kcc-routing/spec.md` are what this extends.

## Not to be confused with

dossiq already has an open change called `refusals-carry-a-status`. That
one is gap register row Q10.14: a rule that refuses a write returning
null instead of a status. This change is about a case refused at intake
going somewhere. Different rows, different mechanisms, and the refusal
here uses that one's error shape.

## Capabilities

- Modified: `semantic-case-intake`: a case type declares what must be
  answered before a case exists, including its classification.
- Modified: `kcc-routing`: routing narrows the assignee choices, refuses
  to a named destination, sleeps an item, and fans one submission into
  several cases.

## Impact

`lib/Service/Routing/Strategy/`, `lib/Controller/RoutingController.php`,
the `case` and `caseType` schemas, the create-case form, the triage
surface, Dutch and English strings.

## Out of scope

- The duplicate warning at intake. dossiq `duplicate-warning-at-intake`,
  open.
- Mail intake and its filters. dossiq `inbound-mail-filters`, this wave.
- The acknowledgement of receipt. dossiq `ontvangstbevestiging`, this wave.
- Intake channels beyond mail. Cluster 45.
