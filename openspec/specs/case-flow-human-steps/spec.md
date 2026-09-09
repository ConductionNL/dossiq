# case-flow-human-steps Specification

## Purpose
Defines how a case is walked by one flow run from intake to closure: where that run pauses for a person, what the person is shown while it waits, how their answer wakes exactly the step that asked, and what the applicant sees of the case's progress throughout.

## Requirements

### Requirement: One run walks one case @e2e exclude run lifecycle asserted by flow-engine integration tests; the user-visible halves are covered by the task and status scenarios below

A case SHALL be walked by a single flow run created when the case is created. That run SHALL remain the case's run across every suspension and resume, so the case's whole history is one run rather than a series of unrelated ones.

The flow SHALL be shipped with the app as a declaration on the `case` schema rather than authored by hand per installation. It SHALL arrive disabled and unowned: shipping a flow is not the same as an operator consenting to run it as themselves.

#### Scenario: Creating a case starts its run
- **WHEN** a case is created and the case flow is enabled and adopted
- **THEN** exactly one run is started for that case
- **AND** the run names the case as its subject

#### Scenario: The shipped flow is inert until adopted
- **WHEN** the app's register is imported or re-imported
- **THEN** the case flow exists in the flow store
- **AND** it is disabled and has no owner until somebody adopts it
- **AND** re-importing updates it rather than creating a second copy

#### Scenario: A resumed run is the same run
- **WHEN** a case's run suspends on a human step and is later resumed
- **THEN** the case is still walked by the run that started it
- **AND** the steps recorded before and after the suspension read as one ordered history

### Requirement: An incomplete case asks the applicant, and stops asking @e2e exclude covered by the case-flow e2e journey

When the completeness check does not pass, the run SHALL create a task addressed to the applicant naming what is missing, and suspend until it is answered. On resume the completeness check SHALL run again.

The loop SHALL be capped. A case whose applicant never supplies what is asked SHALL leave the loop by a declared route rather than cycling until the engine's transition ceiling stops it — a run that dies on the ceiling reports as a broken flow rather than as a case nobody answered.

#### Scenario: An incomplete case produces a task for the applicant
- **WHEN** a newly created case fails the completeness check
- **THEN** a task is created addressed to the applicant
- **AND** the task states what is missing
- **AND** the run suspends rather than continuing

#### Scenario: Supplying the information resumes the check
- **WHEN** the applicant completes the task
- **THEN** the run resumes at the step that asked
- **AND** the completeness check runs again
- **AND** a case that is now complete proceeds to the next stage

#### Scenario: The loop is bounded
- **WHEN** the applicant has been asked the capped number of times without the case becoming complete
- **THEN** the run leaves the loop by its declared route
- **AND** the case's status says it is stalled awaiting the applicant
- **AND** the run does not terminate on the engine's transition ceiling

### Requirement: A decision is asked of decidiq and waited for @e2e exclude cross-app delegation; asserted by node unit tests and the listener's resume test

Where the flow requires a decision, it SHALL delegate to decidiq and suspend until decidiq reports the outcome. Dossiq SHALL NOT decide: it records the reference it was given and projects the outcome it is told.

The run SHALL resume only on the outcome of the decision it is waiting for. An outcome for a different decision SHALL leave the run suspended.

When decidiq is unavailable the request SHALL fail closed — the run does not proceed as though a decision had been made.

#### Scenario: Requesting a decision suspends the run
- **WHEN** the flow reaches a decision step
- **THEN** a decision is raised in decidiq for this case
- **AND** the reference decidiq returns is recorded on the case
- **AND** the run suspends

#### Scenario: The concluded decision resumes the waiting run
- **WHEN** decidiq concludes the decision the run is waiting for
- **THEN** the run resumes at the step that asked
- **AND** the outcome is available to the steps after it, so the flow can route on it

#### Scenario: An unrelated outcome does not resume the run
- **WHEN** a decision concludes that this run is not waiting for
- **THEN** the run remains suspended

#### Scenario: An unavailable decision service does not become an approval
- **WHEN** the decision cannot be raised because decidiq is unavailable
- **THEN** the step fails
- **AND** the run does not continue past the decision

### Requirement: The final approval produces a decision document attached to the case @e2e exclude document generation; asserted by the action handler's tests

After the final approval the flow SHALL generate the decision document from the configured template and attach it to the case, then close the case.

A case SHALL NOT be closed without its decision document. If generation fails, the case remains open and the failure is visible — a closed case with no decision is a case whose outcome cannot be evidenced.

#### Scenario: An approved case is closed with its document
- **WHEN** the planning commission approves
- **THEN** the decision document is generated from the template
- **AND** it is attached to the case
- **AND** the case moves to its final status

#### Scenario: A failed generation does not close the case
- **WHEN** the decision document cannot be generated
- **THEN** the case is not moved to its final status
- **AND** the failure is recorded on the run

#### Scenario: A rejected case is closed as rejected
- **WHEN** the planning commission rejects
- **THEN** the case is closed with a result recording the rejection
- **AND** a decision document recording the rejection is attached

### Requirement: Flow storage work runs under the engine's native scoping

Flow nodes and the transition/action handlers they delegate to SHALL perform
their storage work bare: on the flow path the engine's
`RegistryStepDispatcher` executes every contributed node inside
`ObjectService::runAs()` as the run's validated acting identity
(openregister#3332), and on the interactive path the ambient session user
answers the permission checks. The system SHALL NOT keep a local runAs
wrapper, and no flow-facing file may wrap its storage work in one — a manual
wrap re-creates the per-consumer copy of an engine rule and nests a second
scope inside the dispatcher's.

`lib/Service/FlowRunAsScope.php` SHALL stay deleted.

#### Scenario: No flow file wraps runAs manually

- **GIVEN** the flow-facing directories (lib/Flow, lib/Service/Transitions, lib/Service/Actions)
- **WHEN** the structural sweep runs
- **THEN** no file references the retired wrapper, storage-performing files still exist (the detector self-check), and the wrapper file itself is absent

`@e2e exclude` a structural source sweep, not a user journey; pinned by the
inverted FlowStorageRunsAsTheRunsIdentityTest.

#### Scenario: A worker-driven flow write acts as the run's identity

- **GIVEN** a flow run whose runAs names an enabled account, executing under FlowRunWorker
- **WHEN** a dossiq node or handler performs storage work
- **THEN** the write happens under that identity, scoped by the dispatcher, with no dossiq wrap involved

`@e2e case-flow-live-journeys.spec.ts` exercises the seeded case flow under
the worker end to end; the scoping mechanism is OpenRegister's
(RegistryStepDispatcherRunAsTest).

### Requirement: An ask advances on its task, not on a signal

`dossiq.askPerson` SHALL create exactly one task on its first pass, remember it
in this node's resume slot, and on EVERY later pass read that task back before
deciding anything. The task's status, not the presence of a signal, SHALL
determine whether the run advances.

- A task at `completed` SHALL advance the run, whether or not a wake arrived.
- A task at any other non-terminal status SHALL re-suspend the run WITHOUT
  touching the resume slot, so the remembered task and the time it was asked
  survive every heartbeat.
- A task at `terminated` or `disabled` SHALL fail the step. The ask was
  withdrawn: continuing would move the case past a question nobody answered,
  and suspending would wait for an answer that can never come.
- A task that no longer exists SHALL fail the step naming it, rather than
  waiting forever on a row that is gone.
- A read that FAILS — an unreachable or unconfigured store — SHALL re-suspend
  rather than fail. A missing row and an unreadable store are different facts,
  and treating a hiccup as "gone" would fail a case whose task is sitting there
  answered.

The system SHALL NOT create a second task on a re-entry, and SHALL NOT restamp
when the task was asked.

#### Scenario: A heartbeat delivers a completion whose signal was refused

- **GIVEN** a run suspended on an ask, whose task's completion signal the engine's assignee guard refused
- **WHEN** the heartbeat wakes the run and no signal is in hand
- **THEN** the node re-reads the task, finds it completed, and the run advances with the answer on its items

#### Scenario: A heartbeat with the task still open parks again on the same task

- **GIVEN** a run suspended on an ask whose task is still open
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again on the same task, no second task is created, and the asked-at time is unchanged

#### Scenario: Only the node whose task was answered advances

- **GIVEN** a run parked on two asks and only the first task completed
- **WHEN** the heartbeat wakes the run
- **THEN** the answered node advances and its slot is consumed, while the other keeps waiting on its own task

#### Scenario: A withdrawn ask fails the step

- **GIVEN** a run suspended on an ask whose task was terminated
- **WHEN** the run next re-enters the step
- **THEN** the step fails naming the task, and the run neither advances nor waits on

`@e2e exclude` a suspend/resume timing path with no user-visible surface of its
own; pinned end to end through the real engine by
tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php.

### Requirement: The task decides the answer and the wake decorates it

The answer `dossiq.askPerson` writes onto every item under its `signalKey`
SHALL be derived from the task row — its status, its id, this node's id, and
when it was completed — and SHALL record whether it was delivered by a wake or
recovered by a heartbeat. A signal payload MAY contribute fields the row does
not carry, such as who completed the task, and SHALL NOT override the fields
the row decides.

A signal SHALL NOT be able to answer for a task that is still open. The run
holds ONE signal slot, so a flow with two asks would otherwise have the second
read the answer given to the first.

#### Scenario: A signal cannot answer for an open task

- **GIVEN** a run suspended on an ask whose task is still open
- **WHEN** a signal carrying a decision reaches the run
- **THEN** the node suspends again, because the row says the question is unanswered

#### Scenario: The delivered and recovered paths agree

- **GIVEN** two runs on the same ask, one answered through the guarded wake and one recovered by a heartbeat
- **THEN** both carry the same decision, status, task id and node under the step's key, differing only in who answered and whether it was recovered

`@e2e exclude` the shape of a value passed between flow steps; pinned by
AskPersonHeartbeatRecoveryTest and DossiqAskPersonNodeTest.

### Requirement: A flow-engine test may drive the real engine

The unit suite SHALL be able to run a test against OpenRegister's real flow
engine — its own source and its composer dependencies — when that app is
checked out beside this one, and SHALL do so only for the suites that ask for
it, in a separate process. Every other suite keeps the stubs.

A suite that asks for the real engine and does not get it SHALL NOT pass. On a
developer machine it reports as skipped, naming what is missing; under CI, where
the sibling checkout is part of the job, it FAILS — a skip there would be the
instrument lying about the thing it exists to measure.

A stub of an OpenRegister class SHALL declare the same constructor as the real
class. A stub that is easier to build than the thing it stands for teaches the
suite a shape that fatals in production.

#### Scenario: The real engine is absent under CI

- **GIVEN** a CI run whose OpenRegister checkout or install did not complete
- **WHEN** the real-engine suite starts
- **THEN** it fails, naming the missing checkout, rather than skipping

`@e2e exclude` test-infrastructure behaviour with no runtime surface.

### Requirement: A consumer can read back a decision it raised

`ContractDecisionDelegationService` SHALL be able to ask decidiq what became of
a Decision it raised, by dispatching decidiq's `DecisionStateRequestedEvent`
and reading the answer the listener writes back synchronously — the same
request/response-over-the-bus shape the raise already uses (ADR-041).

The read SHALL name the Nextcloud uid it is scoped to, and SHALL NOT be
dispatched with an empty one. decidiq refuses a read that names no identity
rather than treating it as a system caller, and an app that cannot name one has
nothing to ask.

The result SHALL distinguish six facts, because a caller acts differently on
each: the seam could not answer, the read was refused, no such decision exists,
the decision is still open, the decision was concluded with an outcome, and the
decision was withdrawn. In particular, an UNREADABLE seam SHALL NOT be reported
as a refusal or as a missing decision.

A status word this app does not recognise SHALL be reported as still open. It
can only come from a newer decidiq, and waiting through a vocabulary extension
costs a heartbeat while guessing that it means "decided" would advance a case on
an outcome nobody here can name.

This is NOT a second delivery mechanism. `DecisionConcludedEvent` remains how a
conclusion arrives; this is what a consumer consults when it did not.

#### Scenario: The read seam is not installed

- **GIVEN** an instance where decidiq's `DecisionStateRequestedEvent` class does not exist
- **WHEN** the delegation service is asked for a decision's state
- **THEN** it reports the state as unreadable, and never as a missing or refused decision

#### Scenario: A read naming no identity is not dispatched

- **GIVEN** a caller with no acting uid to name
- **WHEN** it asks the delegation service for a decision's state
- **THEN** no event is dispatched and the state is reported as unreadable

`@e2e exclude` an in-process cross-app event contract with no user-visible
surface of its own; pinned by ContractDecisionDelegationReadTest and end to end
through the real engine by tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php.

### Requirement: A decision step advances on its decision, not on a signal

`dossiq.requestDecision` SHALL raise exactly one decision on its first pass,
remember its ref in this node's resume slot, and on EVERY later pass read that
decision back before deciding anything. The decision's state, not the presence
of a signal, SHALL determine whether the run advances.

- A decision concluded with an outcome SHALL advance the run, whether or not an
  announcement arrived.
- A decision still open SHALL re-suspend the run WITHOUT touching the resume
  slot, so the remembered ref and the time it was asked survive every heartbeat.
- A decision that was WITHDRAWN SHALL fail the step. The question was taken off
  the table: continuing would move the case past a decision nobody made, and
  suspending would wait for an answer that can never come.
- A decision that no longer exists SHALL fail the step naming it, rather than
  waiting forever on a record that is gone.
- A read that is REFUSED SHALL fail the step. decidiq answered and would not
  report the decision to the identity this run raised it as, which is a
  misconfiguration to surface rather than a state to poll.
- A read that is UNREADABLE SHALL re-suspend rather than fail. An unreachable
  seam says nothing about the decision, and treating a hiccup as "gone" would
  fail a case whose decision is sitting there taken.

The system SHALL NOT raise a second decision on a re-entry, and SHALL NOT
restamp when the decision was asked.

#### Scenario: A heartbeat delivers a conclusion whose announcement never arrived

- **GIVEN** a run suspended on a decision that decidiq has since concluded, whose conclusion never reached the run
- **WHEN** the heartbeat wakes the run and no signal is in hand
- **THEN** the node re-reads the decision, finds it concluded, and the run advances with the outcome on its items

#### Scenario: A heartbeat with the decision still open parks again on the same decision

- **GIVEN** a run suspended on a decision decidiq has not concluded
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again on the same ref, no second decision is raised, and the asked-at time is unchanged

#### Scenario: A withdrawn decision fails the step

- **GIVEN** a run suspended on a decision decidiq reports as withdrawn
- **WHEN** the run next re-enters the step
- **THEN** the step fails naming the decision, and the run neither advances nor waits on

#### Scenario: An unreadable seam buys another heartbeat

- **GIVEN** a run suspended on a decision, and a decidiq that cannot answer the read
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again rather than failing, and the decision is read again on the next heartbeat

`@e2e exclude` a suspend/resume timing path with no user-visible surface of its
own; pinned end to end through the real engine by
tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php.

### Requirement: The decision decides the outcome and the wake decorates it

The outcome `dossiq.requestDecision` writes onto every item under its
`signalKey` SHALL be derived from what decidiq reported — its status, the
decision ref, this node's id, when it was decided and whether it was signed —
and SHALL record whether it was delivered by a wake or recovered by a heartbeat.
A signal payload MAY contribute fields the read does not carry, and SHALL NOT
override the fields the decision decides.

A signal SHALL NOT be able to answer for a decision that is still open. The run
holds ONE signal slot, so a flow with two decisions would otherwise have the
second read the answer given to the first.

#### Scenario: A signal cannot answer for an open decision

- **GIVEN** a run suspended on a decision decidiq has not concluded
- **WHEN** a signal carrying a decision reaches the run
- **THEN** the node suspends again, because decidiq says the question is unanswered

#### Scenario: The announced and recovered paths agree

- **GIVEN** two runs on the same decision, one advanced by the announcement and one recovered by a heartbeat
- **THEN** both carry the same decision, status, ref and node under the step's key, differing only in whether it was recovered

`@e2e exclude` the shape of a value passed between flow steps; pinned by
RequestDecisionHeartbeatRecoveryTest and DossiqRequestDecisionNodeTest.

### Requirement: A decision step names the identity it raised the decision as

`dossiq.requestDecision` SHALL record the run's acting identity in its resume
slot when it raises a decision, and SHALL scope its read back to that identity.
decidiq stamps a Decision's owner from the uid that created it, so a read naming
any other uid is answered "not permitted".

A run that names no acting identity SHALL re-suspend and log, rather than
dispatching a read decidiq would refuse or inventing a system caller. A run
parked BEFORE this behaviour shipped, whose slot therefore records no identity,
SHALL fall back to the run's current acting identity, so it gains the recovery
on its next heartbeat without a repair step.

#### Scenario: A run parked before the change recovers without a repair

- **GIVEN** a run suspended on a decision whose resume slot records a ref but no raising identity
- **WHEN** the heartbeat wakes the run
- **THEN** the node reads the decision back as the run's current acting identity and recovers

`@e2e exclude` an authorization-scoping detail of an in-process read with no
user-visible surface; pinned by RequestDecisionHeartbeatRecoveryTest.
