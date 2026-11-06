## ADDED Requirements

### Requirement: A case type lists the fields its request card shows

A case type MAY list `requestFields`, the facts entries the request card on a case's Overview shows. When the list is empty the card SHALL show the case description.

#### Scenario: a Woo request
- **GIVEN** the Woo type lists the request's scope, its period and the kinds of document
- **THEN** the request card reads "Alle adviezen en mails …" with the pills "Periode 2025 tot en met 2026" and "Correspondentie, Rapporten"

#### Scenario: a case type without request fields
- **THEN** the request card shows the case description
