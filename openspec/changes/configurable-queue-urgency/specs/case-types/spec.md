## ADDED Requirements

### Requirement: A case type sets when its cases turn critical and almost due in the queue

The case type MUST offer two optional integer fields, `queueCriticalDays`
(0 to 60) and `queueWarningDays` (0 to 120), on the General tab of the case
type editor, labelled "Critical from, working days left" and "Almost due from,
working days left", with a hint that an empty field uses the instance default
from the admin settings. They steer only the deadline tier of the work queue
(see `werkvoorraad-intelligent-queue`); they are separate from
`statutoryWarningDays` and `plannedWarningDays`, which name a warning on one
specific term.

#### Scenario: The editor offers both fields, empty by default
@e2e exclude Asserted by tests/vitest/caseTypeQueueThresholds.spec.js over the General tab; a browser run needs a published case type fixture the shared rig resets.

- GIVEN an administrator opens a case type's General tab
- THEN two number fields for the queue thresholds MUST be shown
- AND both MUST be empty when the case type sets neither

#### Scenario: A value saves on the case type
@e2e exclude The save is the case type editor's existing save of its form; the schema accepting the field is asserted by tests/Unit/Settings/QueueThresholdSchemaTest.php.

- GIVEN an administrator enters 10 in "Critical from, working days left"
- WHEN the case type is saved
- THEN the case type MUST carry `queueCriticalDays` 10
