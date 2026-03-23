# Zaak Intake Flow Specification

## Problem
The zaak intake flow governs what happens after a case is initiated -- whether from Open Formulieren, DSO/Omgevingsloket, manual entry, or API call. It handles automatic zaaktype assignment, status initialization, initial task creation, notification to the assigned behandelaar, and linking of the initiator. This is the bridge between external input and the internal case lifecycle.
**Tender demand**: 61% of tenders (42/69) require formulieren/intake capabilities. Automatic case creation from external submissions is a baseline expectation.
**Standards**: ZGW Zaken API (`zaak-create`), StUF-ZKN (`creeerZaak_Lk01`), CMMN 1.1 (CasePlanModel instantiation)
**Feature tier**: MVP (manual + API intake, zaaktype assignment, status init, behandelaar notification), V1 (Open Formulieren integration, DSO intake, duplicate detection, batch intake, e-mail intake)

## Proposed Solution
Implement Zaak Intake Flow Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the zaak-intake-flow specification.

## Success Criteria
#### Scenario INTAKE-01a: Create case via dialog
#### Scenario INTAKE-01b: Case type selection shows metadata
#### Scenario INTAKE-01c: Manual case submission succeeds
#### Scenario INTAKE-01d: Manual case with optional description
#### Scenario INTAKE-01e: Cancel case creation
