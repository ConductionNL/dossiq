# parafering-flow Specification

## Purpose

A projected approval route can ask for a paraaf without downgrading it to a
generic task.

## ADDED Requirements

### Requirement: REQ-PRF-001 A flow asks for a paraaf, not a task

The system SHALL provide a `dossiq.askParaaf` flow node that raises a
`parafeeractie` against an actor and suspends until it is answered.

The paraaf SHALL carry the fields the parafering surfaces and the
administrative record depend on: the proposal, the step, the actor and its
type, and on answering, the mandate and on-whose-behalf the signer gave.

A generic task carries none of these, so a route projected onto task-raising
nodes cannot drive parafering without losing the record.

#### Scenario: The node raises a parafeeractie and waits

- **GIVEN** a step naming an actor
- **WHEN** the node runs with no answer yet
- **THEN** exactly one `parafeeractie` MUST be written and the run MUST suspend

#### Scenario: The paraaf carries the domain fields

- **GIVEN** a raised paraaf
- **THEN** it MUST carry the proposal, the step, the actor and the actor type

### Requirement: REQ-PRF-002 A paraaf names the run and the node it answers

The `parafeeractie` SHALL record `flowRun` and `flowNode`.

A run holds one awaiting slot per node and cannot say which of them a signal
answers, so naming the run alone is not enough to resume.

#### Scenario: Both linkage fields are written

- **GIVEN** a paraaf raised by a flow
- **THEN** it MUST carry the run id and the node id

### Requirement: REQ-PRF-003 Asking is idempotent

However often the run wakes while a paraaf is outstanding, the system SHALL
raise exactly one.

The node suspends with a heartbeat so a lost signal costs a wake rather than
the flow. Without idempotence that safety net would raise a fresh demand
against the same person every time it fired.

#### Scenario: A heartbeat re-asks rather than re-raising

- **GIVEN** a node that has already raised its paraaf
- **WHEN** the run wakes twice more
- **THEN** still exactly one paraaf MUST exist

#### Scenario: Without a resume slot the node refuses

- **GIVEN** a run offering no resume slot
- **THEN** the node MUST refuse, because a step that cannot be made idempotent
  must not run at all

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
