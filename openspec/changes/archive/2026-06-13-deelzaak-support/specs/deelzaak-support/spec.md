<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Spec: Deelzaak Support

## MODIFIED Requirements

### Requirement: Sub-case count in case list

The case list view SHALL display a sub-case count badge for cases that have one or more sub-cases. Cases with zero sub-cases MUST NOT show a badge. The counts MUST be loaded in a single batch query per list page, not one request per row. Procest's case list is manifest-driven (CnIndexPage), so the badge is implemented as a column with a `subCaseCount` formatter backed by the deelzaak store; sub-cases themselves (cases with a non-null `parentCase`) never show a badge.

#### Scenario: Case list shows sub-case count

- **WHEN** user views the case list containing case "Omgevingsvergunning Keizersgracht 100" with 3 sub-cases
- **THEN** the case row MUST display a badge or indicator showing "3 deelzaken"

#### Scenario: Case without sub-cases has no badge

- **WHEN** user views the case list containing case "Klacht behandeling" with 0 sub-cases
- **THEN** the case row MUST NOT display a sub-case badge

#### Scenario: Sub-case counts batch-loaded per page

- **WHEN** the case list renders a page of cases
- **THEN** the sub-case counts MUST be fetched in a single `/api/deelzaken/counts` request, not one request per row
- **AND** the badges MUST update once the batch query resolves

### Requirement: Sub-case deletion protection

When a user attempts to delete a parent case that has sub-cases, the system SHALL warn the user and require confirmation. The system MUST clear the `parentCase` field on all child cases before proceeding with deletion (orphan cleanup), so the former sub-cases remain accessible as standalone cases. A case with no sub-cases MUST take the standard deletion confirmation without a sub-case warning.

#### Scenario: Delete parent case with sub-cases shows warning

- **WHEN** user attempts to delete case "Omgevingsvergunning Keizersgracht 100" which has 2 sub-cases
- **THEN** the system MUST display a confirmation dialog warning that deleting will unlink the 2 sub-cases from their parent
- **AND** if the user confirms, the system MUST set `parentCase` to null on all sub-cases before deleting the parent
- **AND** each former sub-case MUST remain accessible as a standalone case

#### Scenario: Delete case without sub-cases proceeds normally

- **WHEN** user attempts to delete a case that has no sub-cases
- **THEN** the standard deletion confirmation MUST be shown without a sub-case warning
