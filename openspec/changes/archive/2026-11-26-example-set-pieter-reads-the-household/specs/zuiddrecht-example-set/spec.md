## ADDED Requirements

### Requirement: Pieter works with the social domain's sensitive data

The Zuiddrecht example set SHALL put Pieter Jansen in `dossiq-sensitive`, so the
household card of a social domain case he works on shows its rows.

#### Scenario: the Oosthaven household
- GIVEN the Zuiddrecht set installed
- WHEN Pieter opens the Melding Jeugdwet case of the Oosthaven family
- THEN "Andere zaken van dit huishouden" reads the household's cases rather than a refusal

### Requirement: Pieter is in the handling team of the Jeugdwet case type

The household endpoint asks for `dossiq-sensitive` AND read access to the case. The Oosthaven case falls to Fatma, so the Zuiddrecht example set SHALL also put Pieter (consulent werk en inkomen, social-domain work) in `dossiq-sociaal-domein`, the handling team of `melding-jeugdwet`, which lets him read the case without a per-object grant.

#### Scenario: Pieter reads the household on a case he does not handle

@e2e exclude {set membership, asserted in tests/Unit/Settings/ZuiddrechtPieterReadsTheHouseholdTest.php}

- GIVEN the Zuiddrecht set installed
- WHEN Pieter asks `GET /api/cases/{oosthaven}/household-cases`
- THEN the answer SHALL be 200, not 403 `household-not-allowed`
