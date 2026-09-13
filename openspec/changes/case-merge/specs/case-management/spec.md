## ADDED Requirements

### Requirement: Two cases merge into one through the platform (REQ-CM-37)

The `case` schema SHALL declare its `mdm-merge` rule (relinked parts, kept
fields, reversal window). `#CaseDetail` SHALL offer Merge into, which picks
a survivor and hands the merge to OpenRegister. The merged case SHALL end
with result `merged` and `mergedInto` set, and its active term SHALL be
completed with reason merged. A reversal inside the window SHALL reopen
the case and re-arm its term.

#### Scenario: A duplicate is merged
@e2e tests/e2e/case-merge.spec.ts

- **GIVEN** two open cases for the same applicant, each with a task
- **WHEN** you merge the second into the first
- **THEN** the first SHALL carry both tasks
- **AND** the second SHALL read result merged with a link to the first

#### Scenario: The merged term is closed, the survivor's counts
@e2e exclude covered by the merge-event listener unit test over the term service stub

- **GIVEN** the merge above with a running term on each case
- **WHEN** the merge event is handled
- **THEN** the second case's instance SHALL be completed with reason merged
- **AND** the first case's instance SHALL be unchanged

### Requirement: The old number still finds the case (REQ-CM-38)

A mail or a portal message addressed to a merged case SHALL be routed to
the survivor, and the merged case's public status page SHALL show the
survivor's status.

#### Scenario: A reply to the old number
@e2e exclude the inbound path runs in the mail intake; covered by EmailCaseMatchingTest::testMergedResolvesToSurvivor

- **GIVEN** a mail quoting the merged case's number
- **WHEN** it is matched
- **THEN** it SHALL be filed on the survivor

#### Scenario: The old public link
@e2e tests/e2e/case-merge.spec.ts

- **GIVEN** the merged case's public status link
- **WHEN** an applicant opens it
- **THEN** the survivor's status SHALL be shown
