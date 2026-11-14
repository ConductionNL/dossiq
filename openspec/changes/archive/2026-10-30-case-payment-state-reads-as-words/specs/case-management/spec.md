## ADDED Requirements

### Requirement: A case's payment state reads as words

Every value of a case's `paymentState` SHALL carry a label with a Dutch translation.

#### Scenario: a case type without fees
- **GIVEN** a case whose payment state is `notRequired`
- **THEN** Termijn en betaling reads "Betaalstatus: Geen leges voor dit zaaktype"
