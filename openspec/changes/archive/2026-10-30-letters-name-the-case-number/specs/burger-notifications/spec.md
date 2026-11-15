## ADDED Requirements

### Requirement: Every requester letter names the case by its number

Every letter to a requester rendered from a term template (the receipt, the information request, the reminder on a paused term, the inadmissibility letter, the transfer notice, the default notice and the penalty payment) SHALL name the case by its case number in the title and in the body. When the reference a caller hands over is a case UUID, the number SHALL be read from that case. A reference that is already a number SHALL be used as written. The opt-out link and the delivery record SHALL keep the case UUID.

#### Scenario: The information request names the case number when handed the UUID
- **GIVEN** a case with identifier `2026-0018` and a caller that passes the case UUID as `case`
- **WHEN** the information request is sent
- **THEN** the title SHALL read "Wij hebben nog iets nodig voor zaak 2026-0018"
- **AND** the body SHALL NOT contain the UUID

#### Scenario: A case that cannot be read keeps the reference it was given
- **GIVEN** a caller that passes a case UUID and a case that cannot be read
- **WHEN** a letter is rendered
- **THEN** the letter SHALL name the reference it was given rather than fail

@e2e exclude Rendering is covered by unit tests through TermijnNotificationService::sendTermijnNotification(); the portal inbox only displays the stored subject.
