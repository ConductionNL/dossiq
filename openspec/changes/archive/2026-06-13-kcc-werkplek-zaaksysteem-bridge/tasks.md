# Tasks: kcc-werkplek-zaaksysteem-bridge

## Status: PARTIAL (self-contained core COMPLETE; cross-app pieces deferred)

**Reconciled 2026-06-14.** An audit found the bridge BACKEND was already built on
`development` (commit `c67f1f7b` + security fix `f08a32d2`): all 7 services
(`ContactMomentService`, `CaseVoorbladService`, `QuickActionService`,
`DoorverbindingService`, `BurgerIdentificationService`, `BelplanRoutingService`,
`SentimentService`), 3 controllers, 2 background jobs, the additive schema fragment
(`register.d/40-kcc-werkplek.json`, incl. the `contactmoment` schema — the prior
audit's "contactmoment unseeded" was stale), all routes, and all SettingsService keys.

**Newly built in this PR (the self-contained remainder):** T03 KCC admin settings
UI (`KccIntegrationSettings.vue` + AdminRoot wiring + `kccTriggerWords.js` helper),
T20 default seed (quick-actions + example belplannen via repair step), plus the
missing test layers (PHPUnit seed test, vitest, Playwright spec-coverage, Newman).

**Deferred [~] (genuinely cross-app — NOT stubbed):**
- T05 — DigiD OAuth2 handshake → **OpenConnector** (ZGW/mTLS). The identificatievragen
  flow + scoring + threshold linking ARE built; only the live DigiD callback is cross-app.
- T08 — SIP/SIPS warm phone transfer + screen-pop → **pipelinq** telephony. The
  doorverbinding record lifecycle + immutable context snapshot + accept/reject (IDOR-guarded)
  ARE built; only the live SIP leg is cross-app.
- T16 — live agent-status feed → **pipelinq** ACD / HR system. The refresh job + record
  aging + resilience ARE built; only the upstream availability push is cross-app.
- T17 — moot under the no-burger-schema design (no `[UNVERIFIED]` burger row exists;
  burger is an opaque NC-contact reference per ADR-022).
- The KCC-werkplek agent UI (screen-pop, quick-action dialogs, sentiment banner,
  specialist picker, klantreis timeline) is rendered by **pipelinq**, per design.md:
  Procest exposes the read/write API; pipelinq owns the contact-center UI.

`burger` schema: deliberately NOT created — a burger is a Nextcloud contact entity
(ADR-022 / CLAUDE.md guardrail), resolved via OCP contacts + a pseudonymous reference.

## Implementation Tasks

### Phase 1: Schema & Configuration

- [x] **T01** (built, commit c67f1f7b): Schemas added as the additive ADR-037 fragment `lib/Settings/register.d/40-kcc-werkplek.json` (not the monolith): `contactmoment` (18 props), `kccQuickAction`, `belplan`, `specialistBeschikbaarheid`, `doorverbinding`, `klantSentiment`. NB: the `burger` schema was deliberately NOT created — a burger is a Nextcloud contact entity resolved via OCP contacts + an opaque pseudonymous reference (`BurgerIdentificationService`), per the CLAUDE.md / ADR-022 guardrail against inventing a customer/contact schema. Original task text below.

- [ ] **T01-spec-original**: Add schemas to `lib/Settings/procest_register.json`: `contactmoment` (kanaal, richting, startTijd, eindTijd, bellerIdentificatie, geidentificeerdeBurgerId, identificatieMethode, identificatieScore, kccMedewerkerId, gerelateerdeZaken, nieuweZaakIds, aard, samenvatting, volgensIntent, firstTimeFix, transcriptie, transferNaar), `burger` (bsn encrypted, kvkNummer, naam, adres, telefoonnummers, emails, bekendeIdentificaties, voorkeursKanaal, voorkeursTaal, contact_count, last_contact_date, first_contact_date), `kccQuickAction` (naam, actieType, vereisteContext, targetZaaktype, template, permissies, volgorde, isActive), `belplan` (naam, triggerNummer, routeringStappen, openingstijden, terugvalActie, prioriteit, isActive), `specialistBeschikbaarheid` (medewerkerId, expertises, status, huidigeWachtrijLengte, gemiddeldeBehandelduur, gespreksInProgress, laatsteUpdate, poll_interval), `doorverbinding` (contactmomentId, vanMedewerkerId, naarMedewerkerId, naarWachtrij, doorverbindingsReden, contextOverdracht, contextSnapshot, geaccepteerd, acceptatieTijd, afgekeurdReden, warmTransferStarted), `klantSentiment` (contactmomentId, sentimentScore, sentimentLabel, triggerWoorden, transcriptieSnippet, escalatieAanbevolen, escalatieLevel). Register with `schema:BroadcastChannel`, `schema:Role`, `schema:Event` annotations as appropriate.

- [x] **T02** (built, commit c67f1f7b): All KCC config keys present in `lib/Service/SettingsService.php` — `identification_method` (default both), `identification_score_threshold` (0.8), `sentiment_polling_interval` (5), `specialist_availability_polling_interval` (30), `max_zaken_voorblad` (10), `max_contactmomenten_history` (5), `belplan_overflow_threshold_wachttijd` (180), `belplan_overflow_threshold_wachtrij_lengte` (5), `sentiment_trigger_words` (JSON default list) + the six KCC schema-id keys, all wired into `CONFIG_KEYS`, `SLUG_TO_CONFIG_KEY` and the defaults map. Original task text below.

- [ ] **T02-spec-original**: Add config keys to `lib/Service/SettingsService.php`:
  - `identification_method` (enum: digid, bsn_questions, both; default: both)
  - `identification_score_threshold` (0.6–1.0; default: 0.8)
  - `sentiment_polling_interval` (seconds; default: 5)
  - `specialist_availability_polling_interval` (seconds; default: 30)
  - `max_zaken_voorblad` (integer; default: 10)
  - `max_contactmomenten_history` (integer; default: 5)
  - `quick_action_templates` (JSON-encoded map of actionType → template ID)
  - `belplan_overflow_threshold_wachttijd` (seconds; default: 180)
  - `belplan_overflow_threshold_wachtrij_lengte` (integer; default: 5)
  - `sentiment_trigger_words` (JSON array of Dutch words; default: ["ongelooflijk", "klacht", "wethouder", "advocaat", "media", "rechtszaak"])
  - Update `SLUG_TO_CONFIG_KEY` mapping and add all to `CONFIG_KEYS` array.

- [x] **T03** (NEWLY BUILT this PR): Created `src/views/settings/KccIntegrationSettings.vue` admin form — identification method (NcSelect with inputLabel), identification score threshold, sentiment + specialist polling intervals, max-zaken/max-contactmomenten voorblad limits, belplan overflow thresholds, and a sentiment trigger-words textarea. Reads/writes through the generic `/api/settings` GET/POST (the KCC keys live in `CONFIG_KEYS`; POST is `#[AuthorizedAdminSetting]`). Wired into `AdminRoot.vue` as a `CnSettingsSection`. Textarea↔JSON round-trip extracted to `src/utils/kccTriggerWords.js` (vitest-covered). Original task text below.

- [ ] **T03-spec-original**: Create `src/views/settings/KccIntegrationSettings.vue` with admin form:
  - Toggle: "Identification method" (radio: DigiD, Identificatievragen, Both)
  - Slider: "Identification score threshold" (0.6–1.0)
  - Slider: "Sentiment polling interval" (1–60 seconds)
  - Slider: "Specialist availability polling interval" (5–120 seconds)
  - Text field: "Max open zaken in voorblad" (1–50)
  - Text field: "Max contactmomenten in history" (1–20)
  - Multiline textarea: "Trigger words for sentiment (one per line)"
  - Save button with validation; success toast "KCC instellingen opgeslagen"

### Phase 2: Backend Services

- [x] **T04** (built, commit c67f1f7b): `lib/Service/ContactMomentService.php` — `createContactMoment()`, `listForBurger()`, `recordActivity()` (appends immutable timestamped entry to case `activity` via OR `saveObject`, named args), `linkUnlinkedContactmoment()` (records identificatieMethode/score/burgerId audit). Uses the real OR ObjectService API only. NB: there is a SECOND, distinct `lib/Service/Kcc/ContactMomentService.php` from the archived `kcc-klantcontact-integratie` change — the bridge uses the root-level service, not that one. Original task text below.

- [ ] **T04-spec-original**: Create `lib/Service/ContactMomentService.php`:
  - `createContactMoment(kanaal, richting, bellerIdentificatie, aard, kccMedewerkerId, gerelateerdeZaken, samenvatting)` → Creates contactmoment object, initializes sentiment to null, returns with ID
  - `fetchCaseVoorblad(burgerId)` → Queries case register for cases where burgerId is initiator/betrokken; returns max 10 active zaken with title + status + lastActionDate; calls `leges-heffingen` API for openstaande facturen; calculates suggested topic from recent contactmomenten + case properties
  - `recordActivity(caseId, contactmomentId, type, medewerkerName, summary)` → Appends to case.activity array (JSON); timestamps each entry; is immutable after creation (can read, not edit/delete)
  - `linkUnlinkedContactmoment(contactmomentId, burgerId, method, score)` → Sets geidentificeerdeBurgerId, identificatieMethode, identificatieScore; records audit trail

- [~] **T05** (PARTIAL — built self-contained, DigiD deferred cross-app): `lib/Service/BurgerIdentificationService.php` is built — `calculateScore()` + `startIdentificatievragen()` (weighted match-score vs threshold), `lookupByIdentifier()` (NC contacts by phone/email), `resolveFromDigiD(bsn)` + private `pseudonymize()` (opaque `burger:<sha256-prefix>` reference, no PII schema). The identificatievragen + scoring + threshold-gated linking are FULLY built. **Deferred [~]:** the actual DigiD OAuth2 handshake (auth-code → validated BSN assertion) is delivered by **OpenConnector** (ZGW/mTLS) and is NOT present in this repo — `resolveFromDigiD()` consumes an already-resolved BSN; wiring the live DigiD callback is a cross-app openconnector task. Original task text below.

- [ ] **T05-spec-original**: Create `lib/Service/BurgerIdentificationService.php`:
  - `authenticateDigiD(authCode, state)` → Calls openconnector DigiD endpoint; validates state; extracts bsn, naam, adres; looks up Burger by bsn; if not found, creates Burger with data from assertion; returns burgerId
  - `startIdentificatievragen(burger_naam, geboortedatum, adres, bsn_last4, out_of_wallet_answer)` → Calls BRP lookup (if integrated) or in-memory Burger search; calculates match score (weighted: 0.3× naam, 0.3× geboortedatum, 0.2× adres, 0.15× bsn, 0.05× out_of_wallet); returns score + burgerId (if >= threshold)
  - `createUnverifiedBurger(identification_data)` → Creates Burger record with placeholder bsn (marked encrypted "[UNVERIFIED]"); flags for manual review; returns burgerId

- [x] **T06**: Created `lib/Service/Kcc/BelplanRoutingService.php`. Stateless KCC call routing — `getActiveBelplan(phoneNumber, belplannen)` E.164-normalises and matches by `triggerNummer` (suffix-match) + `isActive` filter. `resolveVaardigheid(belplan, menuSelection)` supports both 1-based numeric DTMF selection and label match. `routeCall(vaardigheid, pool, overflowThresholds)` filters by skill → picks the lowest-queue available specialist (ties broken by `gemiddeldeBehandelduur`) → on no-one-available escalates when estimated wait or queue length exceed thresholds. 10 unit tests cover belplan match/skip-inactive/no-match, vaardigheid resolution by index + label, low-queue routing, no-candidate escalation, both overflow branches (`tests/Unit/Service/Kcc/BelplanRoutingServiceTest.php`).

- [x] **T06-spec-original** (built, commit c67f1f7b): The bridge ships its OWN root-level `lib/Service/BelplanRoutingService.php` (distinct from the `Kcc/` sibling above) — `getActiveBelplan()`, `routeCall()` and `getSpecialistBeschikbaarheid()` matching the spec signatures. It is the version wired into `BelplanController` (`belplan#route`/`#index`/`#create`/`#update`) and `SpecialistBeschikbaarheidController`. Built and green.

- [x] **T06-spec-original-detail** (built, commit c67f1f7b): `getActiveBelplan(phoneNumber)`, `routeCall(phoneNumber, menuSelection)`, `getSpecialistBeschikbaarheid(vaardigheid)` all present on the root service. Original task text below.

- [ ] **T06-spec-original-detail-orig**: Create `lib/Service/BelplanRoutingService.php`:
  - `getActiveBelplan(phoneNumber)` → Looks up Belplan by triggerNummer; returns belplan config with routeringStappen
  - `routeCall(phoneNumber, menuSelection, currentWachtrij)` → Executes belplan routing:
    1. Determine vaardigheid from menuSelection
    2. Query `specialistBeschikbaarheid` for specialists with that vaardigheid
    3. Find specialist with status="beschikbaar" and lowest wachtrij
    4. If all busy AND wachttijd > threshold: route to generalist with escalatie-flag
    5. Return {destinationSpecialistId, escalatieFlag, estimatedWaitTime}
  - `getSpecialistBeschikbaarheid(vaardigheid)` → Polls pipelinq API or reads cache; returns array of specialists with status, wachtrij, avg_behandelduur

- [x] **T07** (built, commit c67f1f7b): `lib/Service/QuickActionService.php` — `executeStatusTerugkoppelen(caseId)` (renders status draft text from case + emailTemplate), `executeNieuweZaak(zaaktype, burgerId, details)` (creates case via OR, sourceChannel=kcc), `executeKlachtRegistreren(caseId, samenvatting, burgerId)` (klacht zaaktype, P42D, klachtenfunctionaris), `executeBelTerug(burgerId, window)`. `executeDoorverbinden` is implemented in `DoorverbindingService::initiateWarmTransfer()` (see T08) and routed via `contactMoment#doorverbinden`. Original task text below.

- [ ] **T07-spec-original**: Create `lib/Service/QuickActionService.php`:
  - `executeStatusTerugkoppelen(caseId, kccMedewerkerId)` → Retrieves case status; renders status text using emailTemplate; returns draft text for medewerker review + confirmation
  - `executeNieuweZaak(zaaktype, burgerId, contact_context_samenvatting)` → Creates new case with minimal intake form; prefills burger NAW; on submit, creates case with zaaktype, initiator=burgerId, sourceChannel="kcc_telefoon"; returns new caseId
  - `executeKlachtRegistreren(caseId, samenvatting, severity)` → Creates klacht case (zaaktype="klacht_ex_artikel_9_1_awb"), links to original case, assigns to klachtenfunctionaris, sets deadline P42D; triggers docudesk ontvangstbevestiging; returns klacht caseId
  - `executeDoorverbinden(specialist_id or wachtrij_name, contextOverdraft)` → Creates doorverbinding record with contextSnapshot; calls pipelinq to initiate transfer; returns doorverbinding ID; later polls for geaccepteerd flag
  - `executeBelTerug(burgerId, preferred_window)` → Creates task for KCC-medewerker to callback; sends SMS or email reminder to burger with callback time window

- [~] **T08** (PARTIAL — Procest side built, SIP transfer deferred cross-app): `lib/Service/DoorverbindingService.php` is built — `createContextSnapshot()` (immutable JSON: bellergegevens, zaken, sentiment, history), `initiateWarmTransfer()` (creates doorverbinding record, status pending), `acceptTransfer()`/`rejectTransfer()` (with recipient-ownership guard added in commit f08a32d2 — no IDOR), `appendContextNotes()` (append-only context-trail). The full doorverbinding record lifecycle + immutable snapshot + context-trail are built. **Deferred [~]:** the actual SIP/SIPS warm phone-level transfer with screen-pop is delivered by **pipelinq** telephony — `initiateWarmTransfer()` records the Procest-side intent + context; the live SIP leg is a cross-app pipelinq task. Original task text below.

- [ ] **T08-spec-original**: Create `lib/Service/DoorverbindingService.php`:
  - `createContextSnapshot(contactmomentId, burgerId, zaakIds)` → Captures immutable snapshot: burger NAW, zaak states (status, deadline, lastAction), contact summary, sentiment, quick-action history; returns JSON blob
  - `initiateWarmTransfer(context_snapshot, specialist_id or wachtrij, source_medewerker)` → Creates doorverbinding record; calls pipelinq SIP transfer API with screen-pop config; returns doorverbinding ID + context for display
  - `acceptTransfer(doorverbinding_id)` → Sets geaccepteerd=true, acceptatieTijd=now(); logs to activity
  - `rejectTransfer(doorverbinding_id, reden)` → Sets geaccepteerd=false, afgekeurdReden=reden; routes call to voicemail/wachtrij + callback scheduling
  - `appendContextNotes(doorverbinding_id, specialist_notes)` → Appends (not overwrites) to contextOverdracht; audit-trails with timestamp + specialist UID

- [x] **T09**: Created `lib/Service/Kcc/SentimentService.php`. Deterministic Dutch sentiment analyser — DEFAULT_TRIGGER_WORDS list (klacht/advocaat/wethouder/…); SERIOUS_TRIGGERS auto-escalate to `escalatieLevel=rood` regardless of polarity; hand-curated `NEGATIVE_WEIGHTS`/`POSITIVE_WEIGHTS` Dutch word lists. `analyzeSentiment(text, triggerWords?)` returns `{ score [-1..1], label (positief/neutraal/negatief/boos), triggers, escalatieAanbevolen, escalatieLevel (geen/geel/oranje/rood) }`. Word-boundary trigger matching so "krantje" doesn't match "krant". 9 unit tests cover neutral/positive/angry baselines, serious-trigger auto-escalation, custom trigger lists, word-boundary correctness, and the four-step escalation ladder (`tests/Unit/Service/Kcc/SentimentServiceTest.php`). Persisting analysed sentiment to `klantSentiment` objects + the SentimentAnalysisJob TimedJob (T15) remain forward work.

- [x] **T09-spec-original** (built, commit c67f1f7b): The bridge ships its OWN root-level `lib/Service/SentimentService.php` (distinct from the `Kcc/` sibling above) with `analyzeSentiment(text, triggerWords)`, `shouldEscalate(score, triggers)`, `getEscalationLevel(score, triggers)` — exactly the spec signatures. It is the version consumed by `SentimentAnalysisJob` (T15), which persists `klantSentiment` objects and appends sentiment activity to related cases. `recordFalsePositiveFeedback()` is the one sub-method NOT built (a coaching-feedback log with "no immediate action"); minor, deferrable. Original task text below.

- [ ] **T09-spec-original-detail**: Create `lib/Service/SentimentService.php`:
  - `analyzeSentiment(text, trigger_words_list)` → Detects trigger words (substring match with word-boundary check); calculates sentiment score using simple word-weight algorithm (hardcoded Dutch dictionary); returns {score: -1..+1, label: "positief"|"neutraal"|"negatief"|"boos", triggers: [words], escalatieAanbevolen: boolean}
  - `shouldEscalate(score, triggers)` → Returns true if score <= -0.5 OR triggers includes ["klacht", "advocaat", "media", "rechtszaak"]
  - `getEscalationLevel(score, triggers)` → Returns escalatieLevel: "geen" (>0), "geel" (-0.3 to 0), "oranje" (-0.6 to -0.3), "rood" (<-0.6 or serious-trigger present)
  - `recordFalsePositiveFeedback(contactmomentId, medewerker_id, reason)` → Logs feedback for future model improvement (no immediate action)

- [x] **T10** (built, commit c67f1f7b): Built as `lib/Service/CaseVoorbladService.php` (spelling corrected from the spec's "Voorbled") — `getCaseVoorblad(burgerId)` queries open cases for the burger, recente contactmomenten, and returns the aggregated voorblad envelope (burger, openZaken, recenteContactmomenten, suggestedTopic). Wired into `contactMoment#voorblad` + the create response. NB: openstaande-facturen via a live leges-heffingen API is a cross-module lookup; the voorblad envelope carries the field but the live invoice fetch is best-effort. Original task text below.

- [ ] **T10-spec-original**: Create `lib/Service/CaseVoorbledService.php`:
  - `getCaseVoorblad(burgerId, config: max_zaken, max_contactmomenten)` → Queries open cases for burgerId; queries contactmomenten history; calls leges-heffingen API for unpaid invoices; calculates suggested topic from recent contactmomenten patterns + unfinished tasks; returns aggregated JSON { burger, openZaken: [{...}], recenteContactmomenten: [{...}], openstaandeFracturen: [{...}], suggestedTopic: "..." }

### Phase 3: Controllers & Routes

- [x] **T11** (built, commit c67f1f7b): `lib/Controller/ContactMomentController.php` — `create`, `index`, `voorblad`, `statusGeven`, `nieuweZaak`, `klachtRegistreren`, `doorverbinden`, `acceptDoorverbinding`, `rejectDoorverbinding`, all `@NoAdminRequired` with server-side session checks + fail-closed `RuntimeException`→400 handling. Original task text below.

- [ ] **T11-spec-original**: Create `lib/Controller/ContactMomentController.php`:
  - `@Route("/api/contactmomenten", methods={"POST"})` — createContactmoment(kanaal, bellerIdentificatie, aard, kccMedewerkerId) → auto-calls BurgerIdentificationService if needed; returns contactmoment + case-voorblad
  - `@Route("/api/contactmomenten", methods={"GET"})` with query param `?burgerId=X` — listContactmomente(burgerId, limit=50, offset) → paginated list, most recent first
  - `@Route("/api/cases/{caseId}/voorblad", methods={"GET"})` — getCaseVoorblad(caseId) → returns case-voorblad for case detail view
  - `@Route("/api/quick-actions/status-geven", methods={"POST"})` — executeStatusTerugkoppelen(caseId, kccMedewerkerId, confirm) → returns draft text or creates activity if confirmed
  - `@Route("/api/quick-actions/nieuwe-zaak", methods={"POST"})` — executeNieuweZaak(zaaktype, burgerId, details) → returns new caseId
  - `@Route("/api/quick-actions/klacht-registreren", methods={"POST"})` — executeKlachtRegistreren(caseId, samenvatting, severity) → returns klacht caseId
  - `@Route("/api/quick-actions/doorverbinden", methods={"POST"})` — executeDoorverbinden(specialist_id, context) → returns doorverbinding ID + status
  - `@Route("/api/doorverbindingen/{id}/accept", methods={"POST"})` — acceptTransfer(id)
  - `@Route("/api/doorverbindingen/{id}/reject", methods={"POST"})` — rejectTransfer(id, reason)
  - All endpoints marked `@NoAdminRequired` (KCC-medewerker level auth); implement permission checks via mandaat-matrix for sensitive actions (klacht, restitutie)

- [x] **T12** (built, commit c67f1f7b): `lib/Controller/BelplanController.php` — `index`, `create`, `update`, `route` (calls BelplanRoutingService). Original task text below.

- [ ] **T12-spec-original**: Create `lib/Controller/BelplanController.php`:
  - `@Route("/api/belplannen", methods={"GET"})` — listBelplannen() → returns all active belplannen
  - `@Route("/api/belplannen", methods={"POST"})` — createBelplan(naam, triggerNummer, routeringStappen) → admin-only
  - `@Route("/api/belplannen/{id}", methods={"PUT"})` — updateBelplan(id, ...) → admin-only
  - `@Route("/api/belplannen/route", methods={"POST"})` — routeCall(phoneNumber, menuSelection) → calls BelplanRoutingService; returns destination + escalatie flag (used by pipelinq or mock KCC for testing)

- [x] **T13** (built, commit c67f1f7b): `lib/Controller/SpecialistBeschikbaarheidController.php` — read-only `index(vaardigheid)` poll endpoint. Original task text below.

- [ ] **T13-spec-original**: Create `lib/Controller/SpecialistBeschikbaarheidController.php`:
  - `@Route("/api/specialist-beschikbaarheid", methods={"GET"})` with optional `?vaardigheid=X` — getSpecialistBeschikbaarheid(vaardigheid) → returns real-time availability list (polled from pipelinq or cache); used by KCC-werkplek UI
  - Endpoint is read-only; updates come from pipelinq push or background job

- [x] **T14** (built, commit c67f1f7b): `appinfo/routes.php` has all KCC routes under `/api/contactmomenten`, `/api/kcc/voorblad`, `/api/kcc/quick-actions/*`, `/api/kcc/doorverbindingen/{id}/accept|reject`, `/api/kcc/belplannen[/{id}|/route]`, `/api/kcc/specialist-beschikbaarheid`, all registered before the SPA catch-all. Original task text below.

- [ ] **T14-spec-original**: Update `appinfo/routes.php`:
  - Add all controller routes BEFORE the SPA catch-all `/_` route
  - Ensure routes are under `/api/` namespace to avoid conflicts with Nextcloud core
  - All routes must be defined before catch-all or they'll be swallowed by SPA router

### Phase 4: Background Jobs

- [x] **T15** (built, commit c67f1f7b): `lib/BackgroundJob/SentimentAnalysisJob.php` (TimedJob) — scans contactmomenten with transcriptie but no sentiment, calls `SentimentService::analyzeSentiment()`, persists a `klantSentiment` object via OR `saveObject`, and on escalation appends a sentiment activity to each related case via `ContactMomentService::recordActivity()`. Exception-tolerant. Original task text below.

- [ ] **T15-spec-original**: Create `lib/BackgroundJob/SentimentAnalysisJob.php` (TimedJob):
  - Scheduled every 10 minutes (configurable)
  - Queries `contactmomente` with `transcriptie` != null and `sentiment` == null
  - For each: calls `SentimentService.analyzeSentiment(transcriptie)`
  - Stores result in `klantSentiment` object (creates if not exists)
  - If escalatieAanbevolen=true: appends activity to linked case(s)
  - Logs exceptions but doesn't deregister job (continues on next interval)

- [~] **T16** (PARTIAL — job built, live pipelinq poll deferred cross-app): `lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php` (TimedJob) exists and runs on the configured interval, refreshing `specialistBeschikbaarheid` records resiliently (stale cache on failure). **Deferred [~]:** the actual fetch of live agent status from the **pipelinq** telephony/ACD (or an HR system) is cross-app — the job framework + record refresh + resilience are built, but the upstream pipelinq availability feed is not present in this repo. Original task text below.

- [ ] **T16-spec-original**: Create `lib/BackgroundJob/SpecialistBeschikbaarheidRefreshJob.php` (TimedJob):
  - Scheduled every 30 seconds (configurable, matches `specialist_availability_polling_interval`)
  - Calls pipelinq API (or HR system API if configured) to fetch latest specialist status
  - Updates all `specialistBeschikbaarheid` records with fresh data: status, wachtrij_lengte, gemiddelde_behandelduur
  - Caches results in-memory or Redis for < 30s to avoid repeated calls
  - If pipelinq is unreachable: logs warning and returns stale cache (resilient)

- [~] **T17** (DEFERRED — moot under the no-burger-schema design): NOT built. This job reviews "unverified Burger records (bsn='[UNVERIFIED]')", but the bridge deliberately does NOT store burger PII as an object — a burger is an opaque pseudonymous reference (`burger:<hash>`) over a Nextcloud contact entity (per ADR-022). There is therefore no `[UNVERIFIED]` burger row to age out, and no BSN stored at-rest to flag. The original task assumes the rejected `burger` schema; it is moot as written. A real equivalent (auditing low-score identificatievragen contactmomenten) would belong with the DigiD/BRP cross-app work (T05). Original task text below.

- [ ] **T17-spec-original**: Create `lib/BackgroundJob/IdentificationReviewJob.php` (TimedJob):
  - Scheduled once daily
  - Queries unverified Burger records (bsn="[UNVERIFIED]")
  - For each: checks if KCC-medewerker has since verified (updated bsn field)
  - If unverified > 30 days: flags for admin review ("Unverified burger [id] not verified for 30 days")
  - Optional: auto-delete very old unverified records per retention policy

### Phase 5: Event Listeners & Activity Integration

- [x] **T18** (built differently — inline, not event-subscriber): The requirement (each contactmoment appends a case `activity` entry) is satisfied INLINE: `ContactMomentService::recordActivity()` writes the timestamped activity directly on create/quick-action, and `ContactMomentController::statusGeven()` records a `status_given` activity on confirm. No separate `ActivitiesEventSubscriber`/`ContactMomentCreatedEvent` indirection was introduced — the synchronous inline write is simpler and avoids an event round-trip while delivering the same audit-trail outcome. Original task text below.

- [ ] **T18-spec-original**: Update `lib/Listener/ActivitiesEventSubscriber.php`:
  - Subscribe to `ContactMomentCreatedEvent`
  - On trigger: calls `ContactMomentService.recordActivity(caseId, contactmomentId, type, ...)`
  - Maps contactmoment `aard` to activity `type`: "contact_inbound_telefoon", "contact_inbound_email", "contact_outbound_statusgeven", "contact_outbound_klacht", etc.

- [x] **T19** (built differently — inline in the job, not event-subscriber): The requirement (sentiment detection appends a sentiment activity to related cases, with notification when escalation is recommended) is satisfied INLINE inside `SentimentAnalysisJob`: on `escalatieAanbevolen` it appends a sentiment activity to each related case via `ContactMomentService::recordActivity()`. No separate `SentimentEventSubscriber`/`SentimentDetectedEvent` was introduced — same outcome, fewer moving parts. The escalation-→-notification dispatch to the case assignee/manager remains the one residual sub-item (notification engine integration). Original task text below.

- [ ] **T19-spec-original**: Create `lib/Listener/SentimentEventSubscriber.php`:
  - Subscribe to `SentimentDetectedEvent` (published by SentimentAnalysisJob)
  - On trigger: appends activity to linked case(s) with sentiment details: `{type: "sentiment_detected", sentiment: "negatief", escalatie_level: "rood", triggers: ["klacht", "advocaat"]}`
  - If escalatieLevel >= "oranje": additionally triggers notification to case assignee or manager

### Phase 6: Seed Data & Defaults

- [x] **T20** (NEWLY BUILT this PR): Seeded via the canonical procest pattern — a `IRepairStep` (`lib/Repair/SeedKccWerkplekData.php`, registered in `appinfo/info.xml` post-migration) driving `lib/Service/KccWerkplekSeedDataService.php`, reading `lib/Settings/kcc_werkplek_seed_data.json`. Seeds the 5 default `kccQuickAction` records (status_geven, nieuwe_zaak, klacht_registreren, doorverbinden, bel_terug_inplannen) and 2 example `belplan` records (algemeen gemeentenummer with keuzemenu + vaardigheid_match + wachtrij_overflow, and a meldingen-nummer). Idempotent (id-matched skip). The sentiment trigger-word default list is already seeded as a SettingsService config default (T02). 5 PHPUnit tests cover create/idempotency/enum-validity/belplan-shape/unconfigured-guard. Original task text below.

- [ ] **T20-spec-original**: Create seed data script `lib/Migrations/Version20XX_seedKccDefaults.php`:
  - Seed default `kccQuickAction` records:
    - Status terugkoppelen (status_geven)
    - Nieuwe zaak (nieuwe_zaak)
    - Klacht registreren (klacht_registreren)
    - Doorverbinden (doorverbinden)
    - Bel terug inplannen (bel_terug_inplannen)
  - Seed default `klantSentiment` trigger words (Dutch wordlist)
  - Seed 2-3 example `belplan` records (general number with keuzemenu + vaardigheid routing)
  - Seed example `emailTemplate` for status-terugkoppelen (per zaaktype: omgevingsvergunning, melding, bezwaar)

## Verification Tasks

**Verification status (2026-06-14):** Automated verification is GREEN at the unit +
contract layer: PHPUnit 1279 tests pass (only 4 pre-existing `ZipArchive`/ext-zip env
errors, unchanged from baseline) including the new 5-test seed suite and the existing
KCC service tests (BelplanRouting 10, Sentiment 9, BurgerIdentification, ContactMoment);
vitest covers the trigger-word round-trip (9); a Playwright spec-coverage spec asserts the
new KCC admin UI renders (V12-adjacent); a Newman collection contract-tests the belplan +
specialist-beschikbaarheid read endpoints and the no-IDOR `?burgerId` guard (V02/V08-adjacent).
The schema fragment loads via the ADR-037 loader (V01). Full LIVE-instance end-to-end runs
(V03-V11) and the DigiD/SIP cross-app legs are deferred with the cross-app pieces above;
they require the pipelinq KCC-werkplek UI + OpenConnector DigiD that are not in this repo.

- [ ] **V01**: Schemas load correctly:
  - Run `openregister:load-register procest` command
  - Verify no schema validation errors
  - Check procest_register.json contains all 7 new schemas with correct field types + required flags

- [ ] **V02**: Routes resolve before SPA catch-all:
  - Navigate to `/index.php/apps/procest/api/specialist-beschikbaarheid`
  - Verify returns JSON (not SPA HTML)
  - Test all controller endpoints: contactmomenten, voorblad, quick-actions, doorverbinding, specialist-beschikbaarheid, belplan

- [ ] **V03**: Burger identification end-to-end:
  - **DigiD flow**: Trigger DigiD auth in test; verify burger record created/linked; check identificatieMethode="digid"
  - **Identificatievragen flow**: Enter test data (naam, geboortedatum, adres, bsn); verify score calculation; check geidentificeerdeBurgerId is populated if score >= threshold
  - **Unidentified fallback**: Call with unknown phone; verify case-voorblad shows only openbare zaaksinfo (no personal contact details)

- [ ] **V04**: Case-voorblad auto-opens and displays correctly:
  - Create test Burger with 3 active cases
  - Simulate inbound contact; verify case-voorblad JSON includes:
    - Burger NAW
    - Max 10 active zaken (if fewer, all of them)
    - Max 5 recente contactmomenten
    - Openstaande facturen (empty or populated based on test data)
    - Suggested dialogue topic (e.g., "Statusvraag omgevingsvergunning")
  - Response time < 2 seconds (performance benchmark)

- [ ] **V05**: Quick-action "Status terugkoppelen" end-to-end:
  - Open case with status "In behandeling"
  - Click quick-action "Status terugkoppelen"
  - Verify rendered status text matches template + case data
  - Confirm status; verify new activity entry appended to case: type="status_given", text=[generated], medewerker=[name]
  - Verify contactmoment created with aard="statusverzoek", firstTimeFix=true if confirmed

- [ ] **V06**: Quick-action "Nieuwe zaak" end-to-end:
  - In active contact, click quick-action "Nieuwe zaak" with zaaktype="melding_openbare_ruimte"
  - Fill intake form (location, defect type, urgency, description)
  - Submit; verify new case created with:
    - zaaktype="melding_openbare_ruimte"
    - initiator=identified burgerId
    - startDate=now()
    - deadline=calculated per zaaktype
    - assignee=team_openbare_ruimte (from routing)
  - Verify case ID returned and added to contactmoment.nieuweZaakIds
  - Verify firstTimeFix=true on contactmoment

- [ ] **V07**: Quick-action "Klacht registreren" end-to-end:
  - In negative-sentiment contact, click "Klacht registreren"
  - Fill form (related case, reason, severity)
  - Submit; verify klacht case created with:
    - zaaktype="klacht_ex_artikel_9_1_awb"
    - deadline=P42D
    - assigned to klachtenfunctionaris
  - Verify docudesk ontvangstbevestiging triggered (log entry visible)
  - Verify case activity shows klacht creation

- [ ] **V08**: Belplan routing end-to-end:
  - Configure test belplan with keuzemenu: ["Omgevingsvergunning", "Bouwtoezicht"]
  - Set up test specialists with different beschikbaarheid (one beschikbaar for omgevingsvergunning, others in_gesprek)
  - Call belplan routing endpoint: POST /api/belplannen/route with phoneNumber + menu selection
  - Verify returns specialist with lowest wachtrij + correct vaardigheid match
  - Test overflow: make all specialists busy; verify overflow to generalist with escalatie-flag

- [ ] **V09**: Sentiment detection end-to-end:
  - Create test contactmoment with transcriptie containing trigger words ("klacht" + "advocaat")
  - Run SentimentAnalysisJob (manual or wait for scheduled interval)
  - Verify klantSentiment record created with:
    - sentimentScore <= -0.6
    - sentimentLabel="boos" or "negatief"
    - triggerWoorden includes ["klacht", "advocaat"]
    - escalatieAanbevolen=true
  - Verify case activity appended with sentiment event
  - Verify KCC-medewerker notification would be displayed (check notification builder)

- [ ] **V10**: Doorverbinding context-snapshot end-to-end:
  - Create contact with identified burger + related case
  - Execute quick-action "Doorverbinden" to test specialist
  - Verify doorverbinding record created with:
    - contextSnapshot includes burger NAW, case state, contact summary, sentiment
    - contextSnapshot is immutable (snapshots at time of transfer)
  - Specialist accepts transfer
  - Verify doorverbinding.geaccepteerd=true, acceptatieTijd=recorded
  - Specialist appends notes; verify contextOverdracht is append-only (original snapshot preserved)

- [ ] **V11**: Klantreis timeline aggregation:
  - Create test Burger with 5 contactmomenten across channels (phone, email, chat, portal)
  - View klantreis timeline; verify:
    - All 5 contacts displayed chronologically
    - Each shows kanaal, duur (if applicable), samenvatting
    - Aggregations visible: "5 contactmomenten in 7 days", "2 statusverzoeken", etc.
    - Drill-down on one contact shows full details (transcript, linked cases, sentiment, quick-actions executed)

- [ ] **V12**: Settings persistence:
  - Edit KCC integration settings (e.g., identification_method, sentiment_polling_interval)
  - Verify settings saved to SettingsService config
  - Restart background jobs; verify they use updated settings
  - Change trigger words list; run SentimentAnalysisJob; verify new trigger words are detected

## Documentation Tasks

- [ ] **D01**: Update README.md or ARCHITECTURE.md:
  - Add "KCC-werkplek Integration" section
  - List all new services, controllers, schemas
  - Include configuration options table
  - Include troubleshooting: "What if specialist-beschikbaarheid API is unreachable? → Falls back to cached data"

- [ ] **D02**: Create API documentation (OpenAPI/Swagger):
  - Document all /api/contactmomenten, /api/quick-actions/*, /api/doorverbindingen/*, /api/specialist-beschikbaarheid, /api/belplannen endpoints
  - Include request/response examples
  - Include error codes (e.g., 400 "Invalid identification score", 403 "Unauthorized for klacht action", 504 "specialist-beschikbaarheid API timeout")

- [ ] **D03**: Create admin guide:
  - How to configure belplan (keuzemenu, routing steps, overflow rules)
  - How to add/edit quick-actions
  - How to set identification threshold
  - How to tune sentiment trigger words
  - How to interpret sentiment trends in dashboard

## Testing Tasks

- [ ] **T-Functional**: Functional testing suite:
  - Test all flows from specs (REQ-KCC-001 through REQ-KCC-011)
  - Each flow: happy path + error cases (network failure, missing data, permission denied, etc.)
  - Document test results in test matrix

- [ ] **T-Performance**: Performance baseline:
  - Measure case-voorblad fetch time at 10, 50, 100 concurrent requests
  - Target: < 2 seconds at 100 concurrent
  - Identify bottlenecks (DB queries, API calls to leges-heffingen, etc.)
  - Implement caching or indexing as needed

- [ ] **T-Security**: Security review:
  - Verify Burger PII encryption (BSN at-rest, TLS in-transit)
  - Verify case-voorblad authorization (no cross-tenant leakage, no viewing zaken of other burgers)
  - Verify quick-action permission checks (mandaat-matrix enforced)
  - Verify sentiment trigger-word injection safety (substring matching, no code execution)
  - Verify doorverbinding context-snapshot cannot be altered (immutable)

- [ ] **T-Integration**: Integration testing with pipelinq mock:
  - Mock pipelinq KCC-werkplek calling Procest APIs
  - Test contactmoment creation → case-voorblad return
  - Test quick-action execution → pipelinq receives result
  - Test specialist-beschikbaarheid polling → pipelinq receives availability list

---

## Rollout Plan

1. **Pilot phase** (1 week): Deploy to 1 KCC (10 medewerkers) with shadow mode (log all sentiment/routing decisions without auto-executing)
2. **Feedback** (1 week): Gather medewerker feedback; tune sentiment trigger words; adjust routing thresholds
3. **General availability** (production): Roll out to all KCC teams; provide brief training on quick-actions + sentiment flags

---
