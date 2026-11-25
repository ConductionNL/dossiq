# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [party-contact-moments-board-lines](../../)

## ADDED Requirements

### Requirement: A party's latest contact moments read moment, channel, direction and case

The latest contact moments card of a contact page and of an organisation page SHALL show for each moment a meta
line with the moment, the channel, the direction and the case number, and SHALL show the count at the right of the
card title.

#### Scenario: DqContact

- **GIVEN** Sanne de Vries wrote in through e-mail about case 2026-0082
- **THEN** the row's meta line reads "4 okt, 10:52 · e-mail · inkomend · zaak 2026-0082"
- **AND** the card title row reads "Laatste contactmomenten" with "3 in totaal" at the right

#### Scenario: A moment without a case

- **GIVEN** a contact moment that names no case
- **THEN** its meta line ends at the direction, without "zaak"
