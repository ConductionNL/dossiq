# background-jobs Specification

## Purpose
TBD - created by archiving change background-jobs-decisions. Update Purpose after archive.

## Requirements

### Requirement: A job dossiq no longer schedules leaves the job list

Nextcloud adds the jobs `appinfo/info.xml` names on upgrade and never removes one. dossiq SHALL remove every job it parks or deletes from the instance's job list on upgrade, whatever argument the job was queued with.

#### Scenario: the parked and removed jobs are gone after the upgrade
@e2e exclude a repair step with no browser surface; covered by tests/Unit/Repair/RetireUnscheduledBackgroundJobsTest.php and the live `occ background-job:list` check in the PR

- **GIVEN** an instance that had `EmailPdfRetryJob`, `AppointmentReminderJob` or `BerichtenboxReadStatusJob` in its job list
- **WHEN** dossiq is upgraded
- **THEN** none of the three SHALL be in the job list
- **AND** none of them SHALL be named in `appinfo/info.xml`

### Requirement: The PDF retry waits for the filinq adapter

`EmailPdfRetryJob` SHALL NOT be scheduled until a filinq adapter converts a mail to PDF. Scheduled without it, every pass only re-marks the row failed and drops it after three attempts.

#### Scenario: the retry does not run
@e2e exclude a job that is not scheduled has no surface; covered by TimedJobRegistrationTest

- **GIVEN** no filinq PDF adapter
- **WHEN** cron runs
- **THEN** `EmailPdfRetryJob` SHALL NOT run
- **AND** its class docblock SHALL say why
