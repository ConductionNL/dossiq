# Tasks: splitting-a-case-and-its-incidents

Tier: V1. Kind: code. Size M. Rows 2.35 and 2.45.

- [x] 1.1 `lib/Settings/dossiq_register.json`: declare per case type what a
  split may divide (documents, parties, tasks), with a default that allows
  all three (D-2).
  - `@spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md`
  - `caseType.splitMayDivide`. AN ABSENT VALUE ALLOWS ALL THREE, and that is
    the decision worth reading twice: an empty default would forbid every
    split on every case type until somebody administered one, which is a
    feature that ships switched off and looks broken.
- [x] 1.2 The split action on `#CaseDetail`: pick the items, open the second
  case, move what was picked, leave a reference in the original (D-1, D-3).
  - `tests/Unit/Service/CaseSplitServiceTest.php` (NOT `tests/unit/`, which
    is not a suite `phpunit.xml` runs)
  - `lib/Service/Split/CaseSplitService.php`, `lib/Controller/CaseSplitController.php`,
    `src/dialogs/CaseSplitDialog.vue`, and a header action beside the merge.
  - EVERY CHOSEN ID IS READ BACK OFF THE CASE before it moves. Taking the ids
    on trust would let a caller move a document off somebody else's case by
    naming its id, which is an IDOR wearing a split's clothes.
    `testAnIdOnAnotherCaseIsNotMoved` is what says so.
  - The reference is WRITTEN and not derived: once a document has moved it no
    longer names the original, so nothing could reconstruct what left.
- [x] 1.3 Write the typed relation on both cases, reusing the relation
  `CaseRelationService` already stores; name the openregister inverse slug
  once that lane opens it.
  - `tests/Unit/Service/CaseSplitRelationTest.php`
  - The relation is `vervolg`, the one `CaseCopyService` already writes, and
    the test asserts it is in `CaseRelationService::RELATION_TYPES` rather
    than matching a literal: a split that invented its own type would be a
    link the Related tab cannot label.
  - The shape is asserted too. `relatedCases` is a JSON-ENCODED STRING of
    typed relations and never an array of ids; an array stores something
    nothing reads and the tab is empty on every split with nothing anywhere
    reporting it.
  - The openregister inverse slug is `relation-types-with-inverses` (register
    row 2.26), still to be specified. Until it lands dossiq writes both sides
    itself, exactly as `CaseRelationService` does today.
- [x] 1.4 Refuse a split of what the case type does not allow, naming the
  rule that refused (D-2).
  - `CaseSplitService::FORBIDDEN`, and the refusal names the KIND it refused
    rather than saying "not allowed": a handler who is told which of the three
    was refused knows what to ask the administrator for.
- [x] 2.1 `lib/Settings/dossiq_register.json`: the `incident` schema with
  the event date, the recording moment, the reporter, the description, the
  owner, the state and the outcome (D-4, D-6).
  - `tests/Unit/Service/IncidentServiceTest.php`
  - `testAnIncidentIsNotADeelzaak` asserts over the SCHEMA rather than over a
    row, because the defect it guards against is somebody adding a `deadline`
    to the incident later, which would give every report a statutory clock
    nobody owes.
- [x] 2.2 The incidents tab on the case, in event-date order, showing the
  recording delay where the two differ (D-6).
  - `tests/vitest/caseIncidentsTab.spec.js`
  - A DECLARATIVE `object-list` SECTION ON THE WORK TAB, not a custom widget,
    and the delay is shown as the two moments side by side rather than as a
    computed number. The number itself travels on the API row
    (`recordingDelayDays`, computed server-side so a list and a report cannot
    disagree about it) and the day a widget needs it, it is already there. A
    custom widget for two columns would have been a registry entry, a ratchet
    exemption and a mounted-component test to render what the vocabulary
    already renders.
  - The sort is asserted in the vitest spec because the manifest is where it
    is decided. A list ordered on the creation moment reads the pattern
    backwards the first time somebody writes up an old report.
- [x] 2.3 The incident hand-off: its own assignee, changed without touching
  the case's owner (D-5).
  - `tests/Unit/Service/IncidentHandoverTest.php`
  - Every test there asserts the CASE's seat after the hand-off and not only
    the incident's. A test that checked the incident alone would pass on an
    implementation that re-seated the case as well, which is the defect: the
    area handler would lose the address because an inspector took one report.
- [x] 3.1 Open incidents as a countable and filterable fact on the work
  list.
  - `IncidentService::openCountsFor()` answers PER CASE rather than a total,
    because the work list has to answer both "which cases have open reports"
    and "how many are there", and a total cannot be taken apart again.
    `tests/Unit/Service/IncidentQueueCountTest.php`.
  - `lib/Service/Queue/Source/OpenIncidentSource.php` puts the reports one
    person owns on their own queue. It is a source of its own and not a
    filter on `AssignedCasesSource`, because an incident's owner is not the
    case's: an inspector holding one report inside somebody else's case would
    appear on nobody's queue otherwise.
  - `in-behandeling` COUNTS AS OPEN. A count that excluded it would tell a
    coordinator the work is done while an inspector is out looking at it.
- [x] 4.1 Dutch and English strings for the split dialog, the refusal, the
  incident form and the incident states.
  - 16 new keys in `l10n/en.json` and `l10n/nl.json`, both catalogues rebuilt
    into their `.js` siblings. The incident field labels come from the schema
    titles in the register descriptor, so they need no catalogue key.
  - INHERITED, reported rather than hidden: `node tests/l10n/check-l10n.js`
    was already red on `parity/round2` over eleven strings from
    `CaseMergeDialog.vue` and `PublicStatusPage.vue` (dossiq#2916) with no
    English source, and seven schema titles from the routing lane (dossiq#2936)
    that had a Dutch value and no English one, so the two key sets diverged.
    All eighteen are in the same two catalogue files this change edits, so
    they are fixed here and named here.
- [x] 4.2 `tests/e2e/splitting-a-case-and-its-incidents.spec.ts`: split with
  a chosen division, a refused split, three incidents with two owners;
  `openspec validate splitting-a-case-and-its-incidents --type change --strict`.
  - Written, tagged and NOT RUN: the integration branch defers Playwright to
    the nightly. The three reports are recorded OUT OF ORDER there for the
    same reason the unit fixture is: recorded in order, a list sorted on the
    creation moment reads correctly by accident and the spec goes green over
    the exact defect it is written for.

## The gap this change does not close, stated rather than discovered

A TASK CANNOT BE MOVED TO ANOTHER CASE, and `tasks` is in the declared
vocabulary anyway. A dossiq task lives in the engine, whose task carries the
case as its `objectUuid`, and `EngineTaskGateway` offers create, claim,
reassign and complete and no verb that moves a task to another object. So a
case type can forbid dividing tasks, and asking to move one is refused with
`CaseSplitService::UNMOVABLE`, whose sentence names the missing verb. The
alternative was to accept the choice and move nothing, which is the silent
no-op this repository has a hub page about.

`tests/unit/Service/IncidentServiceTest.php` and its three siblings are named
in lowercase `tests/unit/` above the line in the original task list. They are
written at `tests/Unit/`, because `phpunit.xml` declares exactly one suite and
it is `tests/Unit`. A file under `tests/unit/` would never execute and would
look like a passing guard.
