# case-types Delta

## ADDED Requirements

### Requirement: A field rule can be limited to statuses

A case type's field rule SHALL take an optional list of statuses. A rule with statuses SHALL be enforced only while a
case is in one of them; a rule without statuses SHALL hold in every status.

#### Scenario: Bouwkosten read only for the intake once the case is in behandeling

- **GIVEN** a rule readOnly on bouwkosten for Intaker with statuses In behandeling and Afgehandeld
- **THEN** an Intaker may change bouwkosten while the case is Ontvangen, and not after

### Requirement: The Fields tab shows the field rights per role and status

The Fields tab SHALL show "Wie wat ziet en invult, per rol en status" as a matrix with a column per status and a row
per field a rule names or a status requires; a cell SHALL say Verplicht, the roles that lose the field or may only
read it, or Bewerken when nothing restricts it.

#### Scenario: DqZaaktypeVelden

- **THEN** Bouwkosten reads "Verplicht" under Ontvangen and "Alleen lezen voor Intaker" under In behandeling
