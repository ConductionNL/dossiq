# Proposal: Beschikking compose → ondertekenen → Berichtenbox → archief

## Summary

An integrated 4-app pipeline for the complete beschikking (formal decision) lifecycle within the Conduction ecosystem. Procest manages the case and the beschikking state-machine, Docudesk provides the template-engine and final PDF composition, OpenConnector handles eIDAS-qualified electronic signatures (via a certified Trust Service Provider) and Berichtenbox delivery, and OpenRegister serves as durable archival storage compliant with TMLO/MDTO metadata requirements. The state-machine enforces transitions: ontwerp → akkoord-mandaat → ondertekend → verzonden → ontvangen-bevestiging → gearchiveerd. The pipeline is generic across all decision types (omgevingsvergunningen, WMO-toekenningen, bijstandsbesluiten, dwangsommen, etc.) and fully auditab with cryptographic proof of mandated authority, signature, delivery, and receipt.

## Problem

Today, beschikking workflow is fragmented across four to five different systems with manual handover steps: a zaak-system for data, Word/PDF for layout, a separate signing service, a separate Berichtenbox integration, and a separate archive link. The process is error-prone, difficult to audit, and legally indefensible in a disputes or rechtszaak. The Awb-mandated chain of custody — who signed, when, under what mandate, and what was delivered — is not adequately captured or proven. Templates are not versioned, so wetswijzigingen (legal changes) cannot be applied cleanly. No single "audit-proof" export exists for bezwaarprocedures or rechtsgang.

## Affected Projects

- [ ] **procest** — Adds the `Beschikking` entity, state-machine, mandaat-verificatie, bezwaartermijn-trigger logic, and the REST API for composition and statusdataflow.
- [ ] **docudesk** — Extends template-engine to produce PDF/A-3 descriptions and verifies template versioning.
- [ ] **openconnector** — Adds eIDAS-TSP (Trust Service Provider) adapters for qualified signing and Berichtenbox / eHerkenning OIN delivery.
- [ ] **openregister** — Adds archiefopslag with TMLO/MDTO metadata generation and automatic verniètigingstermijn scheduling.

## Scope

### In Scope (V1)

- **Beschikking entity and data model** (REQ-BES-001, REQ-BES-005): full `Beschikking` schema with template-binding, decision-records, mandaat-tracking, and state-machine log.
- **Composition from zaakgegevens** (REQ-BES-001): template engine integration; auto-population of geadresseerde, beslissing, motivering; marking missing required fields.
- **Mandaat-verificatie** (REQ-BES-002): at the akkoord step, verify the chosen approver's mandaat-level and bedrag-limit against the geldende mandaatregeling.
- **eIDAS-gekwalificeerde elektronische handtekening** (REQ-BES-003): TSP flow via OpenConnector; durably store signature metadata and validation report.
- **Berichtenbox-aanlevering** (REQ-BES-004): route beschikkingen to MijnOverheid (burgers), eHerkenning OIN (bedrijven), or print-post (fallback).
- **State-machine with formal transitions** (REQ-BES-005): enforce ontwerp → akkoord-mandaat → ondertekend → verzonden → ontvangen-bevestiging → gearchiveerd; log every transition.
- **Bezwaartermijn-trigger** (REQ-BES-006): on verzonden, auto-set bezwaarTermijnEindDatum (6 weken per Awb 6:7) and schedule herinnering at 1 week prior.
- **Archief-overdracht met TMLO/MDTO** (REQ-BES-007): on bezwaarTermijnEindDatum expiry, consolidate beschikking to PDF/A-3, generate TMLO or MDTO metadata, and transfer to OpenRegister.
- **Immutability post-ondertekening** (REQ-BES-008): reject edits to ondertekend or later; require wijzigingsbeschikking or intrekkingsbeschikking.
- **Audit-bewijspakket** (REQ-BES-009): export ZIP with all statusovergangen, mandaatreferentie, TSP-validatierapport, verzendbewijzen, and audit trail.
- **Template versioning** (REQ-BES-010): docudesk templates are versioned with effectieve datum; beschikking always uses the template-versie active on its bekendmakingsdatum.

### Out of Scope

- **Template authoring and redaction** — handled only by juridisch-specialisten within Docudesk; not part of this change.
- **Gemeente-specific mandaat-regelingen** — set up once per gemeente via the OpenRegister admin UI; not in this change.
- **Bezwaarprocedure handling** — separate zaaktype and change; this change provides the raakvlak (REQ-BES-006).
- **Multilingual UI** — only nl initially; EN translation deferred to V2.
- **Advanced archival policy (WOO, B-category permanent retention)** — handled by OpenRegister's per-gemeente selectielijst mapping.

## Approach

1. **Data Model (Procest)**: Define `Beschikking` entity with full schema incl. geadresseerde, beslissing, motivering, rechtsmiddelenclausule, mandaatGegeven, handtekening, verzending, archief blocks. Implement StateMachineLog for transition tracking.
2. **Composition (Docudesk + Procest)**: Procest API calls Docudesk template-engine; Docudesk returns PDF/A-3 bytes + checksum. Mark missing required fields in the UI.
3. **Mandaat (Procest)**: On akkoord step, query MandaatRegeling and verify the selected akkoordgever's level + bedrag. Reject if not bevoegd.
4. **Signing (OpenConnector)**: Procest delegates to OpenConnector TSP-adapter; OpenConnector orchestrates the TSP flow (KPN, EvidosSign, etc.) and returns signed PDF + validation report.
5. **Delivery (OpenConnector)**: Procest calls OpenConnector to route beschikking to the correct Berichtenbox kanaal (MijnOverheid / eHerkenning OIN) with fallback to print.
6. **Archival (OpenRegister)**: On bezwaarTermijnEindDatum expiry, Procest triggers archival; OpenRegister generates TMLO/MDTO metadata and stores the consolidated PDF/A-3 with retention-term.
7. **Audit (Procest)**: On demand, bundle all logs, reports, and proofs into a verifiable ZIP export.

## Cross-Project Dependencies

- **Docudesk** — template-engine with PDF/A-3 output, template versioning, placeholder-substitution from zaakdata.
- **OpenConnector** — eIDAS-TSP adapters, Berichtenbox MijnOverheid + eHerkenning OIN integrations, print-fallback routing.
- **OpenRegister** — archief-opslag, TMLO/MDTO metadata-generation, automatic verniètigingstermin handling.
- **Procest Register Schema** — `procest_register.json` must include `Beschikking`, `MandaatRegeling`, `BeschikkingTemplate`, `StateMachineLog`, `BezwaarTrigger` entities.

## Stakeholders & Success Criteria

- **Behandelaar / Consulent**: Clicks "beschikking opstellen", sees prefilled form, corrects as needed, routes to akkoord, sees ondertekenaar sign, automatic Berichtenbox delivery confirmed.
- **Gemandateerd ambtenaar / B&W**: Receives akkoord-request with template preview, affirms mandate, signs with TSP credential.
- **Archivaris**: Verifies TMLO/MDTO mappings are correct, confirms automatic archival on termijn expiry.
- **Juridisch (Bezwaar/Beroep)**: Exports audit-pakket on demand; verifies eIDAS-signature and chain of custody for rechtsgang.

Success = One integrated flow from ontwerp to gearchiveerd with legal defensibility, full auditability, and zero manual transfer steps.
