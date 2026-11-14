## Purpose

Defines how a case is walked by one flow run from intake to closure: where that run pauses for a person, what the person is shown while it waits, how their answer wakes exactly the step that asked, and what the applicant sees of the case's progress throughout.

## ADDED Requirements

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
