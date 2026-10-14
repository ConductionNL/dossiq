## ADDED Requirements

### Requirement: A requester letter names the case by its number

A letter to a requester (the receipt, an extension notice, a transfer notice) SHALL name the case by the case number its caller passes as the kenmerk, in the title and in the body. It SHALL fall back to the term instance's case link only when the caller passes no kenmerk. It SHALL NOT show a case UUID when the case carries a number.

#### Scenario: The receipt names the case number when the term links the case by UUID
- **GIVEN** a case with identifier `2026-0117` and a bound term whose `case` is the case UUID
- **WHEN** the acknowledgement of receipt is sent
- **THEN** the title SHALL read "Ontvangstbevestiging zaak 2026-0117"
- **AND** the body SHALL name kenmerk `2026-0117` and SHALL NOT contain the UUID

@e2e exclude Rendering is covered by the unit test through AcknowledgementService::acknowledge(); the portal inbox only displays the stored subject.
