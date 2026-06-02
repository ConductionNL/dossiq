> SUPERSEDED 2026-06-02 (ADR-032): decomposed into the chain termijnbewaking-dwangsom-engine-01..11 (see openspec/changes/).

# Proposal: termijnbewaking-dwangsom-engine

## Summary

Introduce a single, auditable, stateful deadline engine (termijnbewaking) for wettelijke beslis-termijnen across all procest-driven decisions, with automatic Wet dwangsom-bij-niet-tijdig-beslissen penalty computation and financial system integration. The engine monitors processing deadlines, pauses for valid grounds (incomplete applications, legal extensions), enforces formal notification (ingebrekestelling), calculates daily penalties per the statutory tariff (€23–€45/day, max €1,442 per case), and triggers payment via openconnector integration to financial systems.

## Why

Dutch administrative law (AWB 4:1.3–4:1.3a) mandates strict decision deadlines (8 weeks standard, 26 weeks for permits, varying by sector), and communes pay out millions in statutory penalties annually when these are missed. Existing zaaksystemen lack termijn awareness, pause-and-extension logic, or integration with the ingebrekestelling-to-dwangsom workflow. The Nationale ombudsman has repeatedly flagged termijn-overschrijding as a structural issue. This engine provides domain-aware deadline tracking, legal-ground-compliant pausing, pro-active escalation alerts, automated penalty calculation per AWB 4:17, and financial-system signal generation—consumable by every procest capability (VTH-vergunningen, subsidies, complaints, Woo-requests, appeals) through a single API.

## What Changes

1. **Termijn Instance Creation & Binding** — Every zaak-creation automatically spawns a TermijnInstance based on zaaktype-matched TermijnDefinitie; system blocks zaak-creation if no matching definition exists
2. **Termijn-Event Audit Trail** — Immutable event log (TermijnGebeurtenis) recording start, pause, resume, extension, override, overschrijding, and ingebrekestelling events with timestamps, actors, and grounds
3. **Pause Management (AWB 4:5/4:15)** — Hersteltermijn pause when incomplete-application notices are sent; automatic pause-duration calculation; pause-expiry pro-active alert if no response
4. **Single Extension (AWB 4:14)** — Validates extension grounds, blocks second extension, emits extension-letter trigger, updates deadline
5. **Pro-Active Escalation Alerts** — Daily scan identifies upcoming deadlines (14d, 7d, 2d thresholds) and overschreden cases; multi-level escalation (handler → teamlead → manager) on terminal warning
6. **Ingebrekestelling Registration & Validation** — Formal notification recording with termijn-overschrijding validation; prevents premature ingebrekestelling; spawns DwangsomBerekening starting 14 days post-notification
7. **Dwangsom Daily Calculation** — Automated tariff application: €23 days 1–14, €35 days 15–28, €45 days 29+; plafond enforcement (€1.442 max); stops on beschikking
8. **Financial System Integration** — Structured payment signal to ERP via openconnector with bedrag, IBAN, zaak-ref, wettelijke-grondslag; status update on payment confirmation
9. **Burger Notification** — Receipt notification with toezegging and deadline; extension notification; ingebrekestelling notification; dwangsom toekenning; payment confirmation
10. **Management Reporting** — Kwartaal termijn-KPI (% within deadline, average duration, overruns by zaaktype/afdeling) and jaarrekening dwangsom-uitbetalingen report

## Impact

- **Affected projects**: procest (primary consumer), openregister (register templates for 6 new schemas), openconnector (ERP payment signaling), procest-dashboard (termijn-KPI display), nldesign-portal (burger notifications)
- **Code surface**: 6 new entity schemas (TermijnDefinitie, TermijnInstance, TermijnGebeurtenis, Ingebrekestelling, DwangsomBerekening, DwangsomUitbetaling); termijn-engine service layer (deadline calculation, pause/extension logic, tariff computation); REST API (20+ endpoints); daily cronjob (deadline scan, penalty accrual); message templates (burst notifications); report generators
- **Dependencies**: REQUIRED: procest base (zaak-engine, case-document linking), openregister (register REST API, object management); optional: procest-dashboard (termijn-KPI widget), nldesign-portal (notification rendering)
- **Standards**: AWB title 4.1.3–4.1.3a (decision deadlines), AWB 4:13–4:18 (termijn-gronden, dwangsom staffel), Wet dwangsom en beroep bij niet tijdig beslissen (Stb. 2009, 383), sectorale reglementingen (Wabo, Omgevingswet, Wmo 2015, Woo, VW 2000), ISO 9001 kwaliteitsmanagement

## Scope

### In Scope — Core Termijn Engine

- TermijnDefinitie configuration per zaaktype (wettelijke grondslag, standard duur, verlengingsruimte, afwijkend dwangsom-regime)
- TermijnInstance per-zaak binding and lifecycle (status transitions: lopend → gepauzeerd → verlengd → voltooid → overschreden)
- TermijnGebeurtenis audit trail (immutable event recording: start, pauze, hervat, verleng, voltooi, ingebrekestelling-ontvangen, overschreden, dwangsom-gestart)
- Hersteltermijn pause (AWB 4:5) with automatic resume on aanvulling receipt or pause-deadline expiry
- Single-extension processing (AWB 4:14) with motivering validation and blocking of second extension
- Pro-active notificatie escalation at 14d, 7d, 2d thresholds and on overschrijding; separate escalation path for terminal warnings

### In Scope — Dwangsom Engine

- Ingebrekestelling registration with formeel-termijn-overschrijding validation (prevents premature registration)
- DwangsomBerekening stateful counter with daily tariff application per AWB 4:17 (€23/14d, €35/14d, €45/14d, max €1.442)
- DwangsomUitbetaling trigger generation with IBAN, bedrag, wettelijke-grondslag, betaal-referentie
- Integration with ERP via openconnector callback for payment confirmation and status update
- Bezwaar handling against dwangsom-beschikking (freeze calculation, pause uitbetaling pending resolution)

### In Scope — Notifications & Reporting

- Burger-notificaties: ontvangstbevestiging (termijn toezegging), extension notification, ingebrekestelling-ontvangstbevestiging, dwangsom-toekenning, payment confirmation
- Management rapportages: per-zaaktype KPI (% within deadline, average duration, extension count, overrun count, ingebrekestelling count, dwangsom total), per-afdeling tracking, per-behandelaar tracking
- Jaarrekening dwangsom-listing: zaak-ref, ingebrekestelling-date, beschikking-date, dwangsom-bedrag, payment-date, payment-ref

### Out of Scope

- Bestuurs-dwangmiddelen (other enforcement tools like bestraffing, intrekking) — separate domain
- Cross-zaaksysteem termijn-synchronization — single-zaaksysteem scope for initial release
- Wettelijke-rente (AWB 4:97) — separate calculation module
- Judicial appeal (bezwaarschrift against dwangsom in ARvS) — zaak-status lifecycle, not termijn-engine
- Compensation for burger-incurred costs (schadevergoeding AWB 8:73) — separate claim system

## Dependencies

- **procest base** (REQUIRED) — zaak-creation hooks, case-document linking, notification-router, event-bus, status-machine
- **openregister** (REQUIRED) — REST API for CRUD on 6 new schemas; object relations; full-text search; auditTrail capability
- **openconnector** (REQUIRED for dwangsom payment) — typified event emission to ERP systems (Coda, Centric, Civision, Unit4, AFAS, SAP); callback handling for payment confirmation
- **procest-dashboard** (optional) — termijn-KPI widget consumer
- **nldesign-portal** (optional) — notification renderer for burger-facing messages

## Acceptance Criteria

1. GIVEN a zaak is created, WHEN zaaktype has a matching TermijnDefinitie, THEN TermijnInstance is auto-created with einddatum-berekend, status=lopend, and a start-event recorded
2. GIVEN a zaak is created WHEN zaaktype has no TermijnDefinitie, THEN zaak-creation is blocked with a clear admin-facing error directing to TermijnDefinitie configuration
3. GIVEN a hersteltermijn-pauze is registered with 14-day duration, WHEN the pause ends without aanvulling response, THEN the system pro-actively notifies the handler with AWB 4:5 advice
4. GIVEN an extension is requested, WHEN it is the first extension with valid motivering, THEN the system approves and updates einddatum; WHEN it is a second extension, THEN the system rejects with AWB 4:14 reference
5. GIVEN a TermijnInstance with einddatum 13 days away, WHEN the daily termijn-scan runs, THEN the handler receives notification via Nextcloud + e-mail
6. GIVEN a TermijnInstance is overschreden, WHEN the scan runs, THEN the status is set to overschreden, an event is recorded, and the case-detail-UI displays a red warning
7. GIVEN an Ingebrekestelling is registered after the termijn is overschreden, WHEN registered, THEN DwangsomBerekening is prepared (starting 14 days post-notification)
8. GIVEN a DwangsomBerekening on day 10 of grace (6 days after 14-day grace ends), WHEN calculated, THEN dwangsom = 6 × €23 = €138
9. GIVEN a DwangsomBerekening reaches plafond (€1.442), WHEN the calculation runs, THEN further growth is blocked and status is flagged
10. GIVEN a beschikking is registered on day 20 of dwangsom accrual (8 days past grace), WHEN registered, THEN DwangsomBerekening stops with final dwangsom-bedrag, and a betaal-signaal is emitted via openconnector

