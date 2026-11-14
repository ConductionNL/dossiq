# party-pages Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [bsn-reveal-logged](../../)

## ADDED Requirements

### Requirement: The BSN is shown through a logged server reveal

A contact page SHALL receive a person's BSN masked, SHALL fetch the number from OpenRegister only when the reader
presses Tonen, and SHALL say that showing the BSN is logged. A masked number SHALL never be stored as a case's
initiator number.

#### Scenario: DqContact

- **GIVEN** Pieter (dossiq-sensitive) opens Sanne de Vries
- **THEN** Identiteit reads "BSN *****7609 [Tonen]" and "Het tonen van het BSN wordt gelogd."
- **WHEN** he presses Tonen
- **THEN** the number shows and OpenRegister records one reveal
