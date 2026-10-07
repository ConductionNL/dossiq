## ADDED Requirements

### Requirement: A bulk act is handed to a job and reports per row (REQ-BLK-01)

dossiq SHALL hand every bulk act on cases to openregister's bulk job and
SHALL NOT iterate in the browser. dossiq SHALL render the job's progress
and its per-row outcome, including a list of the rows it skipped and the
reason for each. dossiq SHALL NOT implement a job runner, a retry or a
cancellation.

#### Scenario: four hundred cases, twelve skipped, and the twelve are named
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a bulk status transition over four hundred cases where twelve refuse
- **WHEN** the job finishes
- **THEN** the handler SHALL see the twelve, each with its reason

#### Scenario: closing the tab does not stop the act
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a running bulk act
- **WHEN** the handler closes the page and returns
- **THEN** the job SHALL still be running or finished
- **AND** its outcome SHALL be readable

#### Scenario: dossiq runs no job of its own

- **GIVEN** the dossiq tree
- **WHEN** it is read for a bulk iteration over cases
- **THEN** none SHALL exist outside the hand-off to the job

### Requirement: A bulk distribution needs a written justification (REQ-BLK-02)

Reassigning cases in bulk SHALL require a written justification before the
job is handed over. The justification SHALL be stored with the act and
SHALL be readable afterwards. An empty justification SHALL refuse the act.

#### Scenario: a coordinator explains a mass reassignment
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a coordinator reassigning four hundred cases
- **WHEN** they submit without a justification
- **THEN** the act SHALL be refused

#### Scenario: the reason survives the act
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a completed bulk reassignment
- **WHEN** the act is read afterwards
- **THEN** the justification SHALL be shown with it

### Requirement: A bulk attribute change is refused across case type versions (REQ-BLK-03)

A bulk change to a case-type attribute SHALL be refused when the selection
spans more than one version of a case type. The refusal SHALL happen at
selection time, before any simulation, and SHALL name the versions in the
selection.

#### Scenario: a selection spanning two versions is stopped early
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a selection holding cases on two versions of one case type
- **WHEN** a handler asks for a bulk attribute change
- **THEN** it SHALL be refused, naming both versions
- **AND** no simulation SHALL run

### Requirement: Select-all says which all it means (REQ-BLK-04)

When a handler selects every row, dossiq SHALL state in words whether the
selection is the current page or every row matching the search, and SHALL
state the number. Moving from the page to the whole result SHALL be a
separate, deliberate act.

#### Scenario: a handler knows whether they have 25 or 400
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a search matching four hundred cases, showing twenty-five
- **WHEN** the handler selects all on the page
- **THEN** the product SHALL say twenty-five on this page
- **AND** SHALL offer to select all four hundred as a separate act

### Requirement: A departed handler's caseload is released through the same job (REQ-BLK-05)

Releasing the caseload of a handler who left or is absent SHALL build a
selection and hand it to the same bulk job, with the same justification
requirement. `SubstitutionController` SHALL NOT iterate over cases itself.

#### Scenario: a handler leaves and their work is released
@e2e tests/e2e/bulk-actions-report-progress.spec.ts

- **GIVEN** a handler with forty open cases who has left
- **WHEN** a coordinator releases their caseload with a justification
- **THEN** the release SHALL run as a job reporting per row
