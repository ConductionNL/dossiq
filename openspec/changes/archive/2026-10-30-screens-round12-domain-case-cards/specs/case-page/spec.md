## ADDED Requirements

### Requirement: The case page shows the cards of its case type's domain (REQ-DCC-1)

`CaseDetail` in the simple profile SHALL show the Hearing, Advice of the
committee and Appeal cards only for a case whose case type category is
"Bezwaar en beroep", the Grant application card only for "Subsidies", the
Consent card only for the social domain or a case naming a family plan, and
the Bids card only for "Inkoop en leveranciers". Each card SHALL list only
records that name this case.

#### Scenario: A permit case shows no objection cards
@e2e exclude measured against boards DqBezwaar and DqZaak by the round 12 pixel check, not an e2e flow

- **GIVEN** a case whose case type category is Vergunningen
- **WHEN** you open the case
- **THEN** the side column SHALL show none of the six domain cards
