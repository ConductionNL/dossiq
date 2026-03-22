# Case Management Specification

## Problem
Case management is the core capability of Procest. A case represents a coherent body of work with a defined lifecycle, initiation, and result. Cases are governed by configurable **case types** that control behavior: allowed statuses, required fields, processing deadlines, retention rules, and more. Cases follow CMMN 1.1 concepts (CasePlanModel) and are semantically typed as `schema:Project`.
**Standards**: CMMN 1.1 (CasePlanModel), Schema.org (`Project`), ZGW (`Zaak`)
**Feature tier**: MVP (core case CRUD, list, detail, status, deadline), V1 (sub-cases, confidentiality, result types, document checklist, suspension)

## Proposed Solution
Implement Case Management Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the case-management specification.

## Success Criteria
#### Scenario CM-01a: Create a case with case type selection
#### Scenario CM-01b: Case type is required at creation
#### Scenario CM-01c: Title is required at creation
#### Scenario CM-01d: Cannot create case with draft case type
#### Scenario CM-01e: Cannot create case with expired case type
