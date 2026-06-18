---
status: done
---
# kcc-werkplek-zaaksysteem-bridge Specification

## Purpose
Bridge the KCC-werkplek (contact-center workplek) to the Procest zaaksysteem so KCC-medewerkers get real-time case context on every inbound contact: automatic case-voorblad, contactmoment capture with audit trail, one-click quick-actions (status terugkoppelen, nieuwe zaak, klacht registreren, warm doorverbinden), datagedreven belplan-routering, and real-time sentiment detection. Burger is a Nextcloud contact entity (opaque pseudonymous reference, no PII schema, per ADR-022). Procest exposes the read/write API; the KCC-werkplek agent UI is rendered by pipelinq.

## Status: partial — residue (cross-app, deferred; not stubbed)
The Procest-side core is BUILT and tested: contactmoment capture + immutable case-activity audit, case-voorblad resolution (IDOR-scoped), identificatievragen scoring + threshold linking, quick-actions (status/nieuwe-zaak/klacht/bel-terug), belplan routing + overflow, sentiment scoring + escalation + persistence, doorverbinding record lifecycle with immutable snapshot + recipient-ownership guard, the admin settings UI, and the default seed. The following legs remain because they live in other apps and are not present in this repo:
- **DigiD authentication** (auth-code → validated BSN assertion) → OpenConnector (ZGW/mTLS). Identificatievragen + scoring + linking are built; `resolveFromDigiD()` consumes an already-resolved BSN.
- **SIP/SIPS warm phone transfer + screen-pop** → pipelinq telephony. The doorverbinding record + immutable context snapshot + accept/reject are built; the live SIP leg is not.
- **Live specialist-availability feed** → pipelinq ACD / HR system. The refresh job + stale-record aging + resilience are built; the upstream push is not.
- **KCC-werkplek agent UI** (screen-pop, quick-action dialogs, sentiment banner, specialist picker, klantreis timeline) → pipelinq, by design.
- **IdentificationReviewJob (T17)** — moot: there is no stored `[UNVERIFIED]` burger record under the no-burger-schema design.

## Requirements
### Requirement: Case-voorblad resolves for a burger on inbound contact
On an inbound contact, the system MUST resolve the burger and return a case-voorblad (open zaken, recente contactmomenten, suggested topic) within the configured limits, scoped to the burger's own cases (no IDOR).

#### Scenario: Known burger returns a case-voorblad
- **GIVEN** an authenticated KCC-medewerker
- **WHEN** a contactmoment is created with a resolvable beller-identificatie
- **THEN** `CaseVoorbladService::getCaseVoorblad(burgerId)` returns the burger's open zaken (capped at `max_zaken_voorblad`), recente contactmomenten (capped at `max_contactmomenten_history`), and a suggested topic

#### Scenario: Missing burgerId is rejected, not silently listed
- **GIVEN** an authenticated KCC-medewerker
- **WHEN** `GET /api/contactmomenten` is called without a `burgerId`
- **THEN** the endpoint returns 400 (server-validated; no cross-burger leakage)

### Requirement: Contactmoment capture records an immutable case activity
Every contactmoment and quick-action MUST append a timestamped, immutable entry to the related case `activity` array for audit.

#### Scenario: Status terugkoppelen records an activity on confirm
- **GIVEN** a case with a known status
- **WHEN** `statusGeven` is invoked with `confirm=true`
- **THEN** a `status_given` activity entry (timestamp, medewerker, text) is appended to the case via `ContactMomentService::recordActivity()`

### Requirement: Identificatievragen score gates burger linking
The identificatievragen flow MUST compute a weighted match score and only link the burger (revealing full zaaksinfo) when the score meets the configured threshold; otherwise only openbare zaaksinfo is shown.

#### Scenario: Score below threshold does not link the burger
- **GIVEN** `identification_score_threshold` of 0.8
- **WHEN** `BurgerIdentificationService::startIdentificatievragen()` scores 0.6
- **THEN** no burger reference is linked and the contact is treated as niet-geidentificeerd

<!-- @e2e exclude DigiD OAuth2 handshake is delivered by OpenConnector (ZGW/mTLS); not exercisable from the Procest UI -->
#### Scenario: DigiD assertion resolves to a pseudonymous reference
- **GIVEN** a DigiD-validated BSN (handshake performed by OpenConnector)
- **WHEN** `BurgerIdentificationService::resolveFromDigiD(bsn)` is called
- **THEN** an opaque `burger:<hash>` reference is returned (no PII stored at-rest)

### Requirement: Quick-actions execute standard KCC handelingen
The system MUST provide server-side quick-actions for status terugkoppelen, nieuwe zaak, klacht registreren and bel-terug, each enforcing case/zaaktype rules.

#### Scenario: Klacht registreren creates a klacht case with statutory deadline
- **GIVEN** an unsatisfied caller about an existing case
- **WHEN** `QuickActionService::executeKlachtRegistreren()` runs
- **THEN** a klacht case is created with the klacht zaaktype, a P42D deadline (Awb art. 9), and a link to the original case

### Requirement: Datagedreven belplan-routering
Belplannen MUST route a call by zaaktype/vaardigheid to the available specialist with the shortest queue, escalating to a generalist when overflow thresholds are exceeded.

#### Scenario: Routes to the lowest-queue available specialist
- **GIVEN** a belplan with a vaardigheid match and several specialists
- **WHEN** `BelplanRoutingService::routeCall()` runs
- **THEN** the available specialist with the lowest wachtrij is returned; if all are busy beyond the overflow threshold, a generalist is returned with an escalatie-flag

### Requirement: Realtime sentiment-detectie en escalatie-aanbeveling
The system MUST score contact text against a configurable Dutch trigger-word list and recommend escalation on negative sentiment or serious triggers.

#### Scenario: Serious trigger recommends escalation
- **GIVEN** contact text containing a serious trigger (e.g. "advocaat")
- **WHEN** `SentimentService::analyzeSentiment()` runs
- **THEN** `escalatieAanbevolen` is true and `escalatieLevel` is raised, persisted to a `klantSentiment` object and appended as a case activity by the SentimentAnalysisJob

### Requirement: Warm doorverbinding preserves an immutable context snapshot
A warm doorverbinding MUST capture an immutable context snapshot (bellergegevens, zaken, sentiment, history) and only allow the intended recipient to accept/reject (no IDOR).

#### Scenario: Recipient ownership is enforced on accept
- **GIVEN** a pending doorverbinding addressed to a specialist
- **WHEN** a different user calls accept
- **THEN** the accept is rejected (recipient-ownership guard), preserving the snapshot

<!-- @e2e exclude SIP/SIPS warm phone transfer + screen-pop are delivered by pipelinq telephony; the Procest side only records the doorverbinding + snapshot -->
#### Scenario: Warm transfer records the Procest-side intent
- **GIVEN** a doorverbinding quick-action
- **WHEN** `DoorverbindingService::initiateWarmTransfer()` runs
- **THEN** a doorverbinding record with an immutable context snapshot is created with status pending (the live SIP leg is performed by pipelinq)

### Requirement: KCC integration is configurable from admin settings
An admin MUST be able to configure identification method/threshold, voorblad limits, sentiment trigger words and belplan overflow thresholds from the Procest admin settings.

#### Scenario: KCC integration settings render and persist
- **GIVEN** an admin on `/settings/admin/procest`
- **WHEN** the KCC-werkplek Integration section is opened
- **THEN** the identification-threshold, sentiment trigger-word, voorblad-limit and belplan-overflow controls render and save through `/api/settings`

### Requirement: Default KCC quick-actions and belplannen are seeded
On install/upgrade the system MUST idempotently seed the default quick-actions and example belplannen.

#### Scenario: Seed is idempotent
- **GIVEN** the KCC schemas are configured
- **WHEN** the seed runs twice
- **THEN** the second run creates no duplicates (all rows skipped by id)

<!-- @e2e exclude Live specialist-availability feed comes from the pipelinq ACD / HR system; the refresh job only ages out stale cache -->

### Requirement: Specialist beschikbaarheid cache stays fresh
The system MUST age out stale `specialistBeschikbaarheid` records so routing never sends a call to a silent specialist.

#### Scenario: Stale records are aged out
- **GIVEN** a specialistBeschikbaarheid record older than the staleness window
- **WHEN** `SpecialistBeschikbaarheidRefreshJob` runs
- **THEN** the stale record is marked so routing skips it (the authoritative push from pipelinq is cross-app)

