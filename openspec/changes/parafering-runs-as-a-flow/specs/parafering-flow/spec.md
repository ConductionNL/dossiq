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

### Requirement: REQ-PRF-006 Only an enabled projection takes over

The system SHALL start a flow run for a voorstel only when the flow projected
from its approval route is ENABLED, and SHALL record the run on the voorstel.

Every projection ships disabled, because the route still drives parafering and
running both would ask every approver twice. Enabling one flow is therefore the
act that moves one route onto the engine, and it is the only thing that does.

A flow that cannot report its enabled state SHALL be treated as disabled. An
unreadable flag is not permission to run.

#### Scenario: An enabled projection starts a run

- **GIVEN** a route whose projected flow is enabled
- **WHEN** a voorstel is activated
- **THEN** a run MUST start and the voorstel MUST record its id

#### Scenario: A disabled projection starts nothing

- **GIVEN** a route whose projected flow is disabled
- **WHEN** a voorstel is activated
- **THEN** no run MUST start

#### Scenario: Another route's flow is not this route's flow

- **GIVEN** an enabled flow projected from a different route
- **THEN** it MUST NOT be started for this one

### Requirement: REQ-PRF-007 A voorstel finishes the way it started

A voorstel carrying no flow run SHALL be driven by its route snapshot.

A hard cutover would strand whatever is mid-parafering: those voorstellen would
be waiting on a run nobody started. The dev instance cannot show this because
it holds zero voorstellen; production can.

The engine being absent or failing SHALL NOT fail activation. A voorstel that
cannot start a run takes the route path, which is what the dual path is for.

#### Scenario: No enabled flow means the route drives it

- **GIVEN** a route with no enabled projection
- **WHEN** a voorstel is activated
- **THEN** it MUST carry no run id, and MUST be on step 1 of its route snapshot

#### Scenario: OpenRegister absent does not fail activation

- **GIVEN** an instance where the flow engine cannot be resolved
- **WHEN** a voorstel is activated
- **THEN** activation MUST succeed on the route path

### Requirement: REQ-PRF-008 A given paraaf resumes the run it answers

When a `parafeeractie` is created for a voorstel driven by a flow run, the
system SHALL record `flowRun` and `flowNode` on it and resume that run.

The signal SHALL carry the approver's own decision, not a bare completion. The
steps after a paraaf branch on WHICH way it went, and a returned voorstel must
not read as an approved one.

#### Scenario: The run resumes with the decision that was given

- **GIVEN** a voorstel driven by a run awaiting a paraaf
- **WHEN** a paraaf is created against it
- **THEN** the run MUST be signalled with that paraaf's action

#### Scenario: The paraaf records which node it answered

- **GIVEN** a paraaf that resumed a run
- **THEN** it MUST carry the run id and the awaiting node's id

#### Scenario: A voorstel on the route path resumes nothing

- **GIVEN** a voorstel carrying no flow run
- **WHEN** a paraaf is created against it
- **THEN** nothing MUST be signalled

### Requirement: REQ-PRF-009 Only the assignee may sign the step

The system SHALL refuse to resume a run when the user who gave the paraaf is
not the assignee of the awaiting step.

`FlowRunService::signal()` is reachable without OpenRegister's HTTP resume
endpoint, so that endpoint's assignee guard is NOT inherited. A paraaf is a
signature: without this check, any user who can write a `parafeeractie` could
sign off somebody else's step, and nothing about the resulting run would look
wrong.

The refusal SHALL NOT undo the paraaf. It is already saved; what is withheld
is the resume, and that is recorded.

#### Scenario: A non-assignee cannot advance the run

- **GIVEN** a paraaf given by somebody who is not the awaiting step's assignee
- **THEN** the run MUST NOT be signalled, and the linkage MUST NOT be stamped

### Requirement: REQ-PRF-010 A projected route drives parafering end to end

A projected approval route SHALL be enabled, and SHALL own the voorstel from
the first paraaf to its final status.

It was disabled for two reasons, and both had to close before it could run:

1. `BesluitvormingParafeerService` advanced the route snapshot regardless, so
   both drivers ran and every approver would have been asked twice.
2. The projection wrote no status, so a flow-driven voorstel collected every
   paraaf and then stayed `in_parafering` forever.

#### Scenario: The chain closes the voorstel

- **GIVEN** a projected route whose approvers have all signed
- **THEN** the chain MUST move the voorstel to `geaccordeerd`

#### Scenario: A returned paraaf leaves the chain

- **GIVEN** an approver who returns the voorstel
- **THEN** the chain MUST move it to `teruggestuurd`, and MUST NOT ask the
  remaining approvers

### Requirement: REQ-PRF-011 The flow drives, or the route snapshot does

The system SHALL NOT advance the route snapshot for a voorstel carrying a flow
run, and SHALL continue to advance it for every voorstel without one.

A voorstel already mid-parafering when a route is enabled carries no run. It
has to finish the way it started; a hard cutover would leave it waiting on a
run nobody started for it.

#### Scenario: A flow-driven voorstel is left alone

- **GIVEN** a voorstel carrying a flow run
- **WHEN** a paraaf is given
- **THEN** the route snapshot MUST NOT be advanced

#### Scenario: A snapshot voorstel still advances

- **GIVEN** a voorstel carrying no flow run
- **WHEN** a paraaf is given
- **THEN** its step MUST advance exactly as before

### Requirement: REQ-PRF-012 A status a flow writes is one the schema declares

The system SHALL refuse a voorstel status that `proposal.status` does not
declare, rather than attempting the save.

`proposal.status` is a closed enum and OpenRegister runs hard validation by
default, so an undeclared value fails far from the node that chose it.

#### Scenario: An undeclared status is refused

- **GIVEN** a flow configured to write a status the schema does not declare
- **THEN** the node MUST refuse it rather than leave the voorstel where it was
