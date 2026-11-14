## ADDED Requirements

### Requirement: REQ-DSO-016 -- The deadline job acts on open DSO cases

The DSO deadline job SHALL find open DSO cases by `dsoStatus` (`submitted` or `in_handling`), because the case stores `caseType` and `status` as uuids. It SHALL tell the case's `assignee` as the deadline approaches and passes, and it SHALL mark an overdue case once, with a patch of the declared `deadlineOverdue` field and a journal entry, written as the background service account.

#### Scenario: a due DSO deadline is acted on
@e2e exclude a cron job with no browser gesture; covered by DsoDeadlineJobServiceAccountTest and the live check in the PR

- **GIVEN** a case with `dsoStatus = in_handling` whose `deadlineDate` has passed
- **WHEN** the job runs
- **THEN** its assignee SHALL be told the deadline is overdue
- **AND** the case SHALL be marked `deadlineOverdue = true` by the background service account

#### Scenario: a decided or non-DSO case is left alone
@e2e exclude a cron job with no browser gesture; covered by DsoDeadlineJobServiceAccountTest::testTheRunWritesAsTheServiceAccount

- **GIVEN** a case with `dsoStatus = granted`, and a case with no `dsoStatus`
- **WHEN** the job runs
- **THEN** neither SHALL be written or notified
