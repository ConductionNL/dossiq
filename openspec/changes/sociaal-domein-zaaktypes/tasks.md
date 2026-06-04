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

## Spec content verification (reviewer gates)

Before this change can be merged, reviewers MUST verify:

### ADR-022 (No parallel storage) gate

- [ ] **WMO spec:** Search for `lib/Db/{*}_mapper.php` or equivalent custom persistence logic — MUST NOT appear. WmoZaak is fully OR-backed.
- [ ] **Jeugdwet spec:** No custom JeugdwetZaakService, MdoOverlegRepository, or parallel storage layer. All entities are OR schemas.
- [ ] **Participatiewet spec:** No ReIntegratieTrajectMapper or parallel re-integratie database. All entities OR-backed.
- [ ] **AVG spec:** No separate audit-log table in procest; audit logging is delegated to openregister (immutable auditTrail) or a dedicated but minimal sociaal-domein auditLog table (not a fork of openregister's audit).

### ADR-031 (No custom state machines) gate

- [ ] **WMO spec:** Every status transition (melding → onderzoek → beschikking → uitvoering → evaluatie → afgesloten) is declared as `x-openregister-lifecycle` in the schema patch (not implemented as custom WmoZaakService::transition()).
- [ ] **Jeugdwet spec:** Every status transition (melding → gezinsplan → ondersteuning → evaluatie → verlengingen → afgesloten) declared as `x-openregister-lifecycle`.
- [ ] **Participatiewet spec:** Every status transition (aanvraag → toetsing → beschikking → re-integratie → afgesloten) declared as `x-openregister-lifecycle`.

### ADR-024 (Manifest navigation) gate

- [ ] **WMO spec:** Case-type `wmo-melding` is discoverable from procest's case-type selector (navigation entry required in register manifest).
- [ ] **Jeugdwet spec:** Case-type `jeugdwet-melding` is discoverable.
- [ ] **Participatiewet spec:** Case-type `bijstandsaanvraag` is discoverable.

### ADR-032 (Config vs. code) gate

- [ ] **Overall:** This change is `kind: config` (specs only, no PHP/Vue/tests/register patches). All four spec files present. No code artifacts added.
- [ ] **Implementation sequencing:** Follow-up code chains are documented in design.md (Wave 1: register-patch chains; Wave 2: access-guard + audit implementation; Wave 3: UI optional).

### AVG legal defensibility gate

- [ ] **AvgClassificatie block:** Every requirement (REQ-AVG-001 through REQ-AVG-008) includes specific GDPR/UAVG articles and Dutch selectielijst references.
- [ ] **Mandatory at creation:** REQ-AVG-001 proves that zaak creation fails without AvgClassificatie filled.
- [ ] **Access guards hardcoded:** REQ-AVG-002 shows that wijkteam membership is checked at query time (data-driven, not role-driven).
- [ ] **Anonymization on export:** REQ-AVG-003 specifies which PII fields are masked (`pii-detection-masking` from openregister invoked on export without toestemming).
- [ ] **Toestemming revocable:** REQ-AVG-004 proves that citizens can revoke consent; future exports are automatically anonymized.
- [ ] **Audit immutable:** REQ-AVG-005 specifies that every read-access is logged with medewerker-id, timestamp, IP, fields accessed — for subject-access-requests and FG-audits.
- [ ] **Retention & destruction:** REQ-AVG-006 shows automatic vernietigingsDatum calculation and archivaris-review requirement (no silent deletion).
- [ ] **SAR support:** REQ-AVG-007 describes how the system can generate a subject-access-request report (all zaakken for a BSN + auditLog + documents, in plain Dutch).
- [ ] **Incident reporting:** REQ-AVG-008 documents breach-incident recording and AP notification workflow (72-hour GDPR requirement).

### Wijkteam access isolation gate

- [ ] **Data-driven guards:** REQ-AVG-002 and comparable requirements in WMO/Jeugdwet/Participatiewet specs prove that access is not role-based alone but checks zaak.wijkteam == user.wijkteam at query time.
- [ ] **FG-audit override:** REQ-AVG-002 shows that FG can access metadata + auditLog without full content (special mode, logged as FG-audit-override).
- [ ] **Second-handler exception:** REQ-AVG-002 or WMO/Jeugdwet specs show that tweedeBehandelaarId grants full access regardless of wijkteam membership.

### Retention compliance gate

- [ ] **WMO:** REQ-WMO-009 specifies 15-year retention (from context-brief selectielijst).
- [ ] **Jeugdwet:** REQ-JW-008 specifies 20-year retention (from context-brief selectielijst).
- [ ] **Participatiewet:** REQ-PW-006 specifies 10-year retention (from context-brief selectielijst).
- [ ] **Destruction proposals:** All three zaaktypes + AVG spec describe automatic vernietigingsvoorstel generation when deadline nears (30 days before vernietigingsDatum).

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

