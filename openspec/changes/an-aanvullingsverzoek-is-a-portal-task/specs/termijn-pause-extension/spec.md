## ADDED Requirements

### Requirement: An aanvullingsverzoek is a task in the resident's portal (REQ-AVR-06)

When a handler asks the applicant for missing information on a case that has a `portalSubject`, dossiq MUST also raise an OpenRegister external task for that resident: performer type `external`, assignee `party:` followed by the case's `portalSubject`, the case as the task's subject, due at the end of the hersteltermijn day, with every missing item in its description. The request MUST remember the task in `portalTask`. When the request leaves `open` (answered, expired or withdrawn), dossiq MUST close the task and record why. A case without a `portalSubject` MUST raise no task. A task that cannot be raised or closed MUST NOT refuse the ask or the answer; it MUST be logged.

#### Scenario: Asking puts a task on the resident's list

- **GIVEN** a case with `portalSubject` `person:bsn-hash-1` and a running term
- **WHEN** the handler asks for "Bankafschrift" and "Huurcontract" with a hersteltermijn ending 24 October 2026
- **THEN** OpenRegister holds an active external task assigned to `party:person:bsn-hash-1` on that case, due 24 October 2026 23:59 Amsterdam time, naming both items
- **AND** the request's `portalTask` names that task
- @e2e exclude backend write, covered by PHPUnit `ResidentQuestionTaskTest` and `AanvullingsverzoekServiceTest::testAskingRaisesThePortalTaskAndRemembersIt`; the portal list is the live pass (task 3.1)

#### Scenario: Answering closes the task

- **GIVEN** an open request with a portal task
- **WHEN** the handler records the answer
- **THEN** the task is terminated with the reason that the request is answered, and leaves the resident's list
- @e2e exclude backend write, covered by PHPUnit `AanvullingsverzoekServiceTest::testAWriteThatLeavesOpenClosesThePortalTask`

#### Scenario: A failed task never refuses the ask

- **GIVEN** OpenRegister refuses to create the task
- **WHEN** the handler asks
- **THEN** the letter is sent, the term is suspended, the request is written without `portalTask`, and the failure is logged
- @e2e exclude failure path, covered by PHPUnit `ResidentQuestionTaskTest::testNoTaskIsRaisedWithoutAResidentAndAFailureNeverThrows`

### Requirement: A question to a resident is a task in their portal (REQ-AVR-07)

Any question dossiq puts to a resident on a case with a `portalSubject` MAY be raised as an OpenRegister external task through one generic capability: assignee `party:` followed by the portal subject, the case as subject, the caller's title, the asked items in the description, due at the end of the caller's due day. The capability MUST close the task as moot, with the caller's reason and source, when the caller says the question is settled, and MUST NOT throw: a task that cannot be raised or closed is logged. It MUST NOT carry procedure knowledge; the caller supplies the title, items, due date and source (decision 182). REQ-AVR-06 is its configuration for the aanvullingsverzoek.

#### Scenario: Any caller's question becomes the resident's task

- **GIVEN** a caller with a question titled "Vul uw aanvraag aan", two items and a due date, on a case with a portal subject
- **WHEN** it raises the question
- **THEN** OpenRegister holds one active external task for the resident with that title, both items and that due day
- @e2e exclude backend write, covered by PHPUnit `ResidentQuestionTaskTest`
