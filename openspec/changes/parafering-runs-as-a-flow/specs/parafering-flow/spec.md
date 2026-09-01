# parafering-flow Specification

## Purpose

A projected approval route can ask for a paraaf without downgrading it to a
generic task.

## ADDED Requirements

### Requirement: REQ-PRF-001 A flow asks for a paraaf, not a task

The system SHALL provide a `dossiq.askParaaf` flow node that records who is
being asked for a paraaf and suspends until one is given.

The node SHALL NOT create the `parafeeractie`.

A `parafeeractie` declares `action` among its required properties, and its
enum carries no value meaning "not yet signed". A paraaf raised without one
cannot be saved, and inventing a placeholder would put an unsigned signature
into an administrative-law record. The record is what somebody signed, not a
request that they sign.

The awaiting step SHALL record the proposal, the step, the question, the actor
and the actor type, so the run can say what is being asked of whom.

#### Scenario: The node records the ask and waits

- **GIVEN** a step naming an actor
- **WHEN** the node runs with no answer yet
- **THEN** the run MUST suspend, and MUST NOT create a `parafeeractie`

#### Scenario: The awaiting step says what it is asking

- **GIVEN** a suspended paraaf step
- **THEN** its slot MUST carry the proposal, the step, the question and the actor

### Requirement: REQ-PRF-002 A paraaf names the run and the node it answers

The `parafeeractie` schema SHALL carry `flowRun` and `flowNode`, and a paraaf
given against an awaiting flow step SHALL record both.

A run holds one awaiting slot per node and cannot say which of them a signal
answers, so naming the run alone is not enough to resume.

#### Scenario: Both linkage fields exist to be written

- **GIVEN** a paraaf answering an awaiting flow step
- **THEN** it MUST carry the run id and the node id

### Requirement: REQ-PRF-003 Asking is recorded once

However often the run wakes while a paraaf is outstanding, the system SHALL
record the ask once and not restate it.

The node suspends with a heartbeat so a lost signal costs a wake rather than
the flow.

#### Scenario: A heartbeat does not restate the question

- **GIVEN** a step that has already recorded its ask
- **WHEN** the run wakes twice more
- **THEN** the recorded ask MUST be unchanged

#### Scenario: Without a resume slot the node refuses

- **GIVEN** a run offering no resume slot
- **THEN** the node MUST refuse, because the slot is where the assignee is
  recorded and an awaiting step that cannot say who may answer it is one
  anybody can answer

### Requirement: REQ-PRF-004 A resume without a decision is not a sign-off

The system SHALL treat a resume carrying no `decision` as a nudge and suspend
again.

That is what makes an accidental or duplicate POST harmless.

#### Scenario: A decisionless resume suspends again

- **GIVEN** a resume carrying only a comment
- **THEN** the run MUST suspend rather than record a paraaf as given

### Requirement: REQ-PRF-005 The projection carries the route's own step number

The route projection SHALL set each node's `step` from the route step's own
`order`, not from its position in the chain.

The parafering surfaces read `step`, so it must mean what the route meant. The
two differ the moment a route numbers its steps from anything but one.

#### Scenario: A route numbered 10 and 20 projects those numbers

- **GIVEN** a route whose steps declare `order` 10 and 20
- **THEN** the projected nodes MUST carry `step` 10 and 20
