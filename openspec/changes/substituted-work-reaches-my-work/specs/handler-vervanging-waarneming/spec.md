## ADDED Requirements

### Requirement: Substituted work is on My work, marked and hideable

My work SHALL call `fetchSubstitutedWork()` and list the returned cases and
tasks among the signed-in user's own, each marked with the absent handler's
name, and SHALL offer a toggle to hide them. The marker SHALL name the
absentee and the substitution's end date.

#### Scenario: A waarnemer sees the absentee's case
@e2e tests/e2e/spec-coverage/handler-vervanging-waarneming.spec.ts

- **GIVEN** an active substitution naming you as waarnemer for Anna
- **AND** a case assigned to Anna inside the substitution's scope
- **WHEN** you open My work
- **THEN** the case SHALL be listed with the marker "for Anna"
- **AND** hiding substituted work SHALL remove it from the list

### Requirement: Leave in humaniq sets the substitution period

When the absentee has an approved humaniq leave request covering today,
the substitution SHALL be active for the leave's period. Without one, the
substitution's own dates SHALL rule. humaniq being absent SHALL leave the
typed dates in force.

#### Scenario: Leave extends the period
@e2e exclude cross-app fixture; covered by SubstitutionServiceTest::testLeaveOverridesTypedPeriod over a leave stub

- **GIVEN** a substitution typed to end yesterday and an approved leave ending next week
- **WHEN** the resolver runs today
- **THEN** the substitution SHALL be active

#### Scenario: No humaniq, no change
@e2e exclude covered by SubstitutionServiceTest::testWithoutHumaniqTypedDatesRule

- **GIVEN** humaniq is not installed
- **WHEN** the resolver runs
- **THEN** the typed dates SHALL rule and one info line SHALL be logged
