## ADDED Requirements

### Requirement: A social domain case lists its investigation steps

Melding Jeugdwet SHALL carry a sensitive list-of-records field
`onderzoeksstappen`. The case page SHALL show an "Onderzoek" tab that lists
those steps from the case as the reader received it, and counts them; a reader
who does not receive sensitive answers SHALL see no steps.

#### Scenario: the Oosthaven case
- GIVEN the Oosthaven example case with two investigation steps
- WHEN a dossiq-sensitive reader opens it
- THEN the tab reads "Onderzoek 2" and lists "Gesprek met de ouders..." and "Informatie opgevraagd bij school..."
