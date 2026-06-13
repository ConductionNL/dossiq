# Proposal: kcc-werkplek-zaaksysteem-bridge

## Summary

Integrate the KCC-werkplek (pipelinq contact center interface) directly into the Procest case management system to deliver real-time zaaksysteem context to KCC-medewerkers during every inbound contact. When a burger calls, emails, or chats, the system automatically opens a case-voorblad displaying their active zaken, contact history, sentiment indicators, and one-click quick-actions (status terugkoppelen, nieuwe zaak, klacht registreren, warm doorverbinden). Each contact is logged as a Contactmoment with full audit trail, geïdentificeerde burger link, and sentiment classification. Belplan-routering is datagedreven on vaardigheid, zaaktype, and realtime specialist beschikbaarheid. Escalations to back-office are warm doorverbindingen with context-overdraft. The bridge unifies all communication channels (telefoon, email, chat, webformulier, social media) into a single geconsolideerde klantreis.

## Motivation

Nederlandse KCC-medewerkers today juggle separate CRM/telefonie systems (Mitel, Cisco, Genesys) that don't integrate with the zaaksysteem. When a burger calls, the medewerker must manually search for zaken on BSN/naam, can't see cross-departmental zaken, doesn't know prior contactmomenten, and has to switch systems for every action. This destroys first-time-fix opportunity, lengthens behandeltijd, worsens klantervaring (burgers repeat their story), and loses the geconsolideerde klantreis for reporting. DigiD and BSN-verificatie are fragmented, sentiment signals go unheeded, and specialist beschikbaarheid is opaque. The KCC-werkplek-zaaksysteem bridge closes these gaps: burger authentication via DigiD or identificatievragen instantly surfaces all relevant zaaksysteem context, quick-actions accelerate common handlingen, sentiment monitoring surfaces escalation risks, and datagedreven belplan-routering matches burgers to available vaardigheid. First-time-fix soars, behandeltijd drops, NPS improves, and the entire klantreis is captured for coaching and compliance.

## Affected Projects

- [ ] Project: `procest` — Backend services, API endpoints, schemas for Contactmoment, Burger, KccQuickAction, Belplan, SpecialistBeschikbaarheid, Doorverbinding, KlantSentiment
- [ ] Project: `pipelinq` — KCC-werkplek integration, quick-action invocation, sentiment display, specialist availability polling

## Scope

### In Scope

- **Burger identification** — DigiD/openconnector integration for portaal-contacten; identificatievragen (naam + geboortedatum + adres + BSN bevestiging) for telefoon; identifies or creates Burger records
- **Case-voorblad on contact** — Auto-open zaak-voorblad showing NAW, open zaken (max 10), recente contactmomenten (max 5), openstaande facturen, suggested dialogue topics
- **Contactmoment registration** — Capture kanaal (telefoon, email, webformulier, chat, social media), identificatiemethode, kccMedewerkerId, gerelateerdeZaken, samenvatting, firstTimeFix flag, sentiment
- **Quick-actions** — Status terugkoppelen, Nieuwe zaak, Klacht registreren, Doorverbinden, Bel terug inplannen, Email sturen, Kopie document sturen (configurable per KCC-rol)
- **Belplan-routering** — Datagedreven routing on zaaktype, vaardigheid, realtime SpecialistBeschikbaarheid (status, wachtrij, gemiddelde behandeltijd), overflow-cascade
- **Sentiment detectie** — Real-time trigger-woord detection (ongelooflijk, klacht, wethouder, advocaat, media), sentiment-score, escalatie-aanbeveling to medewerker
- **Warm doorverbinding** — Context-overdraft (bellergegevens, zaak summary, sentiment, contact history) to specialist; specialist can accept/reject; context preserved in Doorverbinding record
- **Klantreis consolidatie** — Chronological view of all contactmomenten kanaal-overstijgend with aggregatie and drill-down

### Out of Scope

- Voice-to-text transcription (optional, delegated to pipelinq AI if present)
- Encrypted email/SIP security beyond standard TLS
- Custom workflow builder (workflows hardcoded per deployment)
- Multi-language UI beyond Dutch + English stubs
- Integration with external CRM platforms (Salesforce, Zendesk, etc.)

## Approach

1. Add `contactmoment`, `burger`, `kccQuickAction`, `belplan`, `specialistBeschikbaarheid`, `doorverbinding`, `klantSentiment` schemas to `procest_register.json`
2. Create `ContactMomentService` for logging inbound contacts, auto-opening case-voorblad, sentiment detection, activity recording
3. Create `BurgerIdentificationService` for DigiD auth via openconnector + identificatievragen flow + BRP lookup
4. Create `BelplanRoutingService` for datagedreven routing on zaaktype/vaardigheid/beschikbaarheid + overflow logic
5. Create `QuickActionService` for executing standard handelingen (status update, zaak creation, klacht, doorverbinding)
6. Create API endpoints for quick-actions, unlinked contactmomenten, belplan config, specialist beschikbaarheid polling
7. Backend activity logging: each contactmoment appends to case `activity` array + creates task if needed (klacht → klachtenfunctionaris)
8. Pipelinq integration: KCC-werkplek calls Procest API to fetch case-voorblad, execute quick-actions, poll specialist beschikbaarheid

## Risks

- **Burger matching collisions** — Multiple people with same naam/geboortedatum; identificatievragen must have high threshold (score > 0.8) to link Burger; below threshold shows only openbare/geanonimiseerde zaaksinfo
- **Sentiment false positives** — Dutch idioms ("ongelooflijk mooi!") trigger negative sentiment; mitigated by multi-word context + tone analysis; requires monitoring + tuning
- **Specialist beschikbaarheid staleness** — If pipelinq telefonie system doesn't push updates real-time, routing uses stale data; mitigated by short poll interval (< 30s) or webhook push
- **DigiD/openconnector outage** — Portaal-contacten can't auth; mitigated by fallback to identificatievragen or "unidentified" mode (openbare zaaksinfo only)
- **Performance at scale** — Fetching case-voorblad (10 zaken + 5 contactmomenten + sentiment per contact) under 2s for high-concurrency KCC; requires indexed queries, caching, or background pre-fetch
- **Doorverbinding context overdraft incompleteness** — Risk of breaking context trail if specialist details change during transfer; mitigated by immutable Doorverbinding snapshot + activity trail
