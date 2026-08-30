# Proposal: vth-module

## Why

VTH (Vergunningen, Toezicht, Handhaving) is the single highest-value functional domain for Dutch municipalities adopting Procest. 29% of analysed VTH tenders require an integrated permits/supervision/enforcement module, and stock case-management apps do not cover the cross-domain inspection and sanction workflows that a VTH-volwaardige module needs. This change introduces the V1 tier of the Procest VTH module: a DSO/Omgevingsloket intake service stub, configurable inspection checklists, advice management workflow, and the LHS (Landelijke Handhavingsstrategie) enforcement matrix as data, so downstream changes (mobiel-inspectie, legesberekening) can hook into a real VTH zaaktype stack.

## What Changes

1. VTH case-type templates: omgevingsvergunning, toezichtzaak, handhavingszaak with full GEMMA-compatible status/property/document/role configuration.
2. Inspection checklist schemas and configuration/completion UI hooks consumable by mobiel-inspectie.
3. DSO intake service stub mapping inbound vergunningaanvragen to Procest cases.
4. Advice management service for requesting and tracking specialist advice (interne/externe adviseur).
5. LHS enforcement matrix data and lookup utility (V2 foundation).
6. New OpenRegister schemas in `procest_register.json`: `inspectionChecklist`, `checklistItem`, `inspectionResult`, `adviceRequest`, `lhsMatrixCell`.

## Impact

- **Affected projects**: procest (primary), openconnector (DSO connector), openregister (schemas).
- **Code surface**: new service classes, Vue admin and detail components, register schemas, route additions, repair step for template seeding.
- **Dependencies**: OpenConnector (DSO), OpenRegister, Docudesk (optional for besluit redaction), mobiel-inspectie (consumer), legesberekening (consumer).
- **Standards**: GEMMA VTH-referentiecomponenten (VTH001-VTH119), Omgevingswet/DSO STAM 2.0, LHS 1.7.
