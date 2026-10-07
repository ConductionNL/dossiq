## MODIFIED Requirements

### Requirement: A case type may close a case after declared silence (REQ-LIFE-04)

A case type MAY opt in to closing a case after a period of no activity. The default SHALL be off, and a declared period on its own SHALL NOT switch it on: only `autoCloseOnSilence = true` does. Before the close, the applicant SHALL be told it is coming, through the declared automatic moments. The close SHALL record that the product performed it, the period declared, and the last activity it counted from. The sweep SHALL run as the background service account, and that account SHALL abort only cases of a case type that opted in. It SHALL NOT finish or archive a case. OpenRegister cannot scope a grant on the case schema per case type, so dossiq SHALL enforce this in its role gate, before the administrator bypass.

#### Scenario: a bezwaar waiting on the indiener does not sit open forever
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case type that opted in and declares 60 days of silence
- **WHEN** a case has had no activity for 60 days
- **THEN** it SHALL be closed, by the background service account
- **AND** the close SHALL record that the product did it

#### Scenario: the applicant is warned first
@e2e tests/e2e/lifecycle-acts-on-the-case.spec.ts

- **GIVEN** a case of an opted-in case type approaching its declared silence period
- **WHEN** the warning moment is reached
- **THEN** the applicant SHALL be told the case will close

#### Scenario: off by default

- **GIVEN** a case type with no declaration
- **WHEN** a case has had no activity for a year
- **THEN** it SHALL NOT be closed

#### Scenario: a period without the opt-in closes nothing after an upgrade
@e2e exclude a cron sweep with no browser gesture; covered by AutoCloseOnSilenceJobServiceAccountTest::testACaseTypeThatDidNotOptInClosesNothing and the live check in the PR

- **GIVEN** an existing case type that declares 30 days of silence and no `autoCloseOnSilence`
- **WHEN** the sweep runs after the upgrade on a case silent for 40 days
- **THEN** the case SHALL NOT be closed
- **AND** no warning SHALL be written

#### Scenario: the background account aborts only an opted-in case type
@e2e exclude a role check with no browser gesture; covered by AutoCloseOnSilenceJobServiceAccountTest::testTheAccountMayAbortOnlyAnOptedInCaseType

- **GIVEN** the background service account as the acting user
- **WHEN** it asks to abort a case of a case type that did not opt in, or to finish or archive any case
- **THEN** the act SHALL be refused
