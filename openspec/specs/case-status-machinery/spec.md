# case-status-machinery Specification

## Purpose

One write path decides a case's status. The lifecycle is declared on the schema
and driven by OpenRegister's transition engine, so dossiq keeps no second state
machine of its own. A declared lifecycle, its enum and the service that reads it
must agree.

## Requirements

### Requirement: one write path for case status

`case.status` MUST change only through `StatusTransitionService` (its
guarded `execute()`, the admin `executeFreeForm()`, and the flow-node
seam that wraps the same `lib/Service/Transitions/` handlers). No second
public entry point to the transition engine may exist: a facade or
wrapper without production callers is dead machinery and MUST be
removed.

#### Scenario: no second entry point to the transition engine

- GIVEN the tree after this change
- WHEN `lib/` is scanned for the retired classes
- THEN `WorkflowEngineService` does not exist as a file
- AND the scanner test fails naming any file that brings it back

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest.

### Requirement: no dead local state machines

A status machine whose states cannot occur in stored data (a literal
vocabulary written against a reference-valued field, or a scan whose
filter can never match) is dead machinery and MUST be removed rather
than migrated. The vergadering case machine
(`VergaderingCaseService`, `VergaderingDeadlineJob`) is retired under
this rule.

#### Scenario: the vergadering machine stays retired

- GIVEN the tree after this change
- WHEN `lib/` is scanned for the retired classes
- THEN `VergaderingCaseService` and `VergaderingDeadlineJob` do not
  exist as files
- AND `appinfo/info.xml` registers no vergadering background job

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest; the retired UI surface is already asserted by
tests/e2e/spec-coverage/retired-surfaces.spec.ts.

### Requirement: a declared lifecycle agrees with its enum and its service

Every `x-openregister-lifecycle` a dossiq schema declares MUST use the
object-form dialect OR validates (`field`, `initial`, `transitions`,
`final`), MUST name only states of the field's enum, and where an
app-side transition table exists for the same object it MUST agree with
the declaration edge for edge. The complaint lifecycle is rewritten
under this rule in both register manifests.

#### Scenario: the complaint declaration mirrors the service table

- GIVEN the complaint schema in `dossiq_register.json` and
  `dossiq_mock_register.json`
- WHEN the declared transitions are compared with
  `ComplaintService::TRANSITIONS`
- THEN the edge sets are equal
- AND every declared state is a member of the status enum

`@e2e exclude` manifest and service parity; pinned by
LocalStatusMachineryTest.

### Requirement: the transition-table census is closed

Every transition-table constant under `lib/` (`const *TRANSITIONS =`,
`const *VALID_STATUSES =`) MUST sit in the scanner's closed allowlist
with the reason it may exist (domain machine, staged thinning, or
another change's ownership). A new file declaring one fails the suite
naming itself.

#### Scenario: a new local status machine cannot ship quietly

- GIVEN a new file under `lib/` declaring a transition-table constant
- WHEN the unit suite runs
- THEN `LocalStatusMachineryTest` fails naming the file
- AND the failure message says where status sequencing belongs

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest.

### Requirement: A hidden status is hidden everywhere work is counted (REQ-LIFE-01)

`statusType.hiddenInLists`, through the calculated
`case.statusHiddenInLists`, SHALL be honoured by every lens of the Cases
index, the Queue page, My Work, the open counts and the dashboard tiles.
It SHALL NOT remove a case from search, from its own page, or from a list
a person explicitly filtered onto that status.

#### Scenario: the Overdue chip honours it too
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** three overdue cases in a status marked hidden
- **WHEN** a handler opens the Overdue lens
- **THEN** those three cases SHALL NOT be listed

#### Scenario: the counts agree with the list
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** cases in a hidden status
- **WHEN** the open count and the dashboard tiles are read
- **THEN** they SHALL NOT be counted

#### Scenario: My Work and the Queue honour it
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case assigned to a handler in a hidden status
- **WHEN** that handler opens My Work and the Queue
- **THEN** the case SHALL appear in neither

#### Scenario: a hidden case is still findable
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case in a hidden status
- **WHEN** a handler searches for its number
- **THEN** it SHALL be found
- **AND** its page SHALL open

#### Scenario: asking for the status shows them

- **GIVEN** a hidden status
- **WHEN** a handler filters the list onto that status
- **THEN** its cases SHALL be listed

### Requirement: Every declared display flag has a reader, directly or through a calculation (REQ-LIFE-02)

Every display flag declared on a dossiq schema SHALL be read by at least
one query, store or component, either directly or through a property
declared as calculated from it, or SHALL carry an entry in a
reason-bearing allowlist. A structural test SHALL follow the declared
calculation rather than matching the flag's name. It SHALL fail when a
declared flag has no reader and no allowlist entry, and SHALL fail when an
allowlisted flag gains a reader without its entry being removed.

#### Scenario: a flag read through its calculated mirror passes

- **GIVEN** a flag no code names directly
- **WHEN** a property calculated from it is filtered on
- **THEN** the structural test SHALL pass

#### Scenario: a control nobody reads fails the build

- **GIVEN** a new display flag on a schema
- **WHEN** nothing reads it, nothing is calculated from it, and it is not allowlisted
- **THEN** the structural test SHALL fail
- **AND** it SHALL name the flag and the schema

#### Scenario: an allowlisted flag that gained a reader is caught

- **GIVEN** an allowlisted flag
- **WHEN** a reader is added and the entry is left
- **THEN** the structural test SHALL fail

### Requirement: A case type may give its status to the process (REQ-LIFE-03)

A case type SHALL declare whether its status is owned by the process. Where
it is, a status SHALL only be set through a transition, and a direct write
of the status field SHALL be refused with a 4xx carrying `{message, error}`
naming the rule. A case type declaring process ownership whose process
cannot be resolved SHALL refuse the direct write rather than allow it.

#### Scenario: a hand-set status is refused where the process owns it
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type declaring process-owned status
- **WHEN** a handler writes the status field directly
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the rule

#### Scenario: the transition still moves it
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** the same case type
- **WHEN** a handler performs a declared transition
- **THEN** the status SHALL move

#### Scenario: a case type that does not declare it keeps today's behaviour

- **GIVEN** a case type without the declaration
- **WHEN** a handler sets the status
- **THEN** it SHALL be accepted

#### Scenario: an unresolvable process fails closed

- **GIVEN** a case type declaring process-owned status whose process is missing
- **WHEN** a handler writes the status directly
- **THEN** it SHALL be refused

### Requirement: A case type may close a case after declared silence (REQ-LIFE-04)

A case type MAY declare a period of no activity after which dossiq closes
the case. The default SHALL be off. Before the close, the applicant SHALL
be told it is coming, through the declared automatic moments. The close
SHALL record that the product performed it, the period declared, and the
last activity it counted from.

#### Scenario: a bezwaar waiting on the indiener does not sit open forever
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type declaring 60 days of silence
- **WHEN** a case has had no activity for 60 days
- **THEN** it SHALL be closed
- **AND** the close SHALL record that the product did it

#### Scenario: the applicant is warned first
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case approaching its declared silence period
- **WHEN** the warning moment is reached
- **THEN** the applicant SHALL be told the case will close

#### Scenario: off by default

- **GIVEN** a case type with no declaration
- **WHEN** a case has had no activity for a year
- **THEN** it SHALL NOT be closed
