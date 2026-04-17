<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Proposal: Beroep Escalation

## Summary

Pre-seed a "Beroep" case type for tracking appeals at the administrative court (bestuursrechter), and add the UI flow to escalate from a completed bezwaar case to a beroep case. Includes 9 court-proceedings status types, parent-child case linking, verweerschrift document management, ruling outcome recording, and an informational banner about hoger beroep possibilities.

## Motivation

Beroep (Awb art. 8:1) is the statutory next step after a bezwaar is decided. Without tooling support, behandelaars track beroep proceedings outside Procest in spreadsheets or separate systems, losing the link to the original bezwaar and the beslissing op bezwaar. This change closes the gap: seed the Beroep case type with 9 status types mirroring court proceedings, wire the escalation from bezwaar to beroep with parent-child linking and data pre-fill, surface the voorlopige voorziening urgency flag, manage verweerschrift documents, record ruling outcomes, and inform users about hoger beroep after a ruling is issued.

## Affected Projects

- [ ] Project: `procest` — Seed Beroep case type; add escalation UI, ruling recording, and hoger beroep awareness

## Scope

### In Scope (V1)

- **REQ-BER-001**: Beroep case type pre-seeded in `procest_register.json` (zaaktype "Beroep", P26W deadline, suspensionAllowed)
- **REQ-BER-002**: 9 status types for Beroep case type reflecting the court proceedings timeline
- **REQ-BER-003**: Escalation action on a bezwaar case (status "Beslissing op bezwaar" or "Afgehandeld") to create a linked beroep case
- **REQ-BER-004**: Beroep case pre-filled from bezwaar data (bezwaarmaker as appellant, beslissing op bezwaar as contested decision, bezwaar grounds referenced)
- **REQ-BER-005**: Voorlopige voorziening flag (`voorzieningRequested` boolean propertyDefinition) on the beroep case with urgency indicator
- **REQ-BER-006**: Upload verweerschrift as case document and trigger status transition; record ruling outcome (beroep_gegrond / beroep_ongegrond / deels_gegrond / niet_ontvankelijk)
- **REQ-BER-007**: Hoger beroep informational text displayed after court ruling is recorded

### Out of Scope

- Full hoger beroep workflow automation (non-goal per context-brief; ABRvS/CRvB proceedings not modelled)
- Automated court system integrations (no API connection to rechtbank portals)
- Cassatie proceedings
- Automatic notifications to the rechtbank

## Approach

1. **Seed data**: Add Beroep `caseType`, 9 `statusType` records, `roleType` records (Behandelaar, Appellant, Rechtbank-contactpersoon), `resultType` records for ruling outcomes, `documentType` records (Beroepschrift, Verweerschrift, Uitspraak), and `voorzieningRequested` `propertyDefinition` to `procest_register.json`. Run via the existing repair step.

2. **Backend**: Add `BeroepEscalationController` with an escalation endpoint (`POST /api/cases/{id}/escalate-to-beroep`) that creates a beroep case from a bezwaar case, sets `parentCase`, pre-fills data from the bezwaar, and links the bezwaarmaker as appellant. Add `UitspraakController` endpoint to record ruling outcome and trigger status transition.

3. **Frontend**: Add "Escaleren naar beroep" action button on bezwaar case detail (visible when bezwaar is "Beslissing op bezwaar" or "Afgehandeld"), open `BeroepEscalatieDialog.vue` with pre-filled form, show beroep case link in bezwaar activity timeline after escalation. Add `UitspraakDialog.vue` for recording court ruling. Display `HogerBeroepBanner.vue` on beroep case detail when status is "Uitspraak ontvangen" or "Afgehandeld".

4. **Voorlopige voorziening**: Add `voorzieningRequested` toggle to the `BeroepEscalatieDialog`; render urgency badge on beroep case detail when `true`.
