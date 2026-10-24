# case-dialogs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-dialogs-raw-data-board](../../)

## Purpose

Ruwe gegevens is a reading surface an administrator can copy from.

## ADDED Requirements

### Requirement: Ruwe gegevens copies the stored case and says who it is for

The Raw data dialog SHALL state once that it shows the case as OpenRegister stores it, SHALL say that it is for
administrators and that opening it is recorded in the processing log, and SHALL offer Cancel and Copy, where Copy puts
the shown JSON on the clipboard and confirms it.

#### Scenario: DqZaakDialogen

- **WHEN** an administrator opens Ruwe gegevens on a case
- **THEN** the subtitle appears once, the line "Alleen voor beheerders. Lezen kost niets, maar het openen komt in het
  verwerkingenlog." is under the JSON, and the buttons read Annuleren and Kopiëren
- **AND** Kopiëren puts the JSON on the clipboard and says "Gekopieerd naar uw klembord."
