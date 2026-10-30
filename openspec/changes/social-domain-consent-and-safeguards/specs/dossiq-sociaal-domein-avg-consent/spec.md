## ADDED Requirements

### Requirement: Consent is recorded and revoked on the case (REQ-SDC-CONSENT-01)

A case page SHALL list the consents recorded on the case (who gave it, for sharing with which party, for which purpose, from and until when) and SHALL let a handler record a new consent and revoke one. When the case type requires consent for sharing, sharing the case or its data with an external party SHALL be refused unless a valid consent names that party.

#### Scenario: Sharing without consent is refused

- **GIVEN** a Jeugdwet case whose case type requires consent for sharing and no consent naming "Veilig Thuis"
- **WHEN** the handler shares the case with "Veilig Thuis"
- **THEN** the share SHALL be refused with "Er is geen geldige toestemming voor deze partij"
- @e2e tests/e2e/social-domain-consent.spec.ts

#### Scenario: A revoked consent stops the next share

- **GIVEN** a valid consent naming "Wijkteam Noord"
- **WHEN** the handler revokes it and then shares the case with "Wijkteam Noord"
- **THEN** the share SHALL be refused
- @e2e tests/e2e/social-domain-consent.spec.ts

### Requirement: A case type can require a special-category classification and keep cases to its team (REQ-SDC-SAFE-01)

A case type SHALL be able to require a special-category classification at intake and to restrict read access to its handling teams. A case of such a type SHALL NOT be created without the classification, SHALL show it on the case page, and SHALL NOT be readable by staff outside the handling teams, administrators included unless they are in the team.

#### Scenario: Intake asks for the classification

- **GIVEN** the Wmo case type requires a special-category classification
- **WHEN** a handler creates a Wmo case without choosing one
- **THEN** the create form SHALL refuse with the field marked
- @e2e tests/e2e/social-domain-consent.spec.ts

#### Scenario: Another team cannot open the case

- **GIVEN** a Wmo case of a team-only case type handled by "Wijkteam Noord"
- **WHEN** a handler of "Team Vergunningen" opens its link
- **THEN** the case SHALL answer as not found
- @e2e tests/e2e/social-domain-consent.spec.ts

### Requirement: A case list can be exported anonymised (REQ-SDC-ANON-01)

The list export SHALL offer an anonymised variant that removes or generalises every field the case type marks as identifying (names, BSN, addresses to postcode area, birth dates to year) and SHALL record the export in the processing log.

#### Scenario: The anonymised export holds no BSN

- **GIVEN** a filtered list of 20 Participatiewet cases
- **WHEN** the handler exports it anonymised
- **THEN** the file SHALL contain no BSN, no name and birth years only
- @e2e tests/e2e/social-domain-consent.spec.ts
