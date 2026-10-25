# case-dialogs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-dialogs-edit-form-board](../../)

## Purpose

Zaak bewerken asks the case's fields as DqZaakDialogen draws them.

## ADDED Requirements

### Requirement: Zaak bewerken says what it does not change and asks the handler first

The case edit form SHALL carry the subtitle that status and term change through Next step, SHALL ask the handler right
after the case type, and SHALL label the requester as the applicant (Verzoeker).

#### Scenario: DqZaakDialogen

- **WHEN** the handler opens Bewerken on a case
- **THEN** the form reads "De velden van de zaak. Status en termijn wijzigt u via Volgende stap." under its title
- **AND** asks Zaaktype, Behandelaar, Titel, Omschrijving, Vertrouwelijkheid, Intakekanaal, Startdatum, Geplande
  einddatum, Impact, Urgentie and Verzoeker in that order
