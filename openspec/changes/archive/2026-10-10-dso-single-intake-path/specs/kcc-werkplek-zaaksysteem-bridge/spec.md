## MODIFIED Requirements

### Requirement: Quick-actions execute standard KCC handelingen
The system MUST provide server-side quick-actions for status terugkoppelen, nieuwe zaak, klacht registreren and bel-terug, each enforcing case/zaaktype rules.

@e2e exclude backend quick action; covered by QuickActionComplaintFindsAShippedCaseTypeTest and the live run, the agent UI lives in pipelinq

#### Scenario: Klacht registreren creates a klacht case with statutory deadline
- **GIVEN** an unsatisfied caller about an existing case
- **WHEN** `QuickActionService::executeKlachtRegistreren()` runs
- **THEN** a klacht case is created on the shipped case type `klacht-behandeling` (Klacht behandeling, Awb hoofdstuk 9) at its initial status, with a P42D deadline (Awb art. 9:11) and a link to the original case

#### Scenario: The seeded complaint action names a shipped case type
- **GIVEN** a fresh install with the default KCC seed
- **WHEN** the quick action `kcc-qa-klacht-registreren` is read
- **THEN** its `targetCaseType` is `klacht-behandeling`, an identifier the register ships
