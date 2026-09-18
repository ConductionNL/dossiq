# Tasks: splitting-a-case-and-its-incidents

Tier: V1. Kind: code. Size M. Rows 2.35 and 2.45.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `caseType.splittableParts`,
  the three parts a split may divide (D-2). ABSENT MEANS ALL THREE, and the
  schema carries no `default` so that absence keeps meaning it: every case
  type on every install predates the key, and reading silence as "nothing
  may be divided" would ship a split that refuses every split on the day it
  arrives.
  - `tests/Unit/Settings/IncidentAndSplitShippedTest.php`
- [x] 1.2 `lib/Service/Cases/CaseSplitPlan.php`: the plan a split performs.
  A row whose own `case` names a DIFFERENT case is refused by name and not
  moved: the selection arrives from a client, and a plan that repointed any
  id handed to it would let a handler move a document off somebody else's
  case by editing one field in the request. Found by the parallel lane on
  `wip/splitting-incidents-duplicate` and folded in here rather than lost
  with that branch.
  Each chosen item is REPOINTED at the new case, not copied onto it (D-1),
  and each move leaves a reference naming where it went, because a move with
  no trace is indistinguishable from a deletion to whoever opens the file a
  year later. The original's history gets one line, not one per item.
  - `tests/Unit/Service/Cases/CaseSplitServiceTest.php`
- [x] 1.3 The relation is `vervolg`, the one `CaseCopyService` already writes
  and `CaseRelationService` already stores. A second nature meaning "came out
  of" would be one more word for a thing the register can already say.
  - `tests/Unit/Service/Cases/CaseSplitRelationTest.php`
- [x] 1.4 `lib/Service/Cases/CaseSplitPolicy.php`: a part the case type does
  not allow is refused, and the refusal names the rule AND what may still be
  divided (ADR-050). A handler told only what they may not do guesses at the
  rest, and the guess is usually "nothing".
- [x] 2.1 The `incident` schema: the event date, the recording moment, the
  reporter, the description, its own owner, the state and the outcome (D-4,
  D-6). Defined AND carried by the register AND keyed in `SchemaSlugMap`,
  which are three things: a schema the register does not carry is refused on
  every read, and one with no key resolves to nothing and answers an empty
  list that reads exactly like a case with no incidents.
  - `tests/Unit/Service/Cases/IncidentServiceTest.php`
- [x] 2.2 The incidents panel on the case, in EVENT order with the recording
  moment beside it, so a report written up three weeks late sits where it
  happened and the delay is visible (D-6). It shares the Work tab rather
  than adding a twelfth entry to an eleven-entry strip.
  - `tests/vitest/caseIncidentsTab.spec.js`
- [x] 2.3 The incident hand-off changes the incident's assignee and nothing
  of the case (D-5), and takes an untouched incident into `in-behandeling`
  without reopening one somebody has finished.
  - `tests/Unit/Service/Cases/IncidentHandoverTest.php`
- [x] 3.1 `IncidentRecord::openCount()` is the countable fact a work list
  reads. An incident with NO state counts as open: counting it as finished
  lets a list report a clean desk that is not.
- [x] 4.1 Dutch and English strings for the incident panel, its columns and
  the split declaration, rebuilt into the browser catalogues.
- [x] 4.2 `tests/e2e/splitting-a-case-and-its-incidents.spec.ts`: three
  reports whose event order and recording order differ, a hand-off that
  leaves the case where it was, an incident carrying no term and no number,
  and a case type that forbids dividing documents;
  `openspec validate splitting-a-case-and-its-incidents --strict`.

## What is not built, and named rather than left to be discovered

**The split picker dialog.** The header action is deliberately NOT declared
in `src/manifest.json`. An `open-modal` action naming a target the component
registry does not answer renders nothing at all, with no warning and no
console error, so declaring it now would ship a button that does nothing and
look exactly like a button that works. The server-side half is complete and
tested: the bound, the refusal, the plan, the references and the relation.
What remains is a Vue dialog that lists this case's documents, parties and
tasks, offers only the parts the case type allows, and posts the selection.

**The inverse relation.** dossiq writes both sides itself, as
`CaseRelationService` does today. openregister's `relation-types-with-inverses`
(register row 2.26) is where the inverse becomes the platform's; when it
lands, the second write goes and the nature name here is the one to point it
at.
