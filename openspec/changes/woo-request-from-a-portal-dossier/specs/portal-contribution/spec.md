## ADDED Requirements

### Requirement: A resident starts a Woo request from the portal (REQ-PORTAL-020)
The citizen contribution, served to `citizen` and `client`, MUST offer the
endpoint action `startWooVerzoek` (`type: create`, `POST
/index.php/apps/dossiq/api/portal/woo-verzoek`, fields `collectionId`,
`onderwerp`, `omschrijving`, `periodeVan`, `periodeTot`). The receiving
route MUST verify portaliq's `X-Portal-Subject` assertion before anything else,
take the resident from its `sub` claim and never from the body, and call
`WooRequestIntake::start()` with `origin: portal`. It MUST answer 201 with
`{caseId, caseUrl}`, 401 for a missing or invalid assertion, 400 for an
unusable request and 404 for a dossier that is not the resident's. Implements
hydra `openspec/changes/woo-citizen-journey/specs/woo-citizen-journey/spec.md`, "A Woo request MUST be created by one dossiq path, from the portal and
from pipelinq alike".

#### Scenario: A signed-in resident submits the form
- **GIVEN** a resident signed in with DigiD (audience `client`) on their dossier page
- **WHEN** they submit "Start een Woo-verzoek" with an onderwerp
- **THEN** portaliq forwards the action and dossiq answers 201 with the new case
- **AND** the case MUST show under Mijn zaken with its deadline

#### Scenario: A forged request
- **GIVEN** a request to the route without a valid assertion, or with a subjectRef in the body
- **WHEN** it arrives
- **THEN** dossiq MUST answer 401 for the missing assertion and MUST ignore any subjectRef in the body
