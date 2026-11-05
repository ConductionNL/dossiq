# related-case-linking Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-page-answers-in-card-notices](../../)

## ADDED Requirements

### Requirement: A link the RGBZ forbids is refused in a card notice

A case SHALL hang as the subject under one case only, and a subject chain SHALL NOT loop back. A link that breaks
either rule SHALL be refused with 409 and an error notice titled "Niet gekoppeld:" that names the cases.

#### Scenario: Subject already taken

- **GIVEN** ZAAK-B hangs as the subject under ZAAK-C
- **WHEN** a handler links ZAAK-B as the subject of ZAAK-A
- **THEN** the answer is 409 with the text "ZAAK-B hangt al als onderwerp onder ZAAK-C. Een zaak kan maar onder één
  zaak als onderwerp hangen."
- **AND** nothing is written on ZAAK-A

#### Scenario: Loop

- **GIVEN** ZAAK-A hangs under ZAAK-C and ZAAK-C under ZAAK-B
- **WHEN** a handler links ZAAK-B as the subject of ZAAK-A
- **THEN** the link is refused
