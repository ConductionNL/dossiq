## MODIFIED Requirements

### Requirement: WOO deadline tracking and extension
The system MUST enforce WOO-mandated response deadlines with support for
statutory extension, through the generic term engine and not through a clock of
its own. A Woo request's statutory term MUST be a term instance bound through
the seeded definition `td-woo-verzoek` (28 days, Woo art. 4.4 lid 1, one
extension of 14 days under lid 2), starting at the case's `termStartsAt`
(REQ-OTE-02) and rolled by the Algemene termijnenwet. The case `deadline` MUST
follow that instance (REQ-OTE-01). The extension route MUST NOT write
`expectedResolution`, `deadlineVerlengd` or `verdagingReden` on the case.

#### Scenario: Calculate initial response deadline
@e2e exclude the bind runs in a create listener; covered by tests/Unit/Service/WOODeadlineServiceTest.php

- **GIVEN** a WOO request received on "2026-03-16", a Monday and a working day
- **WHEN** the case is created
- **THEN** its statutory term instance MUST be bound through `td-woo-verzoek`
- **AND** the case deadline MUST be 28 calendar days later, "2026-04-13"
- **AND** display the deadline in the `DeadlinePanel.vue` component

#### Scenario: A Woo term ending on a holiday rolls
@e2e exclude a calendar roll; covered by tests/Unit/Service/WOODeadlineServiceTest.php

- **GIVEN** an organisation calendar that marks 2026-12-25 and 2026-12-26 as holidays
- **WHEN** a Woo request received on Friday 2026-11-27 is created
- **THEN** the case `deadline` MUST be Monday 2026-12-28

#### Scenario: Warning thresholds
@e2e exclude a front-end threshold on a fixed date; covered by tests/vitest/deadlineCountdown.spec.js

- **GIVEN** a WOO case with a deadline of "2026-04-13"
- **WHEN** 14 days remain, the `DeadlinePanel` MUST show a yellow warning
- **AND** when 7 days remain, the panel MUST show a red urgent alert
- **AND** only from the day after the deadline MUST the panel show "Termijn verlopen" in red

#### Scenario: Request deadline extension (verdaging)
@e2e exclude the extension goes through DeadlineExtensionService behind the route; covered by tests/Unit/Service/WOODeadlineServiceTest.php and tests/Unit/Controller/WOOAssessmentControllerTest.php

- **GIVEN** a WOO case whose term instance has `endDateCurrent` 2026-11-02, the original end of its four weeks, and `countExtensions` 0
- **WHEN** the case worker extends with the reason "Zienswijzen van derden"
- **THEN** the case's statutory instance MUST be extended to 2026-11-16, the original end plus 14 days, rolled
- **AND** the instance's `countExtensions` MUST be 1 and the case `deadline` MUST be 2026-11-16

#### Scenario: The extension counts from the original end, unrolled
@e2e exclude a calendar roll; covered by tests/Unit/Service/WOODeadlineServiceTest.php and tests/Unit/Service/DeadlineExtensionLimitTest.php

- **GIVEN** a WOO request received on Saturday 2026-05-02, whose four weeks end on Saturday 2026-05-30 and roll to Monday 2026-06-01
- **WHEN** the case worker extends it
- **THEN** the 14 days MUST count from the original end as counted, Saturday 2026-05-30, not from the rolled Monday (Ruben, 2026-10-09)
- **AND** the requested end Saturday 2026-06-13 MUST then be rolled by the Algemene termijnenwet, to Monday 2026-06-15
- **AND** `endDateBeforeRoll` MUST read 2026-06-13
- **AND** the term's event list MUST hold a `verleng` event with that reason

#### Scenario: Only one extension allowed
@e2e exclude as above

- **GIVEN** a WOO case that has already been extended once
- **WHEN** the case worker extends again
- **THEN** the route MUST answer 409 naming the one-extension rule
- **AND** the cap MUST be read from the term instance, so it holds when the case schema drops undeclared keys

#### Scenario: Extension resets warning thresholds
@e2e exclude a front-end threshold; covered by tests/vitest/deadlineCountdown.spec.js

- **GIVEN** a WOO case with original deadline "2026-04-13" extended to "2026-04-27"
- **THEN** the warning thresholds MUST count down to "2026-04-27", the new case `deadline`
