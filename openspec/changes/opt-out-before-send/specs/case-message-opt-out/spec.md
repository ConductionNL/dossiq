## ADDED Requirements

### Requirement: Case mail asks integriq before it is sent (REQ-CMO-101)

Before dossiq sends a case mail to a citizen, it MUST ask integriq through `OutboundSendDecisionRequestedEvent`, resolved with `FleetAppId` and guarded with `class_exists()`, with channel `email`, the category and the case as `caseRef`. A decision of `send: false` MUST stop the mail, MUST NOT record it as sent, and MUST answer 409 with the decision code as `error`. This holds for `sendEmail` and `sendFromTemplate`. Feature tier: V1. This implements ConductionNL/hydra `openspec/changes/opt-out-before-send` REQ-CMO-001 and REQ-CMO-002. Uses `OCP\EventDispatcher\IEventDispatcher` and `OCP\Mail\IMailer`.

#### Scenario: A recipient who stopped this case gets no mail

- **GIVEN** the recipient followed integriq's link and stopped updates on case `Z-2026-001`
- **WHEN** a handler sends a `case-update` mail about `Z-2026-001` to that recipient
- **THEN** no mail is sent and no sent-mail record is written
- **AND** the answer is 409 with `error: opted-out`

#### Scenario: A case opt-out does not stop another case

- **GIVEN** the recipient stopped updates on case `Z-2026-001` only
- **WHEN** a handler sends a `case-update` mail about case `Z-2026-002`
- **THEN** the mail is sent with an unsubscribe link for `Z-2026-002`
- @e2e exclude backend send path, covered by PHPUnit

#### Scenario: integriq is not installed

- **GIVEN** an instance without integriq
- **WHEN** a handler sends a `case-update` mail
- **THEN** no mail is sent
- **AND** the answer is 409 with `error: authority-unavailable`
- @e2e exclude needs an instance without integriq, covered by PHPUnit

### Requirement: A handler can send a besluit that is always delivered (REQ-CMO-102)

A handler MUST be able to mark a case mail as a besluit. A besluit mail MUST be sent whatever the recipient's opt-outs say, and MUST carry no unsubscribe link and no `List-Unsubscribe` header. A handler MUST NOT be able to send any category other than `case-update` or `besluit` from the case mail dialog. An email template MAY declare `messageCategory` as `case-update`, `besluit` or `statutory`.

#### Scenario: A besluit reaches a recipient who opted out

- **GIVEN** the recipient has an instance-wide opt-out
- **WHEN** a handler sends a mail marked as a besluit
- **THEN** the mail is sent without an unsubscribe link
- **AND** integriq records an override for dossiq

#### Scenario: A handler cannot pick marketing

- **WHEN** a request to `/api/email/{caseId}/send` carries `category: marketing`
- **THEN** the answer is 400 and no mail is sent
- @e2e exclude API validation, covered by PHPUnit

### Requirement: Every non-exempt case mail carries the unsubscribe link (REQ-CMO-103)

A `case-update` mail MUST carry integriq's unsubscribe link in its body. It MUST carry `List-Unsubscribe` and `List-Unsubscribe-Post` when the mailer exposes headers. dossiq MUST NOT mint its own token.

#### Scenario: The link is in the body

- **GIVEN** a recipient with no opt-out
- **WHEN** a handler sends a `case-update` mail
- **THEN** the sent body contains integriq's unsubscribe line for that case
- @e2e exclude mail rendering, covered by PHPUnit

### Requirement: Digital post carries a category to integriq (REQ-CMO-104)

dossiq MUST pass a category on `DigitalPostSendRequestedEvent`: `case-update` by default, or `besluit` or `statutory` when the handler marks it. dossiq MUST NOT ask integriq a second time for digital post. It MUST show integriq's refusal, including `opted-out`, to the handler.

#### Scenario: A Berichtenbox case update to an opted-out citizen is refused

- **GIVEN** the citizen stopped updates on case `Z-2026-001`
- **WHEN** a handler sends a Berichtenbox message about `Z-2026-001` with category `case-update`
- **THEN** integriq refuses with code `opted-out`
- **AND** dossiq shows "Nothing was sent" with the reason
- @e2e exclude digital post provider path, covered by PHPUnit

#### Scenario: A Berichtenbox besluit is delivered

- **GIVEN** the citizen has an instance-wide opt-out
- **WHEN** a handler sends a Berichtenbox message with category `besluit`
- **THEN** integriq sends it
- @e2e exclude digital post provider path, covered by PHPUnit
