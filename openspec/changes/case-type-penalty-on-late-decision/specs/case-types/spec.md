# case-types Delta

## ADDED Requirements

### Requirement: A case type says whether a late decision costs a penalty

A case type SHALL be able to say whether a late decision costs the applicant a penalty, and the most it can come to.
The terms endpoint SHALL answer it as a sentence that names the notice of default the penalty waits on, and the
Termijnen card SHALL show it.

#### Scenario: DqZaaktype

- **GIVEN** a case type with `penaltyOnLateDecision: true`
- **THEN** its Termijnen card reads "Dwangsom bij niet tijdig beslissen: Ja, na een ingebrekestelling"
