## ADDED Requirements

### Requirement: The Woo request action declares its four steps (REQ-SWS-001)
The actions `startWooVerzoek` and `startWooVerzoekAlgemeen` MUST declare `steps`: "Uw vraag"
(`onderwerp`, `omschrijving`), "Periode en documenten" (`periodeVan`, `periodeTot`,
`documentSoorten`, `toelichting`), "Uw gegevens" (`verzoekerNaam`, `verzoekerEmail`,
`verzoekerType`) and "Controleren en versturen" as a review of every answer. Every whitelisted
field MUST belong to exactly one step. Each step MUST use the keys `id`, `title`,
`description` and `fields`; the review step MUST carry `review: true` and no fields. The
labels MUST be the ones of `DossiqWoo.dc.html`. A field MUST be declared `required` only when
the server requires it. This builds on REQ-PORTAL-020 and keeps its endpoint, method
and assertion.

#### Scenario: A resident moves through the steps
- **GIVEN** a resident signed in with DigiD on a site that renders declared steps
- **WHEN** they start "Informatie opvragen (Woo-verzoek)"
- **THEN** the form MUST show step 1 of 4, "Uw vraag", and only its two fields
- **AND** step 4 MUST list every answer with a link to change the step it came from

#### Scenario: No form-only required field
- **GIVEN** the declared action
- **WHEN** the provider test reads its `fieldConfigs`
- **THEN** only `onderwerp` MUST be marked required

#### Scenario: Every field has a step
- **GIVEN** the declared action
- **WHEN** the provider test compares `fields` with the fields of all steps
- **THEN** each field MUST appear in exactly one step

### Requirement: A resident starts a Woo request without a dossier (REQ-SWS-002)
The citizen contribution MUST offer `startWooVerzoekAlgemeen`, posting to the same route as
`startWooVerzoek`, with the same steps and fields except `collectionId`, and without
`attachTo`. `startWooVerzoek` MUST keep `attachTo` and `rowField` unchanged.

#### Scenario: From the home page
- **GIVEN** a resident with no dossier
- **WHEN** they send a Woo request through `startWooVerzoekAlgemeen`
- **THEN** dossiq MUST answer 201 and the case MUST show under the resident's cases without case objects

### Requirement: A half-filled Woo request can be saved and resumed (REQ-SWS-003)
Both Woo actions MUST declare `draft` with `retentionDays: 30`. Dossiq MUST NOT receive or store
anything before the resident sends the request.

#### Scenario: Save and come back
- **GIVEN** a resident on step 2 of the Woo request on a site that stores drafts
- **WHEN** they choose "Opslaan en later verdergaan" and return the next day
- **THEN** the form MUST reopen on step 2 with their answers
- **AND** no case MUST exist for the unsent request

### Requirement: The confirmation names the case and the date (REQ-SWS-004)
Both Woo actions MUST declare a `confirmation` with a title, a body using `{identifier}` and
`{deadline}`, and a sentence on where to find the request. A body sentence whose placeholder has
no value MUST be left out.

#### Scenario: The resident reads their case number
- **GIVEN** a request sent and answered with identifier 2026-0003 and deadline 30 October 2026
- **WHEN** the confirmation shows
- **THEN** it MUST read "Uw zaaknummer is 2026-0003. U krijgt uiterlijk 30 oktober 2026 antwoord."
