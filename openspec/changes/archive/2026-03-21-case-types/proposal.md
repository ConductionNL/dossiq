# Case Type System Specification

## Problem
Case types are configurable definitions that control the behavior of cases. A case type determines which statuses are allowed, what roles can be assigned, which custom fields are required, processing deadlines, confidentiality defaults, and archival rules. This is the international equivalent of ZGW's `ZaakType`, modeled after CMMN 1.1 `CaseDefinition` concepts.
Case types form a hierarchy where the CaseType is the central configuration entity:
```
CaseType
├── StatusType[]         — Allowed lifecycle phases (ordered)
├── ResultType[]         — Allowed outcomes (with archival rules)
├── RoleType[]           — Allowed participant roles
├── PropertyDefinition[] — Required custom data fields
├── DocumentType[]       — Required document types
├── DecisionType[]       — Allowed decision types

## Proposed Solution
Implement Case Type System Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the case-types specification.

## Success Criteria
#### Scenario CT-01a: Create a case type
#### Scenario CT-01b: Update a case type
#### Scenario CT-01c: Delete a case type with no active cases
#### Scenario CT-01d: Delete a case type with active cases -- blocked
#### Scenario CT-01e: Case type list display
