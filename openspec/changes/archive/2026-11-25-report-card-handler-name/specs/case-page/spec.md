# case-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [report-card-handler-name](../../)

## ADDED Requirements

### Requirement: The report card names the handler

The report card of a case SHALL show the handler by display name and team, not by user id, under the case type's
handler label (`case-type-handler-label`: "Behandelaar" when the case type declares none).

#### Scenario: DqSociaalDomein

- **GIVEN** a youth case of the Melding Jeugdwet case type, whose handler label is "Regisseur", handled by Fatma Kaya of the jeugdteam
- **THEN** De melding's field Regisseur reads "Fatma Kaya, jeugdteam", as board DqSociaalDomein draws it

### Requirement: The report card lays its facts out in three columns

The report card SHALL lay its facts out in three columns, as board DqSociaalDomein draws De melding.

#### Scenario: DqSociaalDomein De melding

- **THEN** Zaaktype, Grondslag and Gemeld door share the first row, Onderzoek klaar, Regisseur and Gezinsplan the second
