# Proposal: dso-omgevingsloket

## Summary

Integrate Procest with the DSO (Digitaal Stelsel Omgevingswet) Omgevingsloket so that municipalities can receive vergunningaanvragen from the national one-stop portal, manage the VTH (Vergunningen, Toezicht, Handhaving) case lifecycle, and push status updates and decisions back to DSO-LV. The change adds Procest-side case management on top of the DSO data layer (`vergunningaanvraag`, `activiteit`, `locatie`, `omgevingsdocument`) already specified in OpenRegister: zaak conversion of inbound verzoeken, deadline tracking against the 8-week reguliere procedure, beschikking generation, samenwerkverzoek and doorstuur handling, and status pushback through OpenConnector's DSO adapter.

## Motivation

The Omgevingswet (effective January 2024) replaced 26 laws and obliges every Dutch bevoegd gezag to handle environmental permit applications through DSO-LV. 32% of analyzed tenders explicitly require DSO/VTH capabilities; municipalities (Zoetermeer 282155, Westerkwartier 264852) specify detailed VTH010-VTH019 requirements for triggerbericht reception, verzoek ophalen, samenwerkfunctionaliteit, doorstuur, beschikking, and status pushback. Procest needs to convert DSO verzoeken into managed zaken, enforce reguliere/uitgebreide procedure deadlines, and synchronize the resulting decisions back to DSO-LV.

## Affected Projects

- [ ] Project: `procest` — VTH case-type wiring, deadline service, beschikking generation, status pushback wiring, Vue components
- [ ] Project: `openconnector` (out-of-repo) — DSO-LV adapter handles triggerbericht / verzoek ophalen / samenwerken / doorsturen (existing spec)

## Scope

### In Scope

- **Verzoek-to-zaak conversion** — Convert inbound `vergunningaanvraag` objects into Procest zaken with the `omgevingsvergunning` case type
- **VTH case type** — Define `omgevingsvergunning` zaaktype with statuses (`ingediend` → `in_behandeling` → `verleend`/`geweigerd`/`ingetrokken`) and reguliere (8 wk) / uitgebreide (26 wk) procedure variants
- **Deadline tracking** — Background job warning at 6/2 weeks remaining; auto-flag overdue cases
- **Beschikking generation** — Trigger Docudesk template generation on `verleend` / `geweigerd`, attach as bijlage on the vergunningaanvraag
- **Status pushback** — Dispatch typed event on status change so OpenConnector pushes update to DSO-LV
- **Samenwerkverzoek & doorstuur** — Procest UI to initiate, accept, reject samenwerking and to forward to another bevoegd gezag
- **VTH dashboard** — Procest view of all omgevingsvergunningen with filters on activiteitgroep / regelkwalificatie / status / locatie
- **Notifications** — New verzoek arrival, approaching-deadline warning, samenwerkverzoek received

### Out of Scope

- DSO-LV protocol implementation (mTLS, PKIoverheid, koppelvlak handlers) — owned by OpenConnector
- STTR vergunningcheck rule execution — out of scope (referenced for context)
- 3D / BIM viewer for bouwtekeningen
- Bezwaar-beroep workflow (covered by `bezwaar-beroep-workflow` spec)

## Approach

1. Add `omgevingsvergunning` case type to seed data with reguliere/uitgebreide variants and the status enum from the spec
2. Create `DsoCaseService` that converts inbound `vergunningaanvraag` to a Procest zaak, mirrors status, and computes deadlines
3. Add `DsoDeadlineJob` `TimedJob` (daily) for deadline warnings and overdue flagging
4. Create `BeschikkingGenerationService` orchestrating Docudesk template + attachment as `bijlage`
5. Dispatch typed events (`VergunningStatusChangedEvent`) for OpenConnector to listen to
6. Vue: `DsoCaseDetail.vue`, `SamenwerkverzoekDialog.vue`, `DoorstuurDialog.vue`, `VthDashboard.vue`
7. Extend `SettingsService` with `dso_*` config keys (case type, deadline thresholds, beschikking templates)

## Risks

- Status drift between OpenRegister `vergunningaanvraag` and Procest zaak must be reconciled by a single owner (Procest writes both via service)
- Deadline calculation must use working days per Omgevingswet rules (excluding national holidays)
- Samenwerkverzoek coordination involves multiple bevoegd gezag — Procest must not race OpenConnector on status writes
- Beschikking templates differ per municipality; Docudesk template selection by case type and decision outcome
