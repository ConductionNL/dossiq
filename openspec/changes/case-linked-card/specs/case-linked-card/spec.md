# case-linked-card Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: The case page lists its links in one side card

The case page SHALL place a `linked` widget titled "Linked" (nl "Gekoppeld") in its side column, right after Handling,
listing the leaves `filinq-documents`, `shillinq-payment-requests-panel`, `shillinq-contracts-panel` and
`planninq-projects` in that order. It SHALL NOT place `planninq-projects` as a widget of its own.

#### Scenario: a Woo case with no fees, contract or projects

- **GIVEN** a case with no payment requests, contract or projects
- **THEN** the Gekoppeld card reads "Leges en betalingen, contract en projecten: niets gekoppeld"
- **AND** it offers "Nieuw project" and "Openen in Planninq"
