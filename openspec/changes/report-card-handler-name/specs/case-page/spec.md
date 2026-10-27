# case-page Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [report-card-handler-name](../../)

## ADDED Requirements

### Requirement: The report card names the handler

The report card of a case SHALL show the handler by display name, not by user id.

#### Scenario: DqSociaalDomein

- **GIVEN** a youth case handled by Fatma Kaya
- **THEN** De melding reads "Behandelaar: Fatma Kaya"

### Requirement: The report card lays its facts out in three columns

The report card SHALL lay its facts out in three columns, as board DqSociaalDomein draws De melding.

#### Scenario: DqSociaalDomein De melding

- **THEN** Zaaktype, Grondslag and Gemeld door share the first row, Onderzoek klaar, Regisseur and Gezinsplan the second
