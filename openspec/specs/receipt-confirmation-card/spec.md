# receipt-confirmation-card Specification

## Purpose
What dossiq does for receipt confirmation card. The requirements were first written by the change `receipt-confirmation-card-reads` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-receipt-confirmation-card-reads/`) and cover: Outbound messages read as lines; The receipt confirmation card reads in words.

## Requirements

### Requirement: Outbound messages read as lines

The `outboundMessages` formatter SHALL write each entry of a case's outbound communications as one line: the moment's
label, "via" the channel's label, and the time it was sent (day, short month, hours and minutes). An entry without a
channel SHALL read as not sent. The portal channel SHALL be named by the `portalName` portaliq holds (app config `portaliq/portalName`, handed to the page as `config.portalName`), and "the portal" when portaliq names none. Lines SHALL be joined with "; ". An empty or missing list SHALL give an empty string.

#### Scenario: an acknowledgement through the portal

- **GIVEN** `[{ moment: 'acknowledgement', channel: 'portal-inbox', sentAt: '2026-10-03T12:32:00Z' }]`
- **THEN** the formatter returns "Receipt confirmation via the portal, " followed by the local day, month and time

#### Scenario: portaliq names the portal

- **GIVEN** portaliq's app config `portalName` is "Mijn Zuiddrecht"
- **THEN** the same message reads "Receipt confirmation via Mijn Zuiddrecht, ..."

### Requirement: The receipt confirmation card reads in words

The case page's receipt confirmation card SHALL list received at, received outside working hours, term starts at and
outbound communications in that order, received at through the `datetime` formatter, term starts at through `date`,
and outbound communications through `outboundMessages`.

#### Scenario: DqZaakContact

- **GIVEN** a case with a receipt date and one acknowledgement sent
- **THEN** the card shows the date in words and "Receipt confirmation via the portal, ..." instead of a table of codes
