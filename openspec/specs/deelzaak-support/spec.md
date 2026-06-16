## Purpose

Provide deelzaak (sub-case) support in Procest: creating sub-cases from a parent case, listing them on the parent detail, parent breadcrumb navigation, progress roll-up, case-list count badges, and deletion protection. Maps to the ZGW `hoofdzaak` / `deelzaken` relations on the Zaak resource.

The deelzaak UI panel, sub-case create flow, parent breadcrumb, count badge, and orphan-deletion protection are all built (manifest Sub-cases tab → DeelzaakList/DeelzaakDetail, the `subCaseCount` column formatter, and DeelzaakDeleteWarningModal). UI scenarios are exercised by `tests/e2e/spec-coverage/deelzaak-support.spec.ts`; the ZGW `hoofdzaak`/`deelzaken` API response shape is verified at the integration tier (Newman ZGW collection), not via Playwright.

## Requirements

### Requirement: Sub-case creation from parent case

The system SHALL allow users to create a sub-case (deelzaak) from a parent case's detail view.

**Feature tier**: V1 The sub-case MUST have its `parentCase` field set to the parent case's UUID. The case type selection MUST be restricted to types listed in the parent case type's `subCaseTypes` array. Maps to ZGW `hoofdzaak` field on the Zaak resource.

#### Scenario: Create sub-case from parent case detail

- **WHEN** user views case "Omgevingsvergunning Keizersgracht 100" which has case type "Omgevingsvergunning" with `subCaseTypes` containing ["Bouwvergunning", "Milieuvergunning"]
- **AND** user clicks "Create Sub-case"
- **THEN** the system MUST open the case creation dialog with title "Create Sub-case"
- **AND** the case type dropdown MUST only show "Bouwvergunning" and "Milieuvergunning"
- **AND** on submit, the created case MUST have `parentCase` set to the parent case's UUID

#### Scenario: Sub-case creation blocked when parent has no sub-case types

- **WHEN** user views a case whose case type has an empty `subCaseTypes` array
- **THEN** the "Create Sub-case" button MUST NOT be visible

#### Scenario: Sub-case creation blocked when parent case is closed

- **WHEN** user views a case that has an `endDate` set (case is closed)
- **THEN** the "Create Sub-case" button MUST NOT be visible

#### Scenario: Sub-case of sub-case is prohibited

- **WHEN** user views a case that has a non-null `parentCase` (it is itself a sub-case)
- **THEN** the "Create Sub-case" button MUST NOT be visible

### Requirement: Sub-cases section on parent case detail

The case detail view SHALL display a "Sub-cases" section

**Feature tier**: V1 listing all cases whose `parentCase` references the current case. The section MUST show each sub-case's title, status, assignee, and deadline. Maps to ZGW `deelzaken` on the Zaak resource.

#### Scenario: Parent case shows sub-cases list

- **WHEN** user views case "Omgevingsvergunning Keizersgracht 100" which has 3 sub-cases
- **THEN** the case detail MUST display a "Sub-cases" section
- **AND** the section MUST list all 3 sub-cases with title, current status, assignee, and deadline
- **AND** each sub-case title MUST be a clickable link navigating to the sub-case detail

#### Scenario: Parent case with no sub-cases shows empty state

- **WHEN** user views a case that has no sub-cases but whose case type has `subCaseTypes` configured
- **THEN** the "Sub-cases" section MUST display an empty state message: "No sub-cases yet"
- **AND** the "Create Sub-case" button MUST be visible

#### Scenario: Case without sub-case type support hides section

- **WHEN** user views a case whose case type has an empty `subCaseTypes` array
- **THEN** the "Sub-cases" section MUST NOT be rendered

### Requirement: Parent case breadcrumb navigation

When viewing a sub-case (a case with a non-null `parentCase`), the system SHALL display a breadcrumb

**Feature tier**: V1 above the case title linking back to the parent case. The breadcrumb MUST show the parent case's title.

#### Scenario: Sub-case shows parent breadcrumb

- **WHEN** user views sub-case "Bouwvergunning Keizersgracht 100" which has `parentCase` pointing to case "Omgevingsvergunning Keizersgracht 100"
- **THEN** the case detail MUST display a breadcrumb: "Omgevingsvergunning Keizersgracht 100 > Bouwvergunning Keizersgracht 100"
- **AND** the parent case name in the breadcrumb MUST be a clickable link navigating to the parent case detail

#### Scenario: Top-level case has no breadcrumb

- **WHEN** user views a case with `parentCase` equal to null
- **THEN** no parent breadcrumb MUST be displayed

### Requirement: Sub-case progress roll-up on parent case

The parent case detail SHALL display a progress indicator

**Feature tier**: V1 summarizing sub-case completion status. The indicator MUST show the count of completed sub-cases vs total (e.g., "3/5 completed").

#### Scenario: Roll-up shows completion progress

- **WHEN** user views a parent case with 5 sub-cases, 3 of which have an `endDate` set
- **THEN** the sub-cases section header MUST display "Sub-cases (3/5 completed)"

#### Scenario: Roll-up with no completed sub-cases

- **WHEN** user views a parent case with 2 sub-cases, none of which have an `endDate` set
- **THEN** the sub-cases section header MUST display "Sub-cases (0/2 completed)"

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

