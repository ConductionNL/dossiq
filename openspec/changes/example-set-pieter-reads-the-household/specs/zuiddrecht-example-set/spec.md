## ADDED Requirements

### Requirement: Pieter works with the social domain's sensitive data

The Zuiddrecht example set SHALL put Pieter Jansen in `dossiq-sensitive`, so the
household card of a social domain case he works on shows its rows.

#### Scenario: the Oosthaven household
- GIVEN the Zuiddrecht set installed
- WHEN Pieter opens the Melding Jeugdwet case of the Oosthaven family
- THEN "Andere zaken van dit huishouden" reads the household's cases rather than a refusal
