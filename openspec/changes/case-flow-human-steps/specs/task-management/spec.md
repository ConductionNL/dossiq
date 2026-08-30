## ADDED Requirements

### Requirement: A task may hold a suspended flow run, and completing it resumes that run @e2e exclude the resume mechanism is asserted by service tests; the user-visible half is covered by the case-flow e2e journey

A task MAY record the flow run and node that created it. Completing such a task SHALL resume that run at that node, passing the task's outcome so the steps after it can route on what the person actually decided.

The run and the node are BOTH required to resume: a run accumulates one awaiting slot per node across its life, so a task naming only its run cannot say which question it is the answer to.

A task with no run SHALL behave exactly as tasks did before — completing it is not an error and resumes nothing.

#### Scenario: Completing a flow task resumes its run
- **WHEN** a task recording a run and node is completed
- **THEN** that run is resumed at that node
- **AND** the task's outcome is available to the steps that follow

#### Scenario: A task without a run resumes nothing
- **WHEN** a task recording no run is completed
- **THEN** the task is completed normally
- **AND** no run is resumed and no error is raised

#### Scenario: Completing a task twice resumes once
- **WHEN** an already-completed task is completed again
- **THEN** the run is not resumed a second time
- **AND** the run does not advance past the step twice

#### Scenario: A task whose run has gone is still completable
- **WHEN** a task names a run that no longer exists
- **THEN** completing the task succeeds
- **AND** the inability to resume is recorded rather than raised to the person completing it

### Requirement: A person can see what their task is holding up @e2e exclude read surface; covered by the case-flow e2e journey

A task belonging to a flow run SHALL show the case it belongs to and that something is waiting on it. A person answering a question is entitled to know that work is blocked on their answer, and which work.

#### Scenario: A flow task names its case
- **WHEN** a person views a task created by a case flow
- **THEN** the task names the case it belongs to
- **AND** indicates that the case is waiting on this task
