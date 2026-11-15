# burger-notifications (delta)

## ADDED Requirements

### Requirement: A term notice is mailed after integriq allows it (REQ-TERM-070)

A term notice MUST be mailed to the recipient's e-mail address. Before it is built, dossiq MUST ask integriq through `OptOutGate` with channel `email`, the case as `caseRef`, and category `service`; the ontvangstbevestiging MUST use `statutory`. A refused notice MUST NOT be sent, MUST be logged with the refusal code, and MUST NOT be reported to the caller as sent. A non-exempt notice MUST carry integriq's unsubscribe line and the `List-Unsubscribe` headers. A recipient that is not an e-mail address MUST be refused. Approved by Ruben on 2026-10-07.

#### Scenario: A due reminder reaches a normal citizen

- **GIVEN** a term paused on the applicant with a reminder due
- **WHEN** the daily sweep runs
- **THEN** the applicant receives one mail with the unsubscribe line and headers
- @e2e exclude background job, covered by PHPUnit and the live check in the PR

#### Scenario: A citizen who opted out gets nothing

- **GIVEN** the applicant opted out of `service` messages
- **WHEN** the reminder is due
- **THEN** no mail is sent, the refusal is logged with code `opted-out`, and the reminder is not counted
- @e2e exclude background job, covered by PHPUnit and the live check in the PR

#### Scenario: The acknowledgement of receipt is statutory

- **GIVEN** the applicant opted out
- **WHEN** the ontvangstbevestiging is sent
- **THEN** it is mailed without an unsubscribe line
- @e2e exclude background job, covered by PHPUnit

### Requirement: A term notice is sent once per deadline (REQ-TERM-071)

dossiq MUST claim a key per notice in `dossiq_term_notices` before it asks integriq. A second claim on the same key MUST NOT send. A reminder's key MUST be the term and the reminder number. A refusal by an opt-out MUST be kept on the key; a failure that may pass (integriq absent, no sender address, the mail server refusing) MUST give the key back so a later run retries.

#### Scenario: Two triggers on one reminder mail once

- **GIVEN** the engine rung and the daily sweep both find reminder 1 due on a term
- **WHEN** both run
- **THEN** one mail is sent and integriq is asked once
- @e2e exclude background job, covered by PHPUnit and the live check in the PR
