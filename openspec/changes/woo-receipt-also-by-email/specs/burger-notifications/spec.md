# burger-notifications Delta: woo-receipt-also-by-email

**Status**: draft
**Scope**: an acknowledgement of receipt sent by e-mail beside the portal inbox, declared on the case type.

## ADDED Requirements

### Requirement: A case type may send its acknowledgement by e-mail as well (REQ-ACK-COPY-001)

A case type's `acknowledgement` declaration MAY set `emailCopy: true`. When it does and the acknowledgement went out through the portal inbox or digital post, the same notice SHALL also be mailed to the sender's address and recorded on the case as a message of its own. A copy that cannot be sent SHALL be recorded as not sent and SHALL NOT change the acknowledgement duty. Without the declaration no copy is sent. The shipped Woo request case type declares it.

#### Scenario: A Woo request filed on the portal is acknowledged in the inbox and by e-mail
@e2e exclude needs a mail server and a portal account; covered by RequesterNoticeSenderTest and AcknowledgementDutyTest
- **GIVEN** a resident files a Woo request on the portal with their e-mail address
- **WHEN** the acknowledgement of receipt goes out
- **THEN** it is in their portal inbox
- **AND** it is mailed to their address
- **AND** the case records two outbound messages, one per channel

#### Scenario: A case type without the declaration sends one message
@e2e exclude covered by RequesterNoticeSenderTest
- **GIVEN** a case type that does not declare `emailCopy`
- **WHEN** the acknowledgement goes to the portal inbox
- **THEN** no e-mail is sent

#### Scenario: A copy without an address does not undo the receipt
@e2e exclude covered by RequesterNoticeSenderTest
- **GIVEN** a case type that declares `emailCopy` and a case without an e-mail address
- **WHEN** the acknowledgement goes to the portal inbox
- **THEN** the duty is met and the copy is recorded as not sent
