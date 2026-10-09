---
status: done
---

# Roles & Decisions Specification

## Purpose

Roles define the relationship between participants (Nextcloud users or external contacts) and cases -- who is involved and in what capacity. Results record the formal outcome of a completed case, linking to a predefined result type that controls archival rules. Decisions are formal administrative choices made on cases, with legal validity periods and publication requirements.

Together, these three entities govern participation, outcomes, and formal decision-making within the case lifecycle.

**Standards**: Schema.org (`Role`, `ChooseAction`), CMMN (case outcomes, case participants), ZGW (`Rol`, `Resultaat`, `Besluit`, `RolType`, `ResultaatType`, `BesluitType`)
**Primary feature tier**: MVP (roles, results), V1 (decisions, role types, result types, decision types)

---

## Data Model

### Role Entity

Stored as an OpenRegister object in the `dossiq` register under the `role` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `name` | string (max 255) | `schema:roleName` / `omschrijving` | Yes | — |
| `description` | string | `schema:description` / `roltoelichting` | No | — |
| `roleType` | reference (UUID to RoleType) | — / `omschrijvingGeneriek` (via RoleType) | Yes | — |
| `case` | reference (UUID to Case) | — / `zaak` | Yes | — |
| `participant` | string (Nextcloud user UID or contact reference) | `schema:agent` / `betrokkene` | Yes | — |

### Role Type Entity

Stored as an OpenRegister object in the `dossiq` register under the `roleType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `caseType` | reference (UUID to CaseType) | `zaaktype` | Yes |
| `genericRole` | enum | `omschrijvingGeneriek` | Yes |

### Standard Generic Roles

These are the fixed set of generic role categories, derived from ZGW but internationally applicable.

| Generic Role | ZGW Dutch | Description | Typical Use |
|-------------|-----------|-------------|-------------|
| `initiator` | Initiator | Started the case | Citizen/applicant who submitted the request |
| `handler` | Behandelaar | Processes the case | Civil servant assigned to handle the case |
| `advisor` | Adviseur | Provides advice | Technical or legal advisor consulted |
| `decision_maker` | Beslisser | Makes decisions | Authority who signs off on decisions |
| `stakeholder` | Belanghebbende | Has interest in outcome | Neighbor, affected party |
| `coordinator` | Zaakcoordinator | Coordinates the case | Team lead overseeing case progress |
| `contact` | Klantcontacter | Contact person | Front-desk agent, customer contact |
| `co_initiator` | Mede-initiator | Co-initiator | Joint applicant or co-requester |

### Result Entity

Stored as an OpenRegister object in the `dossiq` register under the `result` schema.

| Property | Type | Source | Required |
|----------|------|--------|----------|
| `name` | string (max 255) | `schema:name` | Yes |
| `description` | string | `schema:description` | No |
| `case` | reference (UUID to Case) | Parent case | Yes |
| `resultType` | reference (UUID to ResultType) | ResultType definition | Yes |

### Result Type Entity

Stored as an OpenRegister object in the `dossiq` register under the `resultType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `description` | string | `toelichting` | No |
| `caseType` | reference (UUID to CaseType) | `zaaktype` | Yes |
| `archiveAction` | enum: `retain`, `destroy` | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601, e.g., "P20Y") | `archiefactietermijn` | No |
| `retentionDateSource` | enum | `afleidingswijze` | No |

### Decision Entity

Stored as an OpenRegister object in the `dossiq` register under the `decision` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `title` | string (max 255) | `schema:name` | Yes | — |
| `description` | string | `schema:description` / `toelichting` | No | — |
| `case` | reference (UUID to Case) | — / `zaak` | Yes | — |
| `decisionType` | reference (UUID to DecisionType) | — / `besluittype` | No | — |
| `decidedBy` | string (Nextcloud user UID) | `schema:agent` | No | — |
| `decidedAt` | datetime (ISO 8601) | `schema:endTime` / `datum` | No | current timestamp |
| `effectiveDate` | date (ISO 8601) | `schema:startTime` / `ingangsdatum` | No | — |
| `expiryDate` | date (ISO 8601) | `schema:endTime` / `vervaldatum` | No | — |

### Decision Type Entity

Stored as an OpenRegister object in the `dossiq` register under the `decisionType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `description` | string | `toelichting` | No |
| `category` | string | `besluitcategorie` | No |
| `objectionPeriod` | duration (ISO 8601) | `reactietermijn` | No |
| `publicationRequired` | boolean | `publicatie_indicatie` | Yes |
| `publicationPeriod` | duration (ISO 8601) | `publicatietermijn` | No |

---

## Requirements

### REQ-ROLE-001: Role Assignment on Cases

The system MUST support role assignment on cases.

@e2e exclude Role assignment requires existing cases with access; data-dependent participant assignment flows not testable without pre-seeded cases.

**Tier**: MVP

The system MUST support assigning roles to participants on cases. A role links a participant (Nextcloud user or contact reference) to a case with a specific role type.

#### Scenario: Assign a handler to a case

- GIVEN a case #2024-042 "Bouwvergunning Keizersgracht" exists
- AND a role type "Behandelaar" (genericRole: `handler`) exists for the case's type "Omgevingsvergunning"
- WHEN the coordinator assigns Nextcloud user "jan.devries" as handler
- THEN the system MUST create a role object in the `role` schema with:
  - `name`: "Behandelaar"
  - `roleType`: UUID of the "Behandelaar" role type
  - `case`: UUID of case #2024-042
  - `participant`: "jan.devries"
- AND the handler MUST appear in the Participants section of the case detail view
- AND the case's `assignee` field SHOULD also be set to "jan.devries" (handler shortcut)
- AND the audit trail MUST record the role assignment

#### Scenario: Assign initiator from Pipelinq request-to-case conversion

- GIVEN a Pipelinq request #REQ-2024-089 is being converted to a case
- AND the requesting contact is "Petra Jansen" (contact ref: "contact-uuid-petra")
- AND the case type has a role type "Aanvrager" (genericRole: `initiator`)
- WHEN the case is created from the request
- THEN the system SHOULD automatically create a role with:
  - `roleType`: UUID of the "Aanvrager" role type
  - `participant`: "contact-uuid-petra"
  - `case`: UUID of the new case
- AND the initiator MUST appear in the Participants section under "Initiator"

#### Scenario: Assign multiple participants with different roles

- GIVEN case #2024-042 already has:
  - Handler: "jan.devries" (Jan de Vries)
  - Initiator: "contact-uuid-petra" (Petra Jansen)
- WHEN the coordinator adds an advisor with participant "dr.k.bakker"
- THEN the system MUST create a new role object for the advisor
- AND all three participants MUST be visible in the case detail:
  ```
  Handler:    Jan de Vries     [Reassign]
  Initiator:  Petra Jansen (Acme Corp)
  Advisor:    Dr. K. Bakker
  ```
- AND each role MUST show the participant display name and role type label

#### Scenario: Assign the same participant with multiple roles

- GIVEN "jan.devries" is already the handler on case #2024-042
- WHEN the coordinator also assigns "jan.devries" as the coordinator role
- THEN the system MUST create a second role object for the coordinator assignment
- AND the Participants section MUST show Jan de Vries listed under both roles

#### Scenario: Reassign a handler

- GIVEN case #2024-042 has handler "jan.devries" (Jan de Vries)
- WHEN the coordinator clicks "Reassign" and selects "maria.bakker" (Maria Bakker)
- THEN the existing handler role MUST be updated with `participant`: "maria.bakker"
- AND the case `assignee` field SHOULD be updated to "maria.bakker"
- AND "maria.bakker" SHOULD receive a notification about the assignment
- AND the audit trail MUST record the reassignment from "jan.devries" to "maria.bakker"

#### Scenario: Remove a role from a case

- GIVEN case #2024-042 has an advisor role for "dr.k.bakker"
- WHEN the coordinator removes the advisor role
- THEN the role object MUST be deleted from OpenRegister
- AND "Dr. K. Bakker" MUST no longer appear in the Participants section
- AND the audit trail MUST record the removal

---

### REQ-ROLE-002: Role Type Enforcement from Case Type

The system SHALL enforce role types from the case type.

@e2e exclude Role type enforcement is V1; requires case types with configured role types, not present in the current test environment.

**Tier**: V1

The system SHOULD enforce that only role types linked to the case's case type can be assigned. This prevents assigning roles that are not applicable to the case type.

#### Scenario: Only allowed role types are available for assignment

- GIVEN case type "Omgevingsvergunning" has role types:
  - "Aanvrager" (genericRole: `initiator`)
  - "Behandelaar" (genericRole: `handler`)
  - "Technisch adviseur" (genericRole: `advisor`)
  - "Beslisser" (genericRole: `decision_maker`)
- WHEN the user opens the "Add Participant" dialog on a case of this type
- THEN only these four role types MUST be available for selection
- AND role types from other case types MUST NOT appear

#### Scenario: Reject assignment of a role type not linked to the case type

- GIVEN case type "Klacht behandeling" has only role types: "Klager" (initiator), "Behandelaar" (handler)
- WHEN the user attempts to assign a role with genericRole `advisor` to a case of this type
- THEN the system MUST reject the assignment
- AND the error message MUST indicate that the role type is not allowed for this case type

#### Scenario: Case type with no role types defined

- GIVEN case type "Melding" has no role types configured (V1 feature not yet configured)
- WHEN the user attempts to add a participant to a case of this type
- THEN the system SHOULD allow assignment with any generic role as fallback
- OR the system SHOULD display a message that role types need to be configured by an admin

---

### REQ-ROLE-003: Handler Assignment Shortcut

The system MUST provide a handler assignment shortcut.

@e2e exclude Handler assignment shortcut requires existing cases in the list/detail view; data-dependent user assignment not testable without pre-seeded cases.

**Tier**: MVP

The system MUST provide a convenient handler assignment mechanism that creates the handler role and updates the case's `assignee` field in a single action.

#### Scenario: Quick handler assignment from case list

- GIVEN the case list shows case #2024-050 "Bouwvergunning Prinsengracht" with handler "---"
- WHEN the user clicks the handler cell and selects "Jan de Vries"
- THEN the system MUST create a handler role for "jan.devries" on the case
- AND the case `assignee` MUST be set to "jan.devries"
- AND the case list MUST immediately reflect the new handler

#### Scenario: Handler assignment from case detail

- GIVEN case #2024-050 has no handler assigned
- WHEN the user clicks "Assign Handler" in the Participants section
- THEN a user picker MUST appear showing Nextcloud users
- AND selecting "jan.devries" MUST create both the role and update the case assignee

---

### REQ-ROLE-004: Role-Based Case Access

The system SHALL support role-based case access.

@e2e exclude Role-based case access is V1; requires multi-user setup with restricted case configurations not available in the current test environment.

**Tier**: V1

The system SHOULD support controlling who can see and edit a case based on their assigned role.

#### Scenario: Handler has full edit access

- GIVEN "jan.devries" is assigned as handler on case #2024-042
- WHEN Jan views the case
- THEN Jan MUST have full edit access: update case fields, change status, manage tasks, manage roles

#### Scenario: Advisor has read access plus task assignment

- GIVEN "dr.k.bakker" is assigned as advisor on case #2024-042
- WHEN Dr. Bakker views the case
- THEN Dr. Bakker MUST have read access to all case details
- AND Dr. Bakker SHOULD be able to complete tasks assigned to them
- AND Dr. Bakker MUST NOT be able to change the case status or manage other roles

#### Scenario: Unassigned user cannot access a restricted case

- GIVEN case #2024-042 has confidentiality `case_sensitive`
- AND "pieter.smit" has no role on the case
- WHEN "pieter.smit" attempts to view the case
- THEN the system SHOULD deny access based on RBAC rules
- AND the case MUST NOT appear in Pieter's case list

---

### REQ-RESULT-001: Case Result Recording

The system MUST support case result recording.

@e2e exclude Case result recording requires closing a case with a specific result type; data-dependent result flows not testable without pre-seeded cases.

**Tier**: MVP

The system MUST support recording a result when a case is being completed. Each case MUST have at most one result. The result links to a predefined result type from the case type.

#### Scenario: Record a result on case completion

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" has status "Besluitvorming" (the status before final)
- AND the case type "Omgevingsvergunning" has result types: "Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"
- WHEN the handler Jan de Vries records the result "Vergunning verleend"
- THEN the system MUST create a result object with:
  - `name`: "Vergunning verleend"
  - `case`: UUID of case #2024-042
  - `resultType`: UUID of the "Vergunning verleend" result type
- AND the case `result` reference MUST point to this result object
- AND the case `endDate` MUST be set to the current date
- AND the case status MUST transition to "Afgehandeld" (the final status)
- AND the audit trail MUST record the result and case closure

#### Scenario: Result type determines archival rules

- GIVEN the result type "Vergunning verleend" has:
  - archiveAction: `retain`
  - retentionPeriod: "P20Y" (20 years)
  - retentionDateSource: `case_completed`
- WHEN this result is recorded on case #2024-042
- THEN the system MUST store the archival metadata linked to the case
- AND the retention end date MUST be calculated as endDate + 20 years

#### Scenario: Result with "Denied" outcome

- GIVEN case #2024-038 "Subsidie innovatie" is being closed
- WHEN the handler Maria Bakker records result "Subsidie afgewezen" (archiveAction: `destroy`, retentionPeriod: "P10Y")
- THEN the result MUST be created and linked to the case
- AND the case MUST be marked as completed with endDate set

#### Scenario: Choose from predefined result types

- GIVEN case type "Omgevingsvergunning" has 3 result types configured
- WHEN the user initiates case closure on case #2024-042
- THEN the system MUST present the 3 result types as a selectable list
- AND the user MUST select one before completing the case
- AND free-text result entry MUST NOT be allowed (the result must match a defined result type)

#### Scenario: Attempt to record a result with an invalid result type

- GIVEN case type "Omgevingsvergunning" has result types: "Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"
- WHEN the user attempts to record a result with a result type UUID belonging to case type "Klacht behandeling"
- THEN the system MUST reject the result
- AND the error message MUST indicate that the result type does not belong to this case type

#### Scenario: Attempt to record a second result on a case

- GIVEN case #2024-042 already has a result "Vergunning verleend"
- WHEN the user attempts to record another result
- THEN the system MUST reject the operation
- AND the error message MUST indicate that a case can have at most one result

#### Scenario: Case without result types configured

- GIVEN case type "Melding" has no result types defined (MVP without V1 type configuration)
- WHEN the handler closes the case
- THEN the system MUST allow case closure without selecting a result type
- AND a generic result with the case closure information MUST be recorded

---

### REQ-RESULT-002: Result Type Configuration

Admin users MUST be able to configure result types.

@e2e exclude Result type configuration is V1; covered by REQ-ADMIN-009 admin settings tab; not testable separately without a published case type.

**Tier**: V1

Admin users MUST be able to configure result types per case type, including archival rules.

#### Scenario: Create a result type with archival rules

- GIVEN the admin is editing case type "Omgevingsvergunning"
- WHEN the admin creates a result type:
  - name: "Vergunning verleend"
  - archiveAction: `retain`
  - retentionPeriod: "P20Y"
  - retentionDateSource: `case_completed`
- THEN the result type MUST be created and linked to the case type
- AND the result type MUST appear in the Result Types section of the case type admin page

#### Scenario: Edit a result type's archival rules

- GIVEN result type "Vergunning geweigerd" for case type "Omgevingsvergunning" has retentionPeriod "P10Y"
- WHEN the admin changes retentionPeriod to "P7Y"
- THEN the result type MUST be updated
- AND existing cases that used this result type MUST NOT be retroactively affected

#### Scenario: Delete a result type that is not in use

- GIVEN result type "Ingetrokken" for case type "Omgevingsvergunning" is not referenced by any case result
- WHEN the admin deletes the result type
- THEN the result type MUST be removed from the case type
- AND it MUST no longer appear as an option during case closure

#### Scenario: Attempt to delete a result type that is in use

- GIVEN result type "Vergunning verleend" is referenced by 5 existing case results
- WHEN the admin attempts to delete it
- THEN the system SHOULD warn the admin that 5 cases reference this result type
- AND the system SHOULD either prevent deletion or mark the result type as inactive (not available for new results but still resolves for existing ones)

---

### REQ-DECISION-001: Decision CRUD

The system SHALL support decision create, read, update, and delete operations.

@e2e exclude Decision CRUD is V1; decision panel on case detail is not yet built in the current Playwright-testable build.

**Tier**: V1

The system SHOULD support creating, reading, updating, and deleting formal decisions linked to cases. Decisions represent administrative determinations with potential legal effect.

#### Scenario: Create a decision on a case

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" is in status "Besluitvorming"
- AND the case type has a decision type "Omgevingsvergunning besluit"
- WHEN the decision maker "dr.k.bakker" records a decision:
  - title: "Omgevingsvergunning verleend Keizersgracht 100"
  - description: "Vergunning verleend voor de verbouwing van het pand op Keizersgracht 100 conform ingediende bouwtekeningen."
  - decisionType: UUID of "Omgevingsvergunning besluit"
  - effectiveDate: "2026-03-01"
  - expiryDate: "2031-03-01"
- THEN the system MUST create a decision object in the `decision` schema with:
  - `case`: UUID of case #2024-042
  - `decidedBy`: "dr.k.bakker"
  - `decidedAt`: current timestamp
  - All provided fields stored correctly
- AND the decision MUST appear in the Decisions section of the case detail view
- AND the audit trail MUST record the decision creation

#### Scenario: Create a decision with default decidedAt

- GIVEN the user records a decision without explicitly setting `decidedAt`
- THEN `decidedAt` MUST default to the current timestamp
- AND the decision date MUST be displayed in the case detail

#### Scenario: View decisions on case detail

- GIVEN case #2024-042 has 2 decisions:
  - "Omgevingsvergunning verleend" (decidedAt: 2026-02-25, effectiveDate: 2026-03-01, expiryDate: 2031-03-01)
  - "Voorwaardelijk gebruik terrein" (decidedAt: 2026-02-20, effectiveDate: 2026-02-20, expiryDate: 2027-02-20)
- WHEN the user views the case detail
- THEN both decisions MUST be displayed in the Decisions section
- AND each decision MUST show: title, decided date, decided by, validity period (effective to expiry)
- AND decisions MUST be sorted by decidedAt descending (most recent first)

#### Scenario: Update a decision's description

- GIVEN decision "Omgevingsvergunning verleend" exists on case #2024-042
- WHEN the decision maker updates the description to add additional conditions
- THEN the decision object MUST be updated via the OpenRegister API
- AND the audit trail MUST record the modification

#### Scenario: Delete a decision

- GIVEN decision "Voorwaardelijk gebruik terrein" exists on case #2024-042
- WHEN the user deletes the decision
- THEN the decision object MUST be removed from OpenRegister
- AND it MUST no longer appear in the case detail
- AND the audit trail MUST record the deletion

#### Scenario: Create decision from voorstel workflow

- GIVEN the secretariaat clicks "Besluit registreren" on a voorstel with status "geaccordeerd"
- AND enters: besluit tekst, ingangsdatum, besluittype
- WHEN the besluit registration is submitted
- THEN a decision object SHALL be created via the existing decision schema
- AND the decision SHALL be linked to the parent case of the voorstel
- AND the voorstel status SHALL change to "besloten"
- AND the case activity timeline SHALL show: "Besluit vastgesteld: [tekst]"

---

### REQ-DECISION-002: Decision Validity Periods

The system SHALL track decision validity periods.

@e2e exclude Decision validity periods are V1; requires decision objects on cases, not testable in the current Playwright-testable build.

**Tier**: V1

The system SHOULD support tracking the validity period of decisions (effectiveDate to expiryDate) and provide indicators when decisions are nearing expiry or have expired.

#### Scenario: Decision with validity period display

- GIVEN a decision "Omgevingsvergunning verleend" with effectiveDate "2026-03-01" and expiryDate "2031-03-01"
- AND today is 2026-02-25
- WHEN the user views the decision
- THEN the validity period MUST be displayed as "Mar 1, 2026 -- Mar 1, 2031"
- AND the status MUST show "Not yet effective" (effective date is in the future)

#### Scenario: Active decision

- GIVEN a decision with effectiveDate "2026-01-01" and expiryDate "2031-01-01"
- AND today is 2026-06-15
- THEN the decision MUST be displayed as "Active"
- AND the remaining validity SHOULD be displayed (e.g., "4 years, 6 months remaining")

#### Scenario: Decision nearing expiry

- GIVEN a decision with expiryDate "2026-03-15"
- AND today is 2026-02-25 (18 days before expiry)
- THEN the decision SHOULD show an amber warning indicator
- AND the warning SHOULD indicate "Expires in 18 days"

#### Scenario: Expired decision

- GIVEN a decision with expiryDate "2025-12-31"
- AND today is 2026-02-25
- THEN the decision MUST be displayed as "Expired"
- AND an expired indicator MUST be shown in red

#### Scenario: Decision without expiry date

- GIVEN a decision with effectiveDate "2026-03-01" and no expiryDate
- THEN the validity MUST be displayed as "From Mar 1, 2026" (no end date)
- AND the decision MUST be treated as indefinitely valid once effective

#### Scenario: Decision without any dates

- GIVEN a decision with no effectiveDate and no expiryDate
- THEN no validity period MUST be displayed
- AND only the decidedAt date MUST be shown

---

### REQ-DECISION-003: Decision Types from Case Type

The system SHALL support decision types derived from the case type.

@e2e exclude Decision types from case type is V1; requires case types with decision types configured, not available in the current test environment.

**Tier**: V1

The system SHOULD support linking decision types to case types. When creating a decision on a case, only decision types allowed by the case's case type SHOULD be offered.

#### Scenario: Only allowed decision types are available

- GIVEN case type "Omgevingsvergunning" has decision types:
  - "Omgevingsvergunning besluit" (publicationRequired: true, objectionPeriod: "P6W")
  - "Voorlopige voorziening" (publicationRequired: false)
- WHEN the user creates a decision on a case of this type
- THEN only these two decision types MUST be available for selection
- AND the user MAY also create a decision without a decision type (free-form decision)

#### Scenario: Decision type provides default publication rules

- GIVEN decision type "Omgevingsvergunning besluit" has publicationRequired: true and publicationPeriod: "P6W"
- WHEN a decision of this type is created
- THEN the system SHOULD indicate that the decision requires publication
- AND the publication deadline SHOULD be calculated from the decidedAt date

#### Scenario: Create a decision without a decision type

- GIVEN a case where the case type has decision types configured
- WHEN the user creates a decision and selects "No type" or leaves decision type empty
- THEN the system MUST allow the decision to be created without a decision type
- AND all other required fields (title) MUST still be validated

---

### REQ-DECISION-004: Decision Validation

The system MUST validate decision data.

@e2e exclude Decision validation is V1; decision form is not yet built in the current Playwright-testable build.

**Tier**: V1

The system MUST validate decision data to ensure consistency and completeness.

#### Scenario: Decision title is required

- GIVEN the user is creating a new decision
- WHEN the user submits without a title
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `title` is required

#### Scenario: Decision case reference is required

- GIVEN the user is creating a new decision
- WHEN the user submits without a case reference
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `case` is required

#### Scenario: Expiry date must be after effective date

- GIVEN the user sets effectiveDate "2026-03-01" and expiryDate "2026-02-01"
- WHEN the user submits the decision
- THEN the system MUST reject the request
- AND the error message MUST indicate that expiryDate must be after effectiveDate

#### Scenario: DecidedBy must be a valid Nextcloud user

- GIVEN the user sets decidedBy to "nonexistent.user"
- WHEN the user submits the decision
- THEN the system SHOULD warn or reject that the user does not exist
- AND the system MAY allow the value if it is a free-text reference (external decision maker)

---

### REQ-ROLE-005: Participant Display on Case Detail

The case detail view MUST display participants grouped by role type.

@e2e exclude Participant display on case detail requires an existing case with/without assigned participants; data-dependent case detail section not testable without pre-seeded cases.

**Tier**: MVP

The case detail view MUST display all assigned participants grouped by role type, as shown in the design wireframes.

#### Scenario: Full participant section display

- GIVEN case #2024-042 has the following roles:
  - Handler: Jan de Vries ("jan.devries")
  - Initiator: Petra Jansen ("contact-uuid-petra", company "Acme Corp")
  - Advisor: Dr. K. Bakker ("dr.k.bakker")
- WHEN the user views the case detail page
- THEN the Participants section MUST display:
  ```
  PARTICIPANTS

  Handler:
  [avatar] Jan de Vries
           [Reassign]

  Initiator:
  [avatar] Petra Jansen (Acme Corp)

  Advisor:
  [avatar] Dr. K. Bakker

  [+ Add Participant]
  ```
- AND each participant MUST show their display name resolved from Nextcloud user or contact reference
- AND the handler role MUST have a "Reassign" action
- AND the "Add Participant" button MUST open a dialog to select role type and participant

#### Scenario: No participants assigned

- GIVEN a newly created case #2024-051 with no role assignments
- WHEN the user views the case detail
- THEN the Participants section MUST show an empty state
- AND a prominent "Assign Handler" action MUST be visible
- AND an "Add Participant" button MUST be available

#### Scenario: External contact as participant

- GIVEN "Petra Jansen" is a contact in Nextcloud Contacts (not a Nextcloud user)
- WHEN her role is displayed on the case
- THEN the system MUST resolve the contact reference to show her display name
- AND the system SHOULD show the organization ("Acme Corp") if available from the contact record
- AND the participant MUST be distinguished from Nextcloud users (e.g., different icon or label)

---

### REQ-ROLE-006: Role Validation

The system MUST validate role assignments.

@e2e exclude Role validation scenarios require submitting invalid role assignments against existing cases; data-dependent validation flows not testable without pre-seeded cases.

**Tier**: MVP

The system MUST validate role assignments to ensure data integrity.

#### Scenario: Participant is required

- GIVEN the user is creating a new role on a case
- WHEN the user submits without selecting a participant
- THEN the system MUST reject the request
- AND the error message MUST indicate that `participant` is required

#### Scenario: Role type is required

- GIVEN the user is creating a new role on a case
- WHEN the user submits without selecting a role type
- THEN the system MUST reject the request
- AND the error message MUST indicate that `roleType` is required

#### Scenario: Case reference is required

- GIVEN the user is creating a new role
- WHEN the user submits without a case reference
- THEN the system MUST reject the request
- AND the error message MUST indicate that `case` is required

#### Scenario: Validate that the referenced case exists

- GIVEN the user submits a role with `case` set to a non-existent UUID
- THEN the system MUST reject the request
- AND the error message MUST indicate that the referenced case does not exist

---

### REQ-DECISION-005: Decisions Section on Case Detail

The case detail view MUST display all decisions linked to the case.

@e2e exclude Decisions section on case detail is V1; decision panel is not yet built in the current Playwright-testable build.

**Tier**: V1

The case detail view MUST display all decisions linked to the case.

#### Scenario: Decisions section with no decisions

- GIVEN case #2024-042 has no decisions recorded
- WHEN the user views the case detail
- THEN the Decisions section MUST display "(no decisions yet)"
- AND an "Add Decision" button MUST be visible

#### Scenario: Decisions section with multiple decisions

- GIVEN case #2024-042 has 2 decisions
- WHEN the user views the case detail
- THEN both decisions MUST be listed with:
  - Title
  - Decided by (user display name)
  - Decided at (date)
  - Validity period (if set)
  - Decision type (if set)
- AND each decision MUST be clickable to view/edit details

---

### Requirement: The case page lists every party in a Parties tab (REQ-ROLE-007)

You see everyone involved in the case with their role. The `case-panels` tabs
widget on `CaseDetail` SHALL carry a tab Parties that renders widget
`case-roles`, type `object-list`, over schema `role` filtered on
`case = @objectId`, sorted by role type, with the columns role type,
participant, delegate and delegation end date. The tab SHALL sit between
Documents and Tasks in the tab order. The list SHALL show the empty state
"No parties yet" when the case has no role rows. This refines REQ-ROLE-005:
the grouped ParticipantsSection is replaced by the declarative list.

#### Scenario: Parties visible on the case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with a handler role and an advisor role
- **WHEN** you open the case page and pick the Parties tab
- **THEN** the list SHALL show both rows with role type, participant, delegate and delegation end date
- **AND** the tab SHALL be reachable by its id `case-roles` without scrolling past Data

#### Scenario: A case without parties says so
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with no role rows
- **WHEN** you open the Parties tab
- **THEN** the list SHALL show the empty state and the Add party action

### Requirement: You add a party from the case page with the case filled in (REQ-ROLE-008)

You add a person or an organisation with a role without leaving the case. The
Parties tab SHALL carry a header action Add party of type `open-form` over
schema `role` with `props: {"case": "@objectId"}`, showing the fields role
type, participant, delegate, delegate until and description. On success the
list SHALL refresh and show the new row. The `case` field SHALL be prefilled
and read only.

#### Scenario: Add a party with the case prefilled
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** an open case
- **WHEN** you press Add party, choose the role type Advisor and a participant, and save
- **THEN** the new row SHALL appear in the Parties list
- **AND** the saved role row SHALL reference the case you were on

#### Scenario: Role validation still runs
@e2e exclude REQ-ROLE-006 validation runs in OpenRegister and is covered by tests/Unit for the schema; the form only forwards the error

- **GIVEN** the Add party form
- **WHEN** you save without a role type
- **THEN** the form SHALL show the validation error from the platform and keep your input

### Requirement: Every case type offers a Gemachtigde role (REQ-ROLE-009)

A generic `roleType` Gemachtigde with `genericRole: gemachtigde` and no
`caseType` SHALL be seeded once and offered on the Add party form of every
case type after the type's own role types. When a type declares its own
`gemachtigde` role, the generic one SHALL NOT be listed twice.

#### Scenario: A representative on a permit case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case of a type that declares no Gemachtigde role
- **WHEN** you press Add party and open the role type list
- **THEN** Gemachtigde SHALL be offered

#### Scenario: Bezwaar keeps one Gemachtigde
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a bezwaar case whose type declares its own Gemachtigde role
- **WHEN** you open the role type list
- **THEN** Gemachtigde SHALL be listed once

### Requirement: The Parties tab shows who is represented (REQ-ROLE-010)

A Gemachtigde row SHALL show the representative as participant and the
represented party in the column Represented by, read from `representedParty`.

AMENDED 2026-09-18. The proposal and the first wording of this requirement
both named `delegateFrom`. That property was already taken: it is the START
OF A DELEGATION WINDOW, a date-time, and `RoleDelegationResolver` compares it
against now to decide whether to substitute a delegate. Writing a party uuid
into it would have made every routing decision on that case compare a uuid
against a clock, in silence, and the widget would still have rendered the
name. `role.representedParty` is a new property, and the role schema moves to
1.2.0 with it, because OpenRegister fast-skips a schema whose version did not
move and would have dropped the property without a word.

#### Scenario: Represented party is visible
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a Gemachtigde role added for the requester of a case
- **WHEN** you open the Parties tab
- **THEN** the row SHALL show the representative and the requester under Represented by

### Requirement: The case declares the kinds of party it takes (REQ-ROLE-011)

The case schema SHALL declare `partyKinds` naming at least `person`,
`organisation` and `address`, each with a label. A write naming a kind the
schema does not declare SHALL be refused. The `address` kind SHALL hold the
role `locatie` and no other. `person` and `organisation` SHALL name no
roles, because a kind naming roles holds only those and a person link
carries a role type uuid as its role.

The schemas whose objects are parties SHALL declare
`x-openregister-party`, naming the kind and the properties carrying the
name, the addresses, the indicators and, for an organisation, the parent.

#### Scenario: A party of an undeclared kind is refused
@e2e exclude {the refusal is OpenRegister's validator; asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php, which checks the declaration this app writes}

- **GIVEN** a case whose schema declares person, organisation and address
- **WHEN** a party of another kind is added to it
- **THEN** the write SHALL be refused and the message SHALL name the kind

#### Scenario: An address holds only the location role
@e2e exclude {vocabulary shape, asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php::testTheCaseDeclaresTheKindsOfPartyItTakes}

- **GIVEN** the declaration above
- **WHEN** an address party is added in the role of representative
- **THEN** the write SHALL be refused, naming the roles that kind holds

### Requirement: Every case type offers the generic party roles (REQ-ROLE-012)

The case schema's link vocabulary SHALL carry the instance's own role
types first, then the generic party roles every case type offers:
requester, authorised representative, interested party, sender, addressee
and location. A generic role whose key a role type already claims SHALL
NOT be listed twice. The labels SHALL be translated, so a reader sees them
in their own language.

#### Scenario: A representative on a permit case
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case of a type that declares no representative role type
- **WHEN** the vocabulary is read from the case schema
- **THEN** it SHALL carry `gemachtigde` after the type's own role types

#### Scenario: A role type keeps the key it claims
@e2e exclude {vocabulary shape, asserted in tests/Unit/Service/People/CaseRoleVocabularyTest.php::testAGenericRoleIsNotListedTwice}

- **GIVEN** an instance whose own role type is keyed `gemachtigde`
- **WHEN** the vocabulary is written
- **THEN** `gemachtigde` SHALL appear once, labelled by the role type

### Requirement: An indicator on a party is surfaced where the act is offered (REQ-ROLE-013)

An indicator a party carries SHALL be read where dossiq offers the act it
refuses, not only where the party is drawn. A file request to a party
whose indicator refuses a send SHALL be refused, naming the indicator and
the party, and that party SHALL be listed and unselectable with the reason
beside them. Publishing a decision on a case a party refuses publication
on SHALL be refused, naming the indicator and the party.

An instance whose OpenRegister carries no party model SHALL behave as it
did before: nothing refuses the act here, and the refusal that matters is
the one OpenRegister makes at the same two acts.

#### Scenario: A protected party is listed and cannot be asked for a file
@e2e exclude {the indicator is an OpenRegister party record; asserted in tests/Unit/Controller/FileRequestControllerTest.php::testAPartyWhoseIndicatorRefusesASendIsListedAndNamed}

- **GIVEN** a party on a case whose indicator refuses a send
- **WHEN** the handler opens the file request dialog
- **THEN** that party SHALL be listed, not selectable, and the indicator SHALL be named

#### Scenario: The send itself is refused, not only the dialog
@e2e exclude {server-side guard, asserted in tests/Unit/Service/People/FileRequestServiceTest.php::testAPartyWhoseIndicatorRefusesASendIsNotSentTo}

- **GIVEN** the party above
- **WHEN** a file request is posted for them anyway
- **THEN** the response SHALL be 403 naming the indicator and the party, and no share SHALL be created

#### Scenario: Publishing is refused and says why
@e2e exclude {the publication path needs a DROP/LVBB endpoint no e2e instance has; asserted in tests/vitest/caseParties.spec.js}

- **GIVEN** a case with a party whose indicator refuses publication
- **WHEN** the handler opens the publication panel
- **THEN** the publish button SHALL be disabled and the indicator and party SHALL be named

### Requirement: The case page shows who is on the case, in their roles (REQ-ROLE-014)

The People tab SHALL carry a Roles section reading the parties of the
case grouped by role. The role holding the primary party SHALL be
rendered first, and the primary party first within it. A role SHALL be
labelled by the vocabulary rather than by its key. A read that could not
be made SHALL say so, and SHALL NOT be drawn as a case with no parties.

#### Scenario: The primary party is first
@e2e tests/e2e/case-parties.spec.ts

- **GIVEN** a case with a primary party and two other parties in other roles
- **WHEN** the handler opens the People tab
- **THEN** the primary party SHALL be the first party shown, marked as primary

#### Scenario: A failed read is not an empty case
@e2e exclude {the failure is an OpenRegister outage; asserted in tests/vitest/caseParties.spec.js}

- **WHEN** the parties of a case cannot be read
- **THEN** the section SHALL say the parties could not be read, and SHALL NOT show an empty party list

### Requirement: A picker resolves an address before creating a second party (REQ-ROLE-015)

Before a requester is recorded from a source that carries no register
row, the picker SHALL ask which party already holds that address. When
one does, the requester SHALL name that party rather than a second
record. When none does, the choice SHALL be recorded as it was.

#### Scenario: A contact whose address a party already holds
@e2e exclude {the resolve endpoint is OpenRegister's; asserted in tests/vitest/initiatorPicker.spec.js}

- **GIVEN** a party holding the address `jan@example.nl`
- **WHEN** a handler picks a Nextcloud contact with that address as the requester
- **THEN** the case SHALL name that party as its requester and no second party SHALL be created

#### Scenario: An address nobody holds
@e2e exclude {same endpoint; asserted in tests/vitest/initiatorPicker.spec.js}

- **GIVEN** an address no party holds
- **WHEN** the same choice is made
- **THEN** the requester SHALL be recorded exactly as it was before this requirement

## Error Scenarios Summary

| Error | Expected Behavior | Tier |
|-------|-------------------|------|
| Assign role type not linked to case type | Reject with "Role type not allowed for this case type" | V1 |
| Record result with invalid result type | Reject with "Result type does not belong to this case type" | V1 |
| Record second result on a case | Reject with "Case already has a result" | MVP |
| Create decision without title | Reject with validation error "title is required" | V1 |
| Create decision with expiryDate before effectiveDate | Reject with "expiryDate must be after effectiveDate" | V1 |
| Create role without participant | Reject with "participant is required" | MVP |
| Create role referencing non-existent case | Reject with "Referenced case does not exist" | MVP |
| Assign handler to non-existent user | Reject with "User does not exist" | MVP |

---

## Accessibility

All roles and decisions interfaces MUST comply with WCAG AA:

- Participant display names MUST have sufficient contrast
- Role type selection MUST be keyboard-accessible
- Decision validity indicators MUST NOT rely solely on color (use text labels alongside color)
- The "Add Participant" dialog MUST be focusable and navigable by keyboard
- Screen readers MUST announce role type and participant name for each entry

---

## Performance

- The Participants section MUST resolve user/contact display names within 1 second
- Decision validity calculations MUST be performed client-side (no extra API call)
- Role and result operations MUST complete within 2 seconds
- The case detail page MUST load participants, results, and decisions in parallel with other sections

---

### Current Implementation Status

**Roles: Substantially implemented (MVP). Results: Partially implemented. Decisions: Not implemented.**

**Roles -- Implemented (with file paths):**
- **ParticipantsSection**: `src/views/cases/components/ParticipantsSection.vue` -- displays all roles on a case, grouped by role type name. Resolves participant display names via Nextcloud OCS API (`/ocs/v2.php/cloud/users/{uid}`). Shows initials avatar, role type label, and participant name. Supports "Add Participant" button and "Reassign" action on handler roles. Supports "Remove" action on non-handler roles (REQ-ROLE-001, REQ-ROLE-005).
- **AddParticipantDialog**: `src/views/cases/components/AddParticipantDialog.vue` -- dialog for adding participants with role type selection and user picker. Supports pre-selecting handler role type (REQ-ROLE-003).
- **Handler reassignment**: `ParticipantsSection.vue` includes inline reassign UI with NcSelect user picker. Updates both the role object's `participant` and the case's `assignee` field (REQ-ROLE-003).
- **Role removal**: Supported via delete button with confirmation dialog.
- **Role schema**: Defined in `lib/Settings/dossiq_register.json` with properties: `name`, `description`, `roleType`, `case`, `participant` (REQ matching the data model).
- **RoleType schema**: Defined in `dossiq_register.json` with `name`, `caseType`, `genericRole` properties. The `genericRole` enum includes: `initiator`, `handler`, `advisor`, `decision_maker`, `stakeholder`, `coordinator`, `contact`, `co_initiator`.
- **Data fetching**: Roles fetched via `objectStore.fetchCollection('role', { '_filters[case]': caseId })`. Role types fetched in parallel.
- **Display name resolution**: `resolveDisplayNames()` method fetches Nextcloud user info per participant UID.
- **User picker**: `fetchUsers()` fetches available users from `/ocs/v2.php/cloud/users/details`.

**Roles -- Not yet implemented:**
- **REQ-ROLE-002: Role type enforcement (V1)**: No validation that assigned role types belong to the case's case type. All role types are shown in the picker regardless of case type.
- **REQ-ROLE-004: Role-based case access (V1)**: No RBAC enforcement based on role assignments. All users with app access can see all cases.
- **REQ-ROLE-006: Role validation**: Client-side validation exists in the dialog, but server-side validation of participant existence and case reference validity is delegated to OpenRegister schema validation.
- **Notifications**: No Nextcloud notification sent when a handler is assigned or reassigned.
- **External contacts**: Only Nextcloud users are supported as participants. No integration with Nextcloud Contacts for external party references.

**Results -- Partially implemented:**
- **ResultSection**: `src/views/cases/components/ResultSection.vue` -- displays a single result with name, description, and result type. Resolves result type name from the `resultTypes` array.
- **Result schema**: Defined in `dossiq_register.json` with `name`, `description`, `case`, `resultType` properties.
- **ResultType schema**: Defined in `dossiq_register.json` with `name`, `description`, `caseType`, `archiveAction`, `retentionPeriod`, `retentionDateSource` properties.
- **Not implemented**: Result creation UI (selecting from predefined result types during case closure), archival metadata display, result type management in admin settings (REQ-RESULT-002), enforcement of one-result-per-case.

**Decisions -- Not implemented:**
- **Decision schema**: Defined in `dossiq_register.json` with `title`, `description`, `case`, `decisionType`, `decidedBy`, `decidedAt`, `effectiveDate`, `expiryDate` properties.
- **DecisionType schema**: Defined in `dossiq_register.json` with `name`, `description`, `category`, `objectionPeriod`, `publicationRequired`, `publicationPeriod` properties.
- **No UI exists** for creating, viewing, editing, or deleting decisions on cases. No Decisions section on the case detail page. No validity period tracking or expiry indicators.
- The ZGW BRC (Besluiten) controller (`lib/Controller/BrcController.php`) provides ZGW-compliant decision API endpoints, but no frontend consumes them.

### Standards & References

- **ZGW APIs (VNG Realisatie)**: Roles map to ZGW `Rol` with `omschrijvingGeneriek` for generic role categories. Results map to `Resultaat` with `archiefnominatie` and `archiefactietermijn`. Decisions map to `Besluit` with `ingangsdatum`, `vervaldatum`, `publicatie_indicatie`. ZGW BRC controller fully implemented.
- **Schema.org**: Roles typed as `schema:Role`, decisions as `schema:ChooseAction` in `dossiq_register.json`.
- **CMMN 1.1**: Role assignments follow CMMN case participant patterns.
- **Archivering**: Result types include `archiveAction` (retain/destroy) and `retentionPeriod` (ISO 8601 duration) per Dutch archival standards (Archiefwet).
- **WCAG 2.1 AA**: ParticipantsSection uses sufficient contrast and text labels. Decision validity indicators (not yet implemented) must not rely solely on color.
- **Wet open overheid (WOO)**: Decision publication requirements align with WOO transparency obligations.

### Specificity Assessment

- **Roles**: Well-specified and mostly implemented. The MVP scenarios are clear and actionable.
- **Results**: Well-specified but implementation is incomplete. The result creation flow during case closure needs UI work.
- **Decisions**: Well-specified but entirely unimplemented in the frontend. The data model exists in the register config, and the ZGW API layer exists, but no Dossiq-native UI exists.
- **Open questions:**
  - Should role type enforcement be strict (reject) or advisory (warn)?
  - How should external contacts (non-Nextcloud users) be represented as participants?
  - Should decision publication trigger an n8n workflow or a direct API call?
  - How does the result creation flow interact with case status transitions (must the case transition to a final status after result is recorded)?
