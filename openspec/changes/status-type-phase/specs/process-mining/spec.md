# process-mining Delta

**Status**: proposed
**Scope**: dossiq

## ADDED Requirements

### Requirement: Days per phase groups statuses by their phase

A status type MAY name a `phase` (a translatable string, configuration per case type). The "days per phase" matrix
SHALL put a status that names a phase under that phase, adding the days of the statuses of one phase together, and a
status that names none under its own name. When a case type uses phases, its final statuses SHALL be no column.

#### Scenario: Woo-verzoek

- **GIVEN** Woo-verzoek's statuses Zoeken documenten, Beoordelen documenten and Lakken name the phase Beoordeling
- **THEN** the matrix has one Beoordeling cell for Woo-verzoek holding their days together, and no Afgehandeld column

### Requirement: Carrying out a decision is the phase Uitvoering

A status that follows the decision while the decision is being carried out (a benefit or youth care running, a
reintegration plan in progress, follow-up, a compliance period, collection) SHALL name the phase `Uitvoering`. Final
statuses still name no phase.

#### Scenario: Bijstandsaanvraag

- **GIVEN** Bijstandsaanvraag's statuses Bijstand loopt and Re-integratie loopt name the phase Uitvoering
- **THEN** the matrix has one Uitvoering cell for Bijstandsaanvraag after Besluit, holding their days together
