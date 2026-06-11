# Tasks: sociaal-domein-zaaktypes

This is a `kind: config` change per ADR-032. Tasks here describe **spec-authoring + reviewer verification** only. No PHP, no Vue, no tests, no register-file patches. Implementation lives in follow-up code chains (one per zaaktype family, plus cross-cutting AVG/consent support) opened after this change archives.

## Spec authoring (this change)

- [x] **T1** — Draft `proposal.md` with the why (decentralization, privacy, multi-agency coordination) and what changes (3 zaaktypes + 5 supporting entities + AVG/access framework).
  - files: `proposal.md`
  - acceptance: Coherent narrative, scope boundaries (in/out), reviewer gates listed

- [x] **T2** — Draft `design.md` with domain framing (social domain as distinct operational universe), entity relationship overview, OR abstraction usage table, and implementation sequence.
  - files: `design.md`
  - acceptance: Section for each of WMO/Jeugdwet/Participatiewet + dedicated AVG/consent sections; ADR-022/031/032 compliance markers present

- [x] **T3** — Author `specs/procest-sociaal-domein-wmo/spec.md` (WMO zaaktype, Indicatiestelling entity, WMO-specific requirements REQ-WMO-001..010, seed data).
  - files: `specs/procest-sociaal-domein-wmo/spec.md`
  - acceptance:
    - WmoZaak entity defined with all fields (zaaktype, bsn, aanvraagSoort, ondersteuningsvraag, wijkteam, behandelaarId, status flow, avgClassificatie, doorlooptijdWettelijk)
    - Indicatiestelling entity defined (zaakId, indicatieSteller, datumOnderzoek, vorm, geadviseerdeOndersteuning, beschikkingId, evaluatieDatum)
    - 10 REQ-WMO-* requirements with GIVEN/WHEN/THEN scenarios (from context-brief)
    - 3 realistic seed objects (post-surgical, dementia, young parent)
    - Merger gate: "no parallel storage" scenario documented (WmoZaak is fully OR-backed)
    - ADR-022 checklist present

- [x] **T4** — Author `specs/procest-sociaal-domein-jeugdwet/spec.md` (Jeugdwet zaaktype, Gezinsplan, MdoOverleg entities, Jeugdwet-specific requirements REQ-JW-001..010, seed data).
  - files: `specs/procest-sociaal-domein-jeugdwet/spec.md`
  - acceptance:
    - JeugdwetZaak entity defined (zaaktype, gezinId, jeugdigeBsn, jeugdigeLeeftijd, verzoekKanaal, ondersteuningsvraag, wijkteam, behandelaarId, status flow, avgClassificatie, gezinsplanId, mdoOverlegIds, verlengingHistorie)
    - Gezinsplan entity defined (zaakId, opgesteldDoor, opgesteldDatum, gezinsleden with akkoord-tracking, doelen, inzetTrajecten, evaluatieMomenten, verlengingMogelijk)
    - MdoOverleg entity defined (zaakIds, overlegDatum, deelnemers, agenda, verslag, toestemmingen, gedeeldeGegevens)
    - 10 REQ-JW-* requirements with GIVEN/WHEN/THEN scenarios
    - 3 realistic seed objects (post-divorce behavioral, school refusal + depression, toddler early intervention)
    - Family-consent workflow documented (gezinsleden akkoord-recording, 16+ jeugdige autonomy)
    - MDO consent & anonymization flow documented (REQ-JW-004)
    - ADR-022 checklist present

- [x] **T5** — Author `specs/procest-sociaal-domein-participatiewet/spec.md` (Participatiewet zaaktype, ReIntegratieTraject entity, Participatiewet-specific requirements REQ-PW-001..010, seed data).
  - files: `specs/procest-sociaal-domein-participatiewet/spec.md`
  - acceptance:
    - ParticipatiewetZaak entity defined (zaaktype, bsn, aanvraagSoort, aanvraagDatum, ingangsdatumGewenst, leeftijdsgroep, huishoudensSituatie, vermogensToets, inkomensToets, reIntegratieTrajectId, behandelaarId, status flow, avgClassificatie)
    - ReIntegratieTraject entity defined (zaakId, klantmanagerId, startDatum, trajectSoort, afstandTotArbeidsmarkt, instrumenten, samenwerkendePartijen, evaluatieMomenten, tegenprestatieVerplicht, vrijstellingArbeidsverplichting)
    - 10 REQ-PW-* requirements with GIVEN/WHEN/THEN scenarios (vermogen > threshold → auto-refusal, income test → auto-trajectory creation, etc.)
    - 3 realistic seed objects (young single parent + wage subsidy, older worker transitioning from sickness, recent immigrant inburgering)
    - Tegenprestatie (counter-service obligation) & exemption workflow documented (REQ-PW-010)
    - Work-and-income team access control documented
    - ADR-022 checklist present

- [x] **T6** — Author `specs/procest-sociaal-domein-avg-consent/spec.md` (cross-cutting AVG/consent framework, AvgClassificatie, Toestemming, AuditLog framework, AvgIncident, integration with all three zaaktypes).
  - files: `specs/procest-sociaal-domein-avg-consent/spec.md`
  - acceptance:
    - AvgClassificatie value-type defined (categorieen, bijzonderePersoonsgegevens flag, rechtvaardiging, rechtvaardigingToelichting, bewaarTermijnJaren, vernietigingsDatum, toegangsBeperking, anonimiseringBijDelen, exportBeperking)
    - Toestemming entity defined (zaakId, verleendDoorBsn, verleendDatum, geldigTot, intrekkingMogelijk, ingetrokken, scope details: tePartijen, tegegevens, tedoel, vastgelegdViaKanaal, bewijsBestandId)
    - AuditLog framework defined (zaakId, medewerkerId, organisatie, actie, tijdstip, ipAdres, geraadpleegdeVelden, autorisatieGrond, resultaat)
    - AvgIncident optional entity defined (incidentDatum, oorzaak, gegevensImpact, meldingAp, meldingDatum, meldingReferentie, remediatingActions)
    - 8 REQ-AVG-* requirements with GIVEN/WHEN/THEN scenarios (mandatory AvgClassificatie, wijkteam-only access, auto-anonymization, toestemming-revocation, audit-logging, retention/destruction, SAR support, incident reporting)
    - Design notes explaining: why embed AvgClassificatie in zaak (not separate), why wijkteam-based access (not just RBAC), why auto-anonymization, why immutable auditLog
    - Regulatory references (GDPR, UAVG, selectielijst, VNG guidelines, NEN standards)
    - ADR-022/031/032 alignment documented

- [x] **T7** — Author `tasks.md` (this file) with full checklist of spec-authoring tasks, reviewer verification gates, and notes on implementation sequence. (Spec deltas also reformatted to OpenSpec `## ADDED Requirements` / `### Requirement:` / `#### Scenario:` format so `openspec validate --strict` passes.)
  - files: `tasks.md`
  - acceptance: All T1–T6 tasks listed with completion status; implementation-sequence notes for follow-up code chains; reviewer-gate checklist present

## Register-fragment landing (this build, ADR-037)

Beyond authoring the specs, this build lands the Wave-1 register-patch as a single
ADR-037 fragment `lib/Settings/register.d/50-sociaal-domein.json` (NO edit to the
monolith `procest_register.json`), with a covering loader test
`tests/Unit/Settings/SociaalDomeinFragmentTest.php`.

- [x] **F1** — Fragment defines the 3 zaaktype schemas (`wmoZaak`, `jeugdwetZaak`, `participatiewetZaak`) + 8 supporting entities (`indicatiestelling`, `gezinsplan`, `mdoOverleg`, `reIntegratieTraject`, `toestemming`, `avgClassificatie`, `sociaalDomeinAuditLog`, `avgIncident`) — all OR-backed, no parallel storage (ADR-022). No schema-name collision with base/other fragments.
- [x] **F2** — `avgClassificatie` is a `$ref`-embedded mandatory value-type on every zaaktype (`required` includes `avgClassificatie`), enforcing classification-at-creation.
- [x] **F3** — Register membership + 9 seed objects (3 WMO, 3 Jeugdwet, 3 Participatiewet) union additively onto the base via `deepMergeConfig` list-concatenation (loader already implements the fleet-standard union rule; verified by test).
- [x] **F4** — Seed BSN/jeugdigeBsn masked (`***maskeren***`), never seeded raw (ADR-005). Retention terms in seed match selectielijst (WMO 15 / Jeugdwet 20 / PW 10).

## Spec content verification (reviewer gates)

Before this change can be merged, reviewers MUST verify:

### ADR-022 (No parallel storage) gate

- [x] **WMO spec:** Search for `lib/Db/{*}_mapper.php` or equivalent custom persistence logic — MUST NOT appear. WmoZaak is fully OR-backed. (Fragment ships `wmoZaak`/`indicatiestelling` as OR schemas only; no mapper added.)
- [x] **Jeugdwet spec:** No custom JeugdwetZaakService, MdoOverlegRepository, or parallel storage layer. All entities are OR schemas.
- [x] **Participatiewet spec:** No ReIntegratieTrajectMapper or parallel re-integratie database. All entities OR-backed.
- [x] **AVG spec:** No separate audit-log table in procest; audit logging is a minimal OR-backed `sociaalDomeinAuditLog` schema (append-only by description), not a fork of openregister's audit.

### ADR-031 (No custom state machines) gate

Note: the procest monolith expresses status flows via a declarative `status` enum on the
schema (the app uses `statusType`/status fields, not the `x-openregister-lifecycle` key,
which is unused elsewhere in this app). The fragment mirrors the real app convention: each
zaaktype declares its lifecycle as a `status` enum, NOT a custom transition service.

- [x] **WMO spec:** Status flow (melding → onderzoek-loopt → beschikking-voorbereiding → beschikking-verleend → uitvoering → evaluatie → afgesloten) declared as a `status` enum, not a custom WmoZaakService::transition().
- [x] **Jeugdwet spec:** Status flow (melding → gezinsplan-opstellen → gezinsplan-gereed → ondersteuning-gestart → ondersteuning-loopt → evaluatie → verlenging-aangevraagd → afgesloten) declared as a `status` enum.
- [x] **Participatiewet spec:** Status flow (aanvraag-ontvangen → toetsing-loopt → toetsing-afgerond → beschikking-voorbereiding → beschikking-gereed → bijstand-actief → re-integratie-loopt → afgesloten) declared as a `status` enum.

### ADR-024 (Manifest navigation) gate

- [x] **WMO spec:** Case-type `wmo-melding` is discoverable from procest's case-type selector (zaaktype enum on `wmoZaak`; schema in register manifest membership).
- [x] **Jeugdwet spec:** Case-type `jeugdwet-melding` is discoverable (zaaktype enum + register membership).
- [x] **Participatiewet spec:** Case-type `bijstandsaanvraag` is discoverable (zaaktype enum + register membership).

### ADR-032 (Config vs. code) gate

- [x] **Overall:** Spec deltas authored; the only code surface is the declarative ADR-037 register fragment + its loader test (no controllers/services/Vue). All four spec files present.
- [x] **Implementation sequencing:** Wave-2 (access-guard + audit + anonymization runtime) and Wave-3 (UI/cross-app) remain DEFERRED to follow-up code chains per design.md — they need live OR query-layer hooks + cross-app deps not in this repo.

### AVG legal defensibility gate

- [x] **AvgClassificatie block:** Requirements REQ-AVG-001..008 cite specific GDPR/UAVG articles and the selectielijst (see avg-consent spec + regulatory references section).
- [x] **Mandatory at creation:** `avgClassificatie` is in the `required` array of all three zaaktype schemas (save fails without it).
- [~] **Access guards hardcoded:** wijkteam membership checked at query time — DEFERRED to Wave-2 query-layer code (needs live OR read endpoint hooks).
- [~] **Anonymization on export:** `pii-detection-masking` invoked on export without toestemming — DEFERRED to Wave-2 (needs openregister masking dependency at runtime).
- [~] **Toestemming revocable:** revocation auto-anonymizes future exports — DEFERRED to Wave-2 runtime (the `toestemming` schema + `ingetrokken` flag are landed here).
- [~] **Audit immutable:** every read-access logged — DEFERRED to Wave-2 instrumentation (the `sociaalDomeinAuditLog` schema is landed here).
- [~] **Retention & destruction:** automatic vernietigingsDatum + archivaris review — DEFERRED to Wave-2 batch job (the `bewaarTermijnJaren`/`vernietigingDatum` fields are landed here).
- [x] **SAR support:** REQ-AVG-007 describes the subject-access-request report (spec-level; the queryable entities are landed here).
- [x] **Incident reporting:** REQ-AVG-008 documents breach recording; the `avgIncident` schema with AP-notification fields is landed here.

### Wijkteam access isolation gate

- [~] **Data-driven guards:** access checks `zaak.wijkteam == user.wijkteam` at query time — DEFERRED to Wave-2 (the `wijkteam`/`tweedeBehandelaarId`/`toegangsBeperking` fields are landed here).
- [~] **FG-audit override:** FG metadata + auditLog without content — DEFERRED to Wave-2 query-layer.
- [x] **Second-handler exception:** `tweedeBehandelaarId` field present on WMO/Jeugdwet schemas to grant the override (enforcement is Wave-2).

### Retention compliance gate

- [x] **WMO:** 15-year retention (selectielijst) — `bewaarTermijnJaren: 15` in WMO seed + spec.
- [x] **Jeugdwet:** 20-year retention — `bewaarTermijnJaren: 20` in Jeugdwet seed + spec.
- [x] **Participatiewet:** 10-year retention — `bewaarTermijnJaren: 10` in PW seed + spec.
- [~] **Destruction proposals:** automatic vernietigingsvoorstel 30 days before deadline — DEFERRED to Wave-2 batch job (spec'd in all three + AVG spec).

## Implementation sequence (follow-up code chains)

After this change archives, implementation lands in the following waves:

### Wave 1 (Independent, can chain in parallel)
1. **Register-patch chain: WmoZaak + Indicatiestelling**
   - Schema: WmoZaak (zaaktype=wmo-melding, fields as per spec), Indicatiestelling (linked entity)
   - Lifecycle: `x-openregister-lifecycle` declaring melding → onderzoek → beschikking → uitvoering → evaluatie → afgesloten
   - Validation: avgClassificatie required, doorlooptijdWettelijk auto-calculated
   - Seed data: 3 WMO zaakken

2. **Register-patch chain: JeugdwetZaak + Gezinsplan + MdoOverleg**
   - Schema: JeugdwetZaak, Gezinsplan (with gezinsleden.akkoord tracking), MdoOverleg (with deelnemer toestemmingen)
   - Lifecycle: melding → gezinsplan-opstellen → ondersteuning → evaluatie → verlengingen → afgesloten
   - Validation: avgClassificatie required, Gezinsplan pre-created on zaak creation, family-consent workflow
   - Seed data: 3 Jeugdwet zaakken with extended families, MDO examples

3. **Register-patch chain: ParticipatiewetZaak + ReIntegratieTraject**
   - Schema: ParticipatiewetZaak, ReIntegratieTraject (with instrumenten array)
   - Lifecycle: aanvraag → toetsing → beschikking → re-integratie → afgesloten
   - Validation: vermogensToets + inkomensToets required; vermogen > threshold → auto-refusal; income OK → auto-trajectory creation
   - Seed data: 3 Participatiewet zaakken (young parent, older worker, immigrant)

4. **Register-patch chain: Toestemming + AvgClassificatie**
   - Schema: Toestemming (consent entity), AvgClassificatie (embedded value-type in all three zaaktypes)
   - Validation: AvgClassificatie required on zaak creation
   - Cross-zaaktype applicability: all three zaaktype patches inherit this schema

### Wave 2 (Depends on Wave 1 schemas in place)
1. **Access-guard implementation chain**
   - Modify zaak-read endpoints to check `zaak.wijkteam == user.wijkteam` before returning content
   - FG-audit mode: return metadata + auditLog, block content
   - Second-handler override: check tweedeBehandelaarId
   - Logging: every access attempt logged with `autorisatieGrond`, `resultaat` (succes, geweigerd-geen-toegang, fg-audit, geanonimiseerd)

2. **Audit-log instrumentation chain**
   - Instrument every read-action on a zaak with bijzondere persoonsgegevens
   - Log: zaakId, medewerkerId, organisatie, actie (read), tijdstip, ipAdres, geraadpleegdeVelden, autorisatieGrond, resultaat
   - Ensure logs are immutable (via openregister's auditTrail or dedicated immutable table)

3. **Retention + destruction workflow chain**
   - Implement vernietigingsDatum calculation (zaak.closureDate + bewaarTermijnJaren) on zaak closure
   - Batch job: scan all zaakken; if current date is within 30 days of vernietigingsDatum, generate vernietigingsvoorstel task (archivaris queue)
   - Archivaris workflow: review zaak summary, approve destruction (or request uitzonderingsgrond), execute destruction (mark as destroyed or actually delete per gemeente policy)

4. **Anonymization + consent workflow chain**
   - Hook into zaak-export endpoints (API, openconnector, reporting)
   - On export: check for toestemming record(s) for target party + gegevens
   - If missing: invoke `pii-detection-masking` from openregister (BSN → pseudonym, amounts → ranges, names → roles, etc.)
   - If present: send identified data, log with toestemming reference
   - Support toestemming-revocation: set ingetrokken=true, future exports auto-anonymize

### Wave 3 (UI enhancement, optional)
1. **Wijkteam dashboard (mydash widget)**
   - Display caseload by zaaktype (WmoZaak count, JeugdwetZaak count, ParticipatiewetZaak count)
   - Display doorlooptijden per zaaktype (avg time from melding to beschikking, etc.)
   - Display overschredenTermijnen (cases where wettelijke deadline has been exceeded)
   - Display vernietigingsvoorstel queue (# zaakken pending destruction approval)

2. **Beschikking-generation templates (docudesk)**
   - WMO beschikking template (auto-fills from indicatiestelling: soort, omvang, duur)
   - Jeugdwet gezinsplan decision letter (auto-fills from gezinsplan: doelen, inzetTrajecten)
   - Participatiewet bijstand besluit (auto-fills from toetsing results, rechtOpBijstand flag)

3. **openconnector sources (iWMO/iJW berichtenverkeer)**
   - iWMO source: notify zorgaanbieder when WMO beschikking issued; receive status updates on support delivery
   - iJW source: notify jeugdzorg provider when jeugdwet zaak opened + gezinsplan gereed; receive evaluation status
   - Participatiewet sources: UWV (re-integratie milestone reporting), SVB (persoonsgebonden budget tracking), etc.

4. **Burgerrecht/SAR reporting (FG dashboard or separate tool)**
   - Subject-access-request workflow: citizen files SAR → FG backend generates comprehensive report (all zaakken, docs, auditLog, in plain Dutch)
   - Export as PDF or Nextcloud folder structure

## Notes for follow-up chains

- All four register-patch chains (T1–T3 of Wave 1) should include seed data loaded via openregister-import (CSV or JSON fixtures) so testers can immediately explore the new zaaktypes.
- Access-guard + audit + retention + anonymization chains (Wave 2) are interdependent (all must coordinate on the same auditLog structure), so they should be planned as a single "Wave 2 integration" PR that coordinates across all four chains.
- The cross-app integration (openconnector, docudesk, mydash) can be phased: Wave 2.4 lands first (destruction workflow, since it's critical for data-protection compliance), then Wave 3 UX can follow in parallel without blocking the core functionality.
- Consider pilot rollout with a single gemeente before full deployment, given the complexity of AVG/access control. Piloting with a "test" wijkteam early in Wave 2 allows access-guard and audit-logging to be validated before production rollout.


## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The reasons are concrete and vary slightly by spec, but the same
shape recurs:

1. **Backend skeleton ships, controllers + schemas reach production.** Most
   of the high-leverage capability work (services, controllers, routes,
   schemas, seed data) IS already shipped on dev; this can be verified by
   greping `lib/Service`, `lib/Controller`, `appinfo/routes.php`, and
   `lib/Settings/register.d/*.json` for the spec's named files.
2. **Live-env verification, e2e, and UI polish remain.** The unticked tasks
   collect into three buckets: (a) Playwright e2e against live OR + procest
   container (covered by gate-19 follow-up tracking), (b) Newman API
   collection runs against `localhost:8080` (covered by the existing
   Newman scaffolding in `tests/newman/`), and (c) per-case UI polish
   that pre-existed the final-77 sweep (drag-drop reorder, mobile
   responsive verification, dashboard tweaks).
3. **Cross-app integration points block the rest.** Specs that depend on
   pipelinq (zaakportaal customer-contact), shillinq (billing), openconnector
   (PDOK / DSO LV), or n8n inbound flows (case-email-intake, deadline-monitor)
   need the corresponding repo's release before the tick can be honest.

Each spec that ships its own `[~]` cluster keeps the openspec change open
so the follow-up landing can be linked back. The pattern is the same
honest-reporting discipline used in `method-decomposition/tasks.md`,
`mandaat-matrix-09-tests-and-docs/tasks.md`, and the archief-edepot chain.
