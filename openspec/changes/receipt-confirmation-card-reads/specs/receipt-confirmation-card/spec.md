# receipt-confirmation-card Delta

## ADDED Requirements

### Requirement: Outbound messages read as lines

The `outboundMessages` formatter SHALL write each entry of a case's outbound communications as one line: the moment's
label, "via" the channel's label, and the time it was sent (day, short month, hours and minutes). An entry without a
channel SHALL read as not sent. Lines SHALL be joined with "; ". An empty or missing list SHALL give an empty string.

#### Scenario: an acknowledgement through the portal

- **GIVEN** `[{ moment: 'acknowledgement', channel: 'portal-inbox', sentAt: '2026-10-03T12:32:00Z' }]`
- **THEN** the formatter returns "Receipt confirmation via the portal, " followed by the local day, month and time

### Requirement: The receipt confirmation card reads in words

The case page's receipt confirmation card SHALL list received at, received outside working hours, term starts at and
outbound communications in that order, received at through the `datetime` formatter, term starts at through `date`,
and outbound communications through `outboundMessages`.

#### Scenario: DqZaakContact

- **GIVEN** a case with a receipt date and one acknowledgement sent
- **THEN** the card shows the date in words and "Receipt confirmation via the portal, ..." instead of a table of codes
