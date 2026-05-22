# Proposal: subsidieverlening-keten

## Summary

Implement end-to-end subsidy-grant lifecycle management for Dutch government bodies (gemeenten, provincies, ministeries, agentschappen, fondsen) under the Algemene wet bestuursrecht (AWB) titel 4.2. Subsidies fundamentally differ from permits: they commit public money over multi-year horizons (often 2–5 years), require periodic substantiation via tussenrapportages, support advance disbursement (voorschotten) followed by final settlement (vaststelling), and may result in clawback (terugvordering) if conditions aren't met. Procest currently treats subsidies as generic cases, losing multi-year visibility, deadline tracking, and substantiation workflows. This capability introduces dedicated schemas for subsidieregeling, subsidieaanvraag, subsidiebeschikking, subsidieuitvoering, tussenrapportage, subsidievaststelling, and terugvordering, with AWB termijn integration, bewijsstukken (evidence documents) lifecycle management, and a Wet open overheid-compliant subsidieregister feed.

## Motivation

Dutch government budgets for subsidies total €50+ billion annually across thousands of regelingen (regulations). Current zaaksysteem implementations lose sight of multi-year execution: finance, jurists, and policy teams track subsidies in parallel spreadsheets, missing AWB termijnen for interim reports and final settlements, failing to match substantiation against conditions, and unable to produce the mandatory openbaar subsidieregister. Sector-specific regelingen (ASV gemeenten, Kaderwet OCW, ZonMW, NWO, etc.) layer their own termijnen, rapportage cycles, and accountantsverklaringen on top of AWB 4.2 base rules, requiring a flexible and extensible model. This change provides a coherent, auditable, and regulation-compliant subsidy lifecycle engine that integrates with the shared termijnbewaking (deadline management), financial back-office (ERP via OpenConnector), and documentation systems (Docudesk).

## What Changes

1. Nine new OpenRegister schemas: `SubsidieRegeling`, `SubsidieAanvraag`, `SubsidieBeoordeling`, `SubsidieBeschikking`, `SubsidieUitvoering`, `Tussenrapportage`, `SubsidieVaststelling`, `Terugvordering`, `Bewijsstuk`.
2. `SubsidieAanvraagList.vue`, `SubsidieAanvraagDetail.vue`, `SubsidieBeschikkingForm.vue`, `TussenrapportageDetail.vue`, `VaststellingForm.vue`, and `SubsidieRegisterDashboard.vue` Vue components.
3. `SubsidieService`, `TussenrapportageService`, `VaststellingService`, `TerugvorderingService`, `BewijsstukService` with CRUD, multi-year budget tracking, voorschot scheduling, verplichting (condition) tracking, and integration with the shared termijnbewaking engine.
4. Subsidieregister feed (`/api/subsidies/register`) outputting JSON per Wet open overheid and VNG standards for publication on gemeentewebsites.
5. Notifications for AWB termijn reminders, tussenrapportage prompts, and terugvordering inning reminders.
6. AGVV/EU staatssteun classification helpers for de-minimis and AGVV subsidies with automatic TAM-melding (via OpenConnector).
7. Cofinanciering tracking and validation for multi-party funded projects.

## Impact

- New schemas, new module, seven new Vue components, backend services for 5 core workflows (aanvraag, beschikking, tussenrapportage, vaststelling, terugvordering).
- Integrates with procest base (zaak-engine, behandelaar-model, termijnbewaking-engine, document-store, notification-router), openregister (schema-registratie, event-bus), termijnbewaking-dwangsom-engine (AWB termijnen, ingebrekestelling), docudesk (bewijsstukken-archief), and openconnector (ERP integration, AGVV-melding).
- Reuses existing case infrastructure (status types, roles, document attachments, activity timeline) for the lifecycle.

## Out of Scope

- Bezwaarschriften (formal objections — handled by separate procest-bezwaar capability).
- Internal compliance audits (audit trail is built in; external audit workflows deferred).
- AI/NLP-based automatic criteria-matching for subsidy eligibility (complex regulation interpretation deferred).
- Citizen-facing grant portal (portal layer handled separately; backend APIs provided).
- Custom sector-specific regelingen UIs (base schemas support extensibility; sector-specific layouts deferred).

## Affected Projects

- [ ] Project: `procest` — Backend subsidy services, controllers, schemas, and Vue components for subsidy case UI

## Standards & Sources

Algemene wet bestuursrecht (AWB) titel 4.2 (subsidies), Kaderwet subsidies (sector-specific), Comptabiliteitswet 2016, Financiële-verhoudingswet, VNG-modelverordening Algemene Subsidieverordening (ASV), Aanwijzingen voor subsidieverstrekking (Rijksdienst), AGVV (Algemene Groepsvrijstellingsverordening 651/2014), de-minimisverordening (1407/2013, €300.000 drempel per 3 jaar), Wet open overheid artikel 3.3 lid 2 onder f, VNG-richtlijn subsidieregister, Selectielijst gemeenten (archivering), Archiefwet 1995.
