> SUPERSEDED 2026-06-02 (ADR-032): decomposed into vth-workflow-configuration-01..11

# Proposal: vth-workflow-configuration

## Summary

Configure the Procest workflow engine (from `workflow-engine-enhancement`) with VTH-specific workflows, zaaktype definitions, and process automation rules. This change defines the workflow templates and configuration for Vergunningverlening, Toezicht, and Handhaving workflows, including leges calculation rules, mobile inspection workflows, and DSO integration hooks—without duplicating generic workflow functionality already provided by the engine.

## Why

The workflow-engine-enhancement provides a generic, configurable workflow orchestration system. VTH (Vergunningen, Toezicht, Handhaving) is the highest-value functional domain for Dutch municipalities (2,888 requirements across 436+ tenders), with 1,134 requirements for DSO (Digitaal Stelsel Omgevingswet) integration and 767 for VTH aktiviteitenbeheer. This change packages domain-specific workflow configurations (zaaktype templates with status transitions, role assignments, business rules) that consume the generic engine without forking it.

## What Changes

1. **VTH zaaktype templates** — Pre-configured zaaktype definitions for Omgevingsvergunning, Toezichtzaak, and Handhavingszaak with GEMMA-aligned statuses, roles, and document requirements
2. **VTH workflow definitions** — Process step sequences, status transitions, guards, and automatic actions for each zaaktype (intake → beoordeling → beschikking → verzending)
3. **Leges calculation rule engine** — Rules engine for fee calculation by zaaktype and activiteit, with support for verrekening, teruggaaf, and navordering
4. **Mobile inspection workflow** — Responsive workflow steps for inspectors in the field with checklist-based completion, photo capture, and GPS tagging
5. **DSO integration hooks** — Workflow triggers for DSO verzoek reception, status pushback, and samenwerkverzoek handling
6. **LHSO classification support** — Enforcement workflow classification per Landelijke Handhavingsstrategie for handhavingszaken
7. **Beschikking generation automation** — Template-based automation for permit and decision document generation on workflow completion

## Impact

- **Affected projects**: procest (primary), openregister (register templates), openconnector (DSO hooks), mobiel-inspectie (mobile inspection consumer), legesberekening (leges calculation consumer)
- **Code surface**: workflow configuration files (JSON templates), VTH-specific service classes for leges calculation and beschikking generation, repair step for template seeding, route additions for workflow management APIs
- **Dependencies**: REQUIRED: `workflow-engine-enhancement` must be deployed first; optional: mobiel-inspectie, legesberekening, DSO integration
- **Standards**: GEMMA VTH-referentiecomponenten, Omgevingswet/DSO STAM 2.0, Landelijke Handhavingsstrategie (LHS) 1.7

## Scope

### In Scope — Configuration (using generic workflow engine)

- VTH zaaktypen templates with pre-configured statuses, properties, document types, role definitions
- VTH process steps (intake, beoordeling, advies, beschikking, bekendmaking)
- Status transitions with guards (required fields, document checks, role-based approvals)
- VTH role definitions (Vergunningverlener, Juridisch adviseur, Inspector, Handhaver)
- Checklist templates per process step (completeness check, BIBOB check, external advies coordination)

### In Scope — VTH-Specific Extensions Beyond Generic Engine

- **Leges calculation module** — Rule-based fee calculation engine configurable per zaaktype/activiteit with rates, exemptions, verrekening (offsetting prior fees), teruggaaf (refunds), navordering (additional billing)
- **Mobile inspection view** — Responsive mobile workflow view for inspectors with checklist completion, photo upload, GPS location capture
- **DSO integration hooks** — Receive DSO verzoeken, auto-create cases, push status updates to DSO-LV
- **LHSO classification** — Enforcement classification matrix (4x4: gedrag × gevolgen) with suggested handhaving trajectory
- **Beschikking generation** — Template-based generation of permits and decisions with case data merge fields (applicant, location, activities, conditions)
- **Activiteit-object-subject linking** — Register permits/decisions as structured data linked to activity, location (BAG object), and subject (applicant)

### Out of Scope

- Generic workflow engine functionality (covered by `workflow-engine-enhancement`)
- GIS/map integration for locations (covered by `gis-integration`)
- General signalering/notifications (covered by `signalering-widgets`)
- Bezwaar/beroep workflows (covered by `bezwaar-beroep-workflow`)
- Advanced parafering routes for college approval (covered by existing change)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED) — This change configures the engine; cannot proceed without it
- **Existing procest specs** — openspec/specs/vth-module/, openspec/specs/legesberekening/, openspec/specs/mobiel-inspectie/, openspec/specs/dso-omgevingsloket/
- **OpenRegister** — VTH zaaktypen and workflow templates stored as register schemas
- **OpenConnector** — DSO integration for verzoek reception and status pushback
- **Nextcloud Calendar** — Optional: inspection planning linked to calendar (future integration)

## Acceptance Criteria

1. GIVEN the workflow engine is deployed, WHEN an administrator loads the VTH workflow configuration, THEN zaaktypen for Omgevingsvergunning, Toezichtzaak, and Handhavingszaak are available with pre-configured process steps and status transitions
2. GIVEN an Omgevingsvergunning case, WHEN the case reaches the leges calculation step, THEN the system automatically calculates fees based on configured rates and activity characteristics
3. GIVEN a leges calculation with verrekening rules, WHEN the administrator configures the rules, THEN previously imposed leges are automatically deducted from the new calculation
4. GIVEN an inspector in the field, WHEN they open a toezichtzaak on a mobile device, THEN they see a responsive inspection checklist with photo upload and GPS tagging
5. GIVEN a DSO integration endpoint, WHEN a verzoek arrives from the Omgevingswet digital system, THEN a new case is automatically created with correct zaaktype and pre-filled data
6. GIVEN an enforcement case, WHEN the handler classifies using LHSO, THEN the system suggests the appropriate handhaving trajectory
7. GIVEN a beschikking step, WHEN the handler generates the permit document, THEN case data (applicant, location, activities, conditions) is merged into the template
8. GIVEN a granted vergunning, WHEN a user searches by activity, location, or subject, THEN the permit is findable as structured data
