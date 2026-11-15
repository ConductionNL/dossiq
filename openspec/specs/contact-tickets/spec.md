# contact-tickets Specification

## Purpose
How the ticket lists on contact and organisation pages name a ticket status in words, including a ticket converted into a case.

## Requirements

### Requirement: A converted ticket is named in words

A ticket list SHALL name a ticket with status `converted` as "Converted to a case" in the handled (success) tone, never as its raw status code.

#### Scenario: DqContact Tickets
- **GIVEN** Sanne de Vries's ticket PQ-2026-0398 with status `converted`
- **THEN** its pill reads "Omgezet in een zaak" in the green handled tone
