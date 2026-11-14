# case-dialogs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-dialogs-contact-selects](../../)

## Purpose

Contact vastleggen asks what DqZaakDialogen draws.

## ADDED Requirements

### Requirement: Contact vastleggen asks channel and direction as two selects

The Log contact dialog SHALL ask the channel and the direction as two selects in one row, each labelled as optional and
each holding a value from the start. Every channel and direction the dialog offered SHALL stay choosable, and the saved
values SHALL not change.

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Contact vastleggen
- **THEN** Kanaal (niet verplicht) and Richting (niet verplicht) are selects on one row, set to Telefoon and Inkomend
- **AND** choosing E-mail and Uitgaand saves `email` and `outbound` as before
