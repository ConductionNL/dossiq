## ADDED Requirements

### Requirement: A planned follow-up can repeat (REQ-WDE-PS-1)

You plan a follow-up once and it comes back on schedule. The plan-follow-up
form SHALL offer a recurrence of none, monthly, quarterly, half-yearly or
yearly, and an end as a date or a count. The planned follow-up document SHALL
express the recurrence as the scheduled flow's cron fields and SHALL keep
`runAs` on every occurrence. A document with recurrence none SHALL behave as
today.

#### Scenario: A yearly inspection is planned once
@e2e tests/e2e/planned-case-series.spec.ts

- **GIVEN** an open case of a type that allows a follow-up
- **WHEN** you plan a follow-up with recurrence yearly and an end after 3 occurrences
- **THEN** one scheduled flow SHALL exist for the case with yearly cron fields
- **AND** the Related tab SHALL show the series with its next occurrence

#### Scenario: A single follow-up is unchanged
@e2e tests/e2e/planned-case-series.spec.ts

- **GIVEN** an open case
- **WHEN** you plan a follow-up with recurrence none
- **THEN** the flow SHALL carry the planned date as before
- **AND** the sweep SHALL switch it off after it fires

### Requirement: The sweep stops a series when it is spent (REQ-WDE-PS-2)

The sweep SHALL keep a recurring series armed until its end date has passed
or its count of occurrences is reached, and SHALL then switch the flow off
with the reason recorded on the flow.

#### Scenario: The third occurrence is the last
@e2e exclude time-dependent; covered by a PlannedFollowUpDocument unit pair that steps the sweep clock through three fires

- **GIVEN** a series with a count of 3 and two occurrences created
- **WHEN** the third occurrence fires and the sweep runs
- **THEN** the flow SHALL be switched off with reason "series complete"

### Requirement: Occurrences are findable as a series (REQ-WDE-PS-3)

Every case an occurrence creates SHALL carry `handoffSource` naming the
series flow, and the Related tab SHALL list those cases under the series.

#### Scenario: Two occurrences show under one series
@e2e tests/e2e/planned-case-series.spec.ts

- **GIVEN** a series that has created two cases
- **WHEN** you open the Related tab of the source case
- **THEN** both cases SHALL be listed under the series row
- **AND** Stop series SHALL be offered on that row
