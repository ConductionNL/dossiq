# add-work-queue Specification

## Purpose

Unclaimed work gets a page of its own. Assigned to me shows what a handler
owns, and All cases shows everything. Neither shows what is open and waiting for
somebody to pick it up. The Queue answers that third question, and the three
surfaces follow one rule.

## Requirements

### Requirement: The queue holds the work nobody has picked up

dossiq SHALL provide a Queue page at `/queue`, an index over the `case` schema
filtered to cases with no `assignee` and `isFinalStatus` false. It SHALL sit first in
the My work group, above Assigned to me.

The base filter SHALL be `assignee: "IS NULL"` and `isFinalStatus: false`.
`assignee: "IS NULL"` is the literal sentinel every OpenRegister condition builder
matches by value, and it SHALL be preferred over the `assignee_isnull=true` suffix,
which was unimplemented when this page shipped and works only on instances carrying
openregister `isnull-filter-operator`.

#### Scenario: The queue holds unassigned open cases

- **WHEN** a handler opens `/queue`
- **THEN** every row is a case with no assignee whose `isFinalStatus` is false
- **AND** the page holds strictly fewer rows than `/cases`

#### Scenario: The queue narrows by case type

- **WHEN** a handler selects a case type in the folder sidebar on `/queue`
- **THEN** the rows are the unassigned open cases of that case type

#### Scenario: An empty queue says so

- **WHEN** no case is both unassigned and open
- **THEN** `/queue` renders its empty state rather than an empty table

### Requirement: Three surfaces, one rule

The My work group SHALL offer Queue, Assigned to me and All cases, and a case SHALL
be reachable from at least one of them at all times. Assigning a case SHALL move it
from the Queue to the assignee's Assigned to me; All cases SHALL show it in both
states.

#### Scenario: The group offers all five surfaces

- **WHEN** a handler opens the navigation
- **THEN** the My work group holds Queue, Assigned to me, All cases, Tasks and
  Workflow board

#### Scenario: Assigning a case moves it
@e2e exclude mutates a shared instance — assigning a case rewrites demo data the other suites read; the filter itself is asserted by queue.spec.ts, which proves the queue is a strict subset of the case index

- **GIVEN** an unassigned open case on `/queue`
- **WHEN** a handler is set as its assignee
- **THEN** the case no longer appears on `/queue`
- **AND** it appears on that handler's `/my-work`
- **AND** it appears on `/cases` in both states

### Requirement: Anything that asks a person for something declares a queue source (REQ-QUEUE-01)

Every mechanism that can ask a person for something SHALL declare itself
as a queue source: a name, how it is read for a given person, what an item
points at, and what closes the item. The personal queue SHALL be built
from the declared sources and SHALL NOT hold its own list of queries. A
structural test SHALL fail when a mechanism that asks a person for
something declares no source and carries no reason-bearing allowlist
entry.

#### Scenario: a new mechanism reaches the queue by declaring itself
@e2e tests/e2e/one-personal-queue.spec.ts

- **GIVEN** a mechanism that asks a handler for an approval
- **WHEN** it declares a queue source
- **THEN** its items SHALL appear in that handler's queue
- **AND** no queue page SHALL be changed

#### Scenario: a mechanism that asks and declares nothing fails the build
@e2e exclude This scenario IS a build-time failure and has no browser surface by definition. Covered by `AsksAPersonDeclaresASourceTest::testANewMechanismWithNoSourceFails`, with its mirror `testTheSameMechanismDeclaredPasses` so the red is not coming from the name.

- **GIVEN** a mechanism assigning work to a person
- **WHEN** it declares no source and is not allowlisted
- **THEN** the structural test SHALL fail
- **AND** it SHALL name the mechanism

#### Scenario: a source that cannot be read says so
@e2e exclude Needs a live source to fail on demand, which the e2e instance cannot arrange without breaking the register for every other suite on it. Covered by `QueueSourceContractTest::testAnUnreadableSourceIsNamedAndTheRestStillAnswer` and `testAnUnreadableSourceIsNotAnEmptyQueue`.

- **GIVEN** a declared source whose read fails
- **WHEN** a person opens their queue
- **THEN** the queue SHALL name the source as unavailable
- **AND** it SHALL NOT silently show fewer items
