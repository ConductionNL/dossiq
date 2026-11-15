# case-types delta: seeded-case-types-are-published

## ADDED Requirements

### Requirement: A shipped case type is published unless it says otherwise (REQ-CT-45)

Every case type the app ships SHALL declare `isDraft`. A shipped case type meant for
handlers SHALL declare `isDraft: false`, so the new case form offers it on a fresh
install. On an existing install the app SHALL publish, once, the shipped case types
that were stored as drafts only because their seed left `isDraft` unset, and SHALL
NOT change them again after that run.

#### Scenario: A fresh install offers every shipped case type
@e2e exclude a fresh install is not something an e2e run can make; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testEveryShippedCaseTypeDeclaresIsDraft

- **GIVEN** the shipped case type seeds
- **WHEN** they are read
- **THEN** every case type SHALL declare `isDraft`, and the 13 named in this change SHALL declare `false`

#### Scenario: An existing install publishes the seeded drafts once
@e2e exclude a repair step has no screen; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testTheSeededDraftsArePublishedAndNothingElse

- **GIVEN** an install where "Sloopmelding" is stored with `isDraft: true` and an administrator's own case type is a draft
- **WHEN** the repair step runs
- **THEN** "Sloopmelding" SHALL be stored with `isDraft: false`
- **AND** the administrator's own draft SHALL be left a draft

#### Scenario: A later draft is left alone
@e2e exclude a repair step has no screen; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testARecordedRunDoesNotRunAgain

- **GIVEN** the step has run once on this instance
- **WHEN** an administrator makes "Sloopmelding" a draft and repair runs again
- **THEN** "Sloopmelding" SHALL stay a draft
