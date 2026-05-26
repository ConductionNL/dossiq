<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: case-management (Case Management)
     This spec extends the existing `case-management` capability. Do NOT define new entities or build new CRUD — reuse what `case-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Spec: Deelzaak Support

## Purpose

Enable hierarchical case structures in Procest where a main case (hoofdzaak) can spawn child cases (deelzaken) that follow their own workflows while remaining linked to the parent. Exposes the `parentCase` and `relatedCases` fields already present on the `case` schema and the `subCaseTypes` configuration on `caseType` through dedicated UI, store actions, and admin settings.

## Context

The `case` schema already carries `parentCase` (UUID reference to parent) and `relatedCases` (JSON-encoded array) fields. The `caseType` schema carries a `subCaseTypes` array listing which case types may be created as deelzaak. The ZGW rules service (`ZgwZrcRulesService`) already enforces zrc-013 constraints (a case cannot be its own parent; sub-cases cannot have sub-cases). No new schemas are needed — this spec covers UI and store behaviour only.

Dutch municipalities commonly use deelzaken for parallel departmental processing (e.g. an omgevingsvergunning spawning bouwtoezicht and milieuadvies sub-cases) and for bezwaar procedures where the objection triggers a linked secondary case.

## Requirements

---

### REQ-DZS-001 — Sub-case creation from parent case detail

Case workers SHALL be able to create a sub-case (deelzaak) from a parent case's detail view. The created sub-case MUST have its `parentCase` field set to the parent case's UUID and its caseType constrained to the parent case type's `subCaseTypes`.

#### Scenario REQ-DZS-001-A: Create sub-case opens filtered dialog

- GIVEN a case worker views case "Omgevingsvergunning Keizersgracht 100" (caseType: "Omgevingsvergunning", `subCaseTypes`: ["Bouwtoezicht", "Milieuadvies"])
- AND the parent case has `endDate` null (case is open)
- AND the parent case has `parentCase` null (it is a top-level case)
- WHEN the case worker clicks "Deelzaak aanmaken" in the SubCasesSection
- THEN the case creation dialog MUST open with title "Deelzaak aanmaken"
- AND the caseType dropdown MUST show only "Bouwtoezicht" and "Milieuadvies"
- AND the `parentCase` field MUST be pre-set to the parent case UUID (hidden from the user)

#### Scenario REQ-DZS-001-B: Sub-case created with correct parentCase

- GIVEN the case worker selects caseType "Bouwtoezicht" and completes the dialog
- WHEN the case creation dialog is submitted
- THEN the new case MUST be saved in OpenRegister with `parentCase` = parent case UUID
- AND the new sub-case MUST appear immediately in the SubCasesSection of the parent case

#### Scenario REQ-DZS-001-C: Button hidden when no subCaseTypes configured

- GIVEN a case worker views a case whose caseType has an empty `subCaseTypes` array
- THEN the "Deelzaak aanmaken" button MUST NOT be rendered

#### Scenario REQ-DZS-001-D: Button hidden when parent case is closed

- GIVEN a case worker views a case that has `endDate` set (case is closed)
- THEN the "Deelzaak aanmaken" button MUST NOT be rendered

#### Scenario REQ-DZS-001-E: Button hidden on existing sub-cases

- GIVEN a case worker views a case that has a non-null `parentCase` field (it is itself a deelzaak)
- THEN the "Deelzaak aanmaken" button MUST NOT be rendered
- (ZGW zrc-013c: deelzaak of a deelzaak is prohibited)

---

### REQ-DZS-002 — Sub-cases section on parent case detail

The case detail view SHALL display a "Deelzaken" section listing all cases whose `parentCase` references the current case.

#### Scenario REQ-DZS-002-A: Sub-cases displayed in compact table

- GIVEN a case worker views parent case "Omgevingsvergunning Keizersgracht 100" which has 2 sub-cases
- THEN the case detail MUST display a "Deelzaken" section
- AND the section MUST list each sub-case with: title (clickable link), status badge, behandelaar, and deadline
- AND each sub-case title link MUST navigate to the sub-case detail view

#### Scenario REQ-DZS-002-B: Empty state when no sub-cases exist

- GIVEN a parent case with caseType that has `subCaseTypes` configured
- AND the case has no sub-cases yet
- THEN the section MUST display the message "Nog geen deelzaken aangemaakt"
- AND the "Deelzaak aanmaken" button MUST be visible

#### Scenario REQ-DZS-002-C: Section hidden for cases without subCaseTypes

- GIVEN a case worker views a case whose caseType has an empty `subCaseTypes` array
- THEN the "Deelzaken" section MUST NOT be rendered in the case detail

---

### REQ-DZS-003 — Parent case breadcrumb navigation

When viewing a sub-case, the system SHALL display a breadcrumb above the case title that links back to the parent case.

#### Scenario REQ-DZS-003-A: Breadcrumb shown on sub-case detail

- GIVEN a case worker views sub-case "Bouwtoezicht Keizersgracht 100" whose `parentCase` references "Omgevingsvergunning Keizersgracht 100"
- THEN the case detail MUST display a breadcrumb: "Omgevingsvergunning Keizersgracht 100 › Bouwtoezicht Keizersgracht 100"
- AND the parent case title in the breadcrumb MUST be a clickable link to the parent case detail

#### Scenario REQ-DZS-003-B: No breadcrumb on top-level cases

- GIVEN a case worker views a case with `parentCase` equal to null
- THEN no breadcrumb MUST be displayed above the case title

---

### REQ-DZS-004 — Sub-case progress roll-up on parent case

The SubCasesSection header SHALL display a completion progress indicator showing how many sub-cases are completed (have `endDate` set) vs total.

#### Scenario REQ-DZS-004-A: Roll-up shows correct completed count

- GIVEN parent case "Omgevingsvergunning Keizersgracht 100" has 2 sub-cases
- AND 1 sub-case has `endDate` set (voltooid) and 1 has `endDate` null (open)
- WHEN a case worker views the parent case detail
- THEN the SubCasesSection header MUST display "Deelzaken (1/2 voltooid)"

#### Scenario REQ-DZS-004-B: Roll-up with all sub-cases open

- GIVEN a parent case with 3 sub-cases, none of which have `endDate` set
- THEN the header MUST display "Deelzaken (0/3 voltooid)"

#### Scenario REQ-DZS-004-C: Roll-up with all sub-cases completed

- GIVEN a parent case with 2 sub-cases, both with `endDate` set
- THEN the header MUST display "Deelzaken (2/2 voltooid)"

---

### REQ-DZS-005 — Sub-case count badge in case list

The case list view SHALL display a badge showing the number of sub-cases for cases that have one or more sub-cases.

#### Scenario REQ-DZS-005-A: Badge displayed for cases with sub-cases

- GIVEN the case list includes "Omgevingsvergunning Keizersgracht 100" with 2 sub-cases
- THEN that case row MUST display a badge or indicator reading "2 deelzaken"

#### Scenario REQ-DZS-005-B: No badge for cases without sub-cases

- GIVEN the case list includes "Klacht behandeling" with 0 sub-cases
- THEN that case row MUST NOT display a sub-case badge

#### Scenario REQ-DZS-005-C: Sub-case counts batch-loaded per page

- GIVEN the case list shows 25 cases on a page
- WHEN the page loads
- THEN sub-case counts MUST be fetched in a single batch query (not 25 individual queries)
- AND the badges MUST update once the batch query resolves

---

### REQ-DZS-006 — Sub-case deletion protection

When a user deletes a case that has sub-cases, the system SHALL warn the user and perform orphan cleanup before deletion.

#### Scenario REQ-DZS-006-A: Deletion warning shown for parent case with sub-cases

- GIVEN case worker attempts to delete "Omgevingsvergunning Keizersgracht 100" which has 2 sub-cases
- THEN the system MUST display a confirmation dialog:
  "Deze zaak heeft 2 deelzaken. Door te verwijderen worden de deelzaken losgekoppeld van hun hoofdzaak. Wilt u doorgaan?"
- AND the dialog MUST show "Annuleren" and "Verwijderen" buttons

#### Scenario REQ-DZS-006-B: Orphan cleanup performed on confirmed deletion

- GIVEN the case worker confirms deletion of the parent case
- THEN the system MUST set `parentCase` to null on all sub-cases before deleting the parent
- AND the parent case MUST be deleted after orphan cleanup completes
- AND each affected sub-case MUST continue to exist as a standalone case

#### Scenario REQ-DZS-006-C: Standard deletion dialog for cases without sub-cases

- GIVEN a case worker deletes a case that has no sub-cases
- THEN the standard deletion confirmation dialog MUST be shown
- AND no sub-case warning MUST appear

---

### REQ-DZS-007 — Sub-case type configuration in admin settings

Administrators SHALL be able to configure which caseTypes are allowed as deelzaak for a given caseType, via the admin settings case type detail view.

#### Scenario REQ-DZS-007-A: Admin configures allowed sub-case types

- GIVEN an administrator edits caseType "Omgevingsvergunning" in the admin settings
- THEN a "Deelzaaktypen" tab MUST be present in the caseType detail view
- AND the tab MUST list all available case types with checkboxes or multi-select
- AND the administrator MUST be able to select zero or more case types as allowed deelzaaktypen
- AND saving MUST persist the selection to the `subCaseTypes` array on the caseType object

#### Scenario REQ-DZS-007-B: Removing a sub-case type does not affect existing sub-cases

- GIVEN caseType "Omgevingsvergunning" has "Milieuadvies" in its `subCaseTypes`
- AND there are existing cases of type "Milieuadvies" with `parentCase` set to an "Omgevingsvergunning" case
- WHEN the administrator removes "Milieuadvies" from the allowed sub-case types and saves
- THEN the existing sub-cases MUST NOT be affected (their `parentCase` links are preserved)
- AND new sub-case creation for "Milieuadvies" under "Omgevingsvergunning" MUST no longer be possible

---

### REQ-DZS-008 — ZGW API compatibility for hoofdzaak and deelzaken

The ZGW zaak REST API responses for cases MUST include `hoofdzaak` and `deelzaken` fields consistent with the ZGW ZRC standard.

#### Scenario REQ-DZS-008-A: Sub-case ZGW response includes hoofdzaak

- GIVEN case "Bouwtoezicht Keizersgracht 100" has `parentCase` = UUID of "Omgevingsvergunning Keizersgracht 100"
- WHEN a client requests `GET /api/zgw/zaken/v1/zaken/{sub-case-uuid}`
- THEN the response MUST include `"hoofdzaak": "/api/zgw/zaken/v1/zaken/{parent-uuid}"`

#### Scenario REQ-DZS-008-B: Parent case ZGW response includes deelzaken list

- GIVEN case "Omgevingsvergunning Keizersgracht 100" has 2 sub-cases
- WHEN a client requests `GET /api/zgw/zaken/v1/zaken/{parent-uuid}`
- THEN the response MUST include a `"deelzaken"` array with the URLs of both sub-cases

## Dependencies

- `openspec/specs/case-management` — Core case model, CaseDetail.vue, CaseList.vue, CaseCreateDialog.vue
- `openspec/specs/case-types` — caseType admin settings, CaseTypeDetail.vue
- OpenRegister filter API — `?parentCase={uuid}` and `parentCase IN [...]` batch queries
- ZGW ZRC rules service — zrc-013 validation already in place; UI must mirror these constraints
