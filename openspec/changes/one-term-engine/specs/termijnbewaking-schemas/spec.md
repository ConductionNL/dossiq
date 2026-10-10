## ADDED Requirements

### Requirement: Lead times and the work queue count working days on the administered calendar (REQ-OTE-04)

The planned end (`plannedLeadTime`) and the internal target
(`internalTargetDays`) SHALL be counted in working days on the administered
calendar through `WorkingDayRoll`, as their schema descriptions say, and then
rolled. The work queue's days-until-deadline SHALL be counted in working days
through the same reader. When no calendar answers, the lead times SHALL fall
back to calendar days and the work queue to Monday to Friday, and each SHALL
log the fall back. Phase terms, remedies and information requests keep the
counting their declarations name.

#### Scenario: A ten working day internal target spans a holiday
@e2e exclude a calendar count with no screen; covered by tests/Unit/Service/CaseTermsServiceTest.php

- **GIVEN** an administered calendar that works Monday to Friday and closes on Monday 2026-12-28
- **AND** a case type whose `internalTargetDays` is 10
- **WHEN** a case starting on Monday 2026-12-14 is created
- **THEN** its internal target SHALL end on Tuesday 2026-12-29
- **AND** not on Thursday 2026-12-24, which counting calendar days gives

#### Scenario: The work queue skips an administered closure day
@e2e exclude a scoring computation; covered by tests/Unit/Service/WorkQueueServiceTest.php

- **GIVEN** today is Friday 2027-04-23, the calendar closes on Tuesday 2027-04-27, and a case is due on Wednesday 2027-04-28
- **WHEN** the work queue scores it
- **THEN** its days until the deadline SHALL be 2
