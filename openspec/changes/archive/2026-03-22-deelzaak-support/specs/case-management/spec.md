## MODIFIED Requirements

### REQ-CM-01: Case Creation

**Feature tier**: MVP (base), V1 (sub-case creation)

The system MUST support creating new cases. Each case MUST be linked to a published, valid case type. The case type controls initial defaults and behavioral constraints. When creating a sub-case, the `parentCase` field MUST be set and the case type MUST be restricted to the parent case type's `subCaseTypes`.

#### Scenario CM-01a: Create a case with case type selection

- GIVEN a user with case management access
- AND a published case type "Omgevingsvergunning" with `processingDeadline = "P56D"`, `confidentiality = "internal"`, and status types ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- WHEN the user opens the "New Case" form and selects case type "Omgevingsvergunning"
- AND enters title "Bouwvergunning Keizersgracht 100"
- AND submits the form
- THEN the system MUST create an OpenRegister object in the `procest` register with the `case` schema
- AND the `identifier` MUST be auto-generated (format: `YYYY-NNN`, e.g., "2026-042")
- AND the `startDate` MUST default to the current date
- AND the `deadline` MUST be auto-calculated as `startDate + P56D` (e.g., 2026-01-15 + 56 days = 2026-03-12)
- AND the `confidentiality` MUST default to "internal" (inherited from case type)
- AND the `status` MUST be set to "Ontvangen" (the first status type by `order`)
- AND a unique `identifier` MUST be auto-generated

#### Scenario CM-01f: Create sub-case with parentCase context

- GIVEN a user viewing case "Omgevingsvergunning Keizersgracht 100" (UUID: `abc-123`)
- AND the case type "Omgevingsvergunning" has `subCaseTypes` containing "Bouwvergunning"
- WHEN the user clicks "Create Sub-case" and selects case type "Bouwvergunning"
- AND enters title "Bouwtoets Keizersgracht 100"
- AND submits the form
- THEN the system MUST create a case with `parentCase = "abc-123"`
- AND all other case creation rules (identifier, startDate, deadline, status) MUST apply normally

### REQ-CM-03: Case Detail View

**Feature tier**: MVP (base), V1 (sub-cases section, breadcrumb)

The case detail view MUST show all case information, status timeline, participants, tasks, and activity. For parent cases, it MUST also show a sub-cases section. For sub-cases, it MUST show a breadcrumb link to the parent case.

#### Scenario CM-03-subcases: Case detail shows sub-cases section

- GIVEN a case "Omgevingsvergunning Keizersgracht 100" with 3 sub-cases
- AND the case type has `subCaseTypes` configured
- WHEN the user opens the case detail
- THEN the case detail MUST include a "Sub-cases" section after the existing sections
- AND the section MUST list sub-cases with title, status, assignee, and deadline columns

#### Scenario CM-03-breadcrumb: Sub-case detail shows parent breadcrumb

- GIVEN sub-case "Bouwtoets Keizersgracht 100" with `parentCase` pointing to "Omgevingsvergunning Keizersgracht 100"
- WHEN the user opens the sub-case detail
- THEN a breadcrumb MUST be displayed above the case title linking to the parent case

### REQ-CM-02: Case List View

**Feature tier**: MVP (base), V1 (sub-case count)

The case list MUST display all cases with filtering, sorting, and pagination. Cases with sub-cases MUST display a sub-case count indicator.

#### Scenario CM-02-subcase-badge: Case list shows sub-case count

- GIVEN the case list contains case "Omgevingsvergunning Keizersgracht 100" with 3 sub-cases
- WHEN the user views the case list
- THEN the case row MUST display a sub-case count badge showing "3"
