---
status: implemented
---
# Roles & Decisions Specification

## Purpose

Roles define the relationship between participants (Nextcloud users or external contacts) and cases -- who is involved and in what capacity. Results record the formal outcome of a completed case, linking to a predefined result type that controls archival rules. Decisions are formal administrative choices made on cases, with legal validity periods and publication requirements.

Together, these three entities govern participation, outcomes, and formal decision-making within the case lifecycle.

**Standards**: Schema.org (`Role`, `ChooseAction`), CMMN (case outcomes, case participants), ZGW (`Rol`, `Resultaat`, `Besluit`, `RolType`, `ResultaatType`, `BesluitType`)
**Primary feature tier**: MVP (roles, results), V1 (decisions, role types, result types, decision types)

**Competitive context**: Dimpact ZAC provides OPA-based policy authorization with 5 policy domains and 51+ individual permissions, plus formal decision (besluit) recording with publication dates and withdrawal. xxllnc Zaken implements 4-level case authorization (search/read/write/manage) and threaded messaging linked to cases. ArkCase uses participant-based row-level ACL with functional access control mapped from LDAP groups. Flowable provides identity links connecting users/groups to tasks and cases with delegation support. Procest takes a simpler role-based approach that maps to ZGW `Rol` with generic role categories, suitable for the Dutch government context.

---

## Data Model

### Role Entity

Stored as an OpenRegister object in the `procest` register under the `role` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `name` | string (max 255) | `schema:roleName` / `omschrijving` | Yes | -- |
| `description` | string | `schema:description` / `roltoelichting` | No | -- |
| `roleType` | reference (UUID to RoleType) | -- / `omschrijvingGeneriek` (via RoleType) | Yes | -- |
| `case` | reference (UUID to Case) | -- / `zaak` | Yes | -- |
| `participant` | string (Nextcloud user UID or contact reference) | `schema:agent` / `betrokkene` | Yes | -- |

### Role Type Entity

Stored as an OpenRegister object in the `procest` register under the `roleType` schema.

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

Stored as an OpenRegister object in the `procest` register under the `result` schema.

| Property | Type | Source | Required |
|----------|------|--------|----------|
| `name` | string (max 255) | `schema:name` | Yes |
| `description` | string | `schema:description` | No |
| `case` | reference (UUID to Case) | Parent case | Yes |
| `resultType` | reference (UUID to ResultType) | ResultType definition | Yes |

### Result Type Entity

Stored as an OpenRegister object in the `procest` register under the `resultType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `description` | string | `toelichting` | No |
| `caseType` | reference (UUID to CaseType) | `zaaktype` | Yes |
| `archiveAction` | enum: `retain`, `destroy` | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601, e.g., "P20Y") | `archiefactietermijn` | No |
| `retentionDateSource` | enum | `afleidingswijze` | No |

### Decision Entity

Stored as an OpenRegister object in the `procest` register under the `decision` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `title` | string (max 255) | `schema:name` | Yes | -- |
| `description` | string | `schema:description` / `toelichting` | No | -- |
| `case` | reference (UUID to Case) | -- / `zaak` | Yes | -- |
| `decisionType` | reference (UUID to DecisionType) | -- / `besluittype` | No | -- |
| `decidedBy` | string (Nextcloud user UID) | `schema:agent` | No | -- |
| `decidedAt` | datetime (ISO 8601) | `schema:endTime` / `datum` | No | current timestamp |
| `effectiveDate` | date (ISO 8601) | `schema:startTime` / `ingangsdatum` | No | -- |
| `expiryDate` | date (ISO 8601) | `schema:endTime` / `vervaldatum` | No | -- |

### Decision Type Entity

Stored as an OpenRegister object in the `procest` register under the `decisionType` schema.

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

The system MUST support assigning roles to participants on cases, as implemented in `ParticipantsSection.vue` and `AddParticipantDialog.vue`. A role links a participant (Nextcloud user or contact reference) to a case with a specific role type.

**Tier**: MVP


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

#### Scenario: Assign multiple participants with different roles

- GIVEN case #2024-042 already has:
  - Handler: "jan.devries" (Jan de Vries)
  - Initiator: "contact-uuid-petra" (Petra Jansen)
- WHEN the coordinator adds an advisor with participant "dr.k.bakker"
- THEN the system MUST create a new role object for the advisor
- AND all three participants MUST be visible in the case detail grouped by role type
- AND each role MUST show the participant display name and role type label

#### Scenario: Assign the same participant with multiple roles

- GIVEN "jan.devries" is already the handler on case #2024-042
- WHEN the coordinator also assigns "jan.devries" as the coordinator role
- THEN the system MUST create a second role object for the coordinator assignment
- AND the Participants section MUST show Jan de Vries listed under both roles

#### Scenario: Reassign a handler

- GIVEN case #2024-042 has handler "jan.devries" (Jan de Vries)
- WHEN the coordinator clicks "Reassign" and selects "maria.bakker" (Maria Bakker) via the NcSelect user picker
- THEN the existing handler role MUST be updated with `participant`: "maria.bakker"
- AND the case `assignee` field SHOULD be updated to "maria.bakker"
- AND "maria.bakker" SHOULD receive a notification about the assignment
- AND the audit trail MUST record the reassignment from "jan.devries" to "maria.bakker"

#### Scenario: Remove a role from a case

- GIVEN case #2024-042 has an advisor role for "dr.k.bakker"
- WHEN the coordinator removes the advisor role via the delete button with confirmation
- THEN the role object MUST be deleted from OpenRegister
- AND "Dr. K. Bakker" MUST no longer appear in the Participants section

---

### REQ-ROLE-002: Role Type Enforcement from Case Type

The system SHALL enforce that only role types linked to the case's case type can be assigned. This prevents assigning roles that are not applicable to the case type.

**Tier**: V1


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

The system MUST provide a convenient handler assignment mechanism that creates the handler role and updates the case's `assignee` field in a single action, as implemented in `ParticipantsSection.vue` with the "Assign Handler" button.

**Tier**: MVP


#### Scenario: Quick handler assignment from case list

- GIVEN the case list shows case #2024-050 "Bouwvergunning Prinsengracht" with handler "---"
- WHEN the user clicks the handler cell and selects "Jan de Vries"
- THEN the system MUST create a handler role for "jan.devries" on the case
- AND the case `assignee` MUST be set to "jan.devries"
- AND the case list MUST immediately reflect the new handler

#### Scenario: Handler assignment from case detail

- GIVEN case #2024-050 has no handler assigned
- WHEN the user clicks "Assign Handler" in the Participants section
- THEN a user picker MUST appear showing Nextcloud users (fetched via `/ocs/v2.php/cloud/users/details`)
- AND selecting "jan.devries" MUST create both the role and update the case assignee

#### Scenario: Handler reassignment preserves other roles

- GIVEN case #2024-042 has handler "jan.devries" and advisor "dr.k.bakker"
- WHEN the handler is reassigned to "maria.bakker"
- THEN only the handler role MUST be updated
- AND the advisor role for "dr.k.bakker" MUST remain unchanged

---

### REQ-ROLE-004: Role-Based Case Access

The system SHALL support controlling who can see and edit a case based on their assigned role.

**Tier**: V1


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

### REQ-ROLE-005: Participant Display on Case Detail

The case detail view MUST display all assigned participants grouped by role type, as implemented in `ParticipantsSection.vue`.

**Tier**: MVP


#### Scenario: Full participant section display

- GIVEN case #2024-042 has the following roles:
  - Handler: Jan de Vries ("jan.devries")
  - Initiator: Petra Jansen ("contact-uuid-petra", company "Acme Corp")
  - Advisor: Dr. K. Bakker ("dr.k.bakker")
- WHEN the user views the case detail page
- THEN the Participants section MUST display participants grouped by role type name
- AND each participant MUST show their display name (resolved from Nextcloud OCS API) with initials avatar
- AND the handler role MUST have a "Reassign" action
- AND non-handler roles MUST have a "Remove" action (delete button)
- AND an "Add Participant" button MUST be visible

#### Scenario: No participants assigned

- GIVEN a newly created case #2024-051 with no role assignments
- WHEN the user views the case detail
- THEN the Participants section MUST show an empty state: "No participants assigned"
- AND a prominent "Assign Handler" button MUST be visible
- AND an "Add Participant" button MUST be available

#### Scenario: External contact as participant

- GIVEN "Petra Jansen" is a contact in Nextcloud Contacts (not a Nextcloud user)
- WHEN her role is displayed on the case
- THEN the system MUST resolve the contact reference to show her display name
- AND the system SHOULD show the organization ("Acme Corp") if available
- AND the participant MUST be distinguished from Nextcloud users (e.g., different icon or label)

---

### REQ-ROLE-006: Role Validation

The system MUST validate role assignments to ensure data integrity.

**Tier**: MVP


#### Scenario: Required fields validation

- GIVEN the user is creating a new role on a case
- WHEN the user submits without selecting a participant
- THEN the system MUST reject the request with "participant is required"
- AND submitting without a role type MUST be rejected with "roleType is required"
- AND submitting without a case reference MUST be rejected with "case is required"

#### Scenario: Validate that the referenced case exists

- GIVEN the user submits a role with `case` set to a non-existent UUID
- THEN the system MUST reject the request
- AND the error message MUST indicate that the referenced case does not exist

#### Scenario: Validate participant is a valid Nextcloud user

- GIVEN the user attempts to assign a role to "nonexistent.user"
- THEN the system SHOULD warn or reject that the user does not exist
- AND the system MAY allow external contact references that are not Nextcloud users

---

### REQ-RESULT-001: Case Result Recording

The system MUST support recording a result when a case is being completed. Each case MUST have at most one result. The result links to a predefined result type from the case type.

**Tier**: MVP


#### Scenario: Record a result on case completion

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" has status "Besluitvorming"
- AND the case type "Omgevingsvergunning" has result types: "Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"
- WHEN the handler Jan de Vries records the result "Vergunning verleend"
- THEN the system MUST create a result object with:
  - `name`: "Vergunning verleend"
  - `case`: UUID of case #2024-042
  - `resultType`: UUID of the "Vergunning verleend" result type
- AND the case `result` reference MUST point to this result object
- AND the case `endDate` MUST be set to the current date
- AND the case status MUST transition to "Afgehandeld" (the final status)

#### Scenario: Result type determines archival rules

- GIVEN the result type "Vergunning verleend" has:
  - archiveAction: `retain`
  - retentionPeriod: "P20Y" (20 years)
  - retentionDateSource: `case_completed`
- WHEN this result is recorded on case #2024-042
- THEN the system MUST store the archival metadata linked to the case
- AND the retention end date MUST be calculated as endDate + 20 years

#### Scenario: Choose from predefined result types

- GIVEN case type "Omgevingsvergunning" has 3 result types configured
- WHEN the user initiates case closure on case #2024-042
- THEN the system MUST present the 3 result types as a selectable list
- AND the user MUST select one before completing the case
- AND free-text result entry MUST NOT be allowed

#### Scenario: Attempt to record a second result on a case

- GIVEN case #2024-042 already has a result "Vergunning verleend"
- WHEN the user attempts to record another result
- THEN the system MUST reject the operation
- AND the error message MUST indicate that a case can have at most one result

#### Scenario: Case without result types configured

- GIVEN case type "Melding" has no result types defined
- WHEN the handler closes the case
- THEN the system MUST allow case closure without selecting a result type
- AND a generic result with the case closure information MUST be recorded

---

### REQ-RESULT-002: Result Type Configuration

Admin users MUST be able to configure result types per case type, including archival rules. This is managed via the admin settings Results tab.

**Tier**: V1


#### Scenario: Create a result type with archival rules

- GIVEN the admin is editing case type "Omgevingsvergunning"
- WHEN the admin creates a result type:
  - name: "Vergunning verleend"
  - archiveAction: `retain`
  - retentionPeriod: "P20Y"
  - retentionDateSource: `case_completed`
- THEN the result type MUST be created and linked to the case type

#### Scenario: Edit a result type's archival rules

- GIVEN result type "Vergunning geweigerd" has retentionPeriod "P10Y"
- WHEN the admin changes retentionPeriod to "P7Y"
- THEN the result type MUST be updated
- AND existing cases that used this result type MUST NOT be retroactively affected

#### Scenario: Attempt to delete a result type that is in use

- GIVEN result type "Vergunning verleend" is referenced by 5 existing case results
- WHEN the admin attempts to delete it
- THEN the system SHOULD warn the admin that 5 cases reference this result type
- AND the system SHOULD either prevent deletion or mark the result type as inactive

---

### REQ-DECISION-001: Decision CRUD

The system SHALL support creating, reading, updating, and deleting formal decisions linked to cases. Decisions represent administrative determinations with potential legal effect, corresponding to ZGW `Besluit`.

**Tier**: V1


#### Scenario: Create a decision on a case

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" is in status "Besluitvorming"
- AND the case type has a decision type "Omgevingsvergunning besluit"
- WHEN the decision maker "dr.k.bakker" records a decision:
  - title: "Omgevingsvergunning verleend Keizersgracht 100"
  - description: "Vergunning verleend voor de verbouwing conform ingediende bouwtekeningen."
  - decisionType: UUID of "Omgevingsvergunning besluit"
  - effectiveDate: "2026-03-01"
  - expiryDate: "2031-03-01"
- THEN the system MUST create a decision object with `decidedBy`: "dr.k.bakker" and `decidedAt`: current timestamp
- AND the decision MUST appear in the Decisions section of the case detail view

#### Scenario: View decisions on case detail

- GIVEN case #2024-042 has 2 decisions
- WHEN the user views the case detail
- THEN both decisions MUST be displayed in the Decisions section sorted by decidedAt descending
- AND each decision MUST show: title, decided by, decided at, validity period, decision type

#### Scenario: Update a decision's description

- GIVEN decision "Omgevingsvergunning verleend" exists on case #2024-042
- WHEN the decision maker updates the description to add additional conditions
- THEN the decision object MUST be updated via the OpenRegister API
- AND the audit trail MUST record the modification

#### Scenario: Delete a decision

- GIVEN decision "Voorwaardelijk gebruik terrein" exists on case #2024-042
- WHEN the user deletes the decision
- THEN the decision object MUST be removed from OpenRegister
- AND the audit trail MUST record the deletion

---

### REQ-DECISION-002: Decision Validity Periods

The system SHALL support tracking the validity period of decisions (effectiveDate to expiryDate) and provide indicators when decisions are nearing expiry or have expired.

**Tier**: V1


#### Scenario: Active decision display

- GIVEN a decision with effectiveDate "2026-01-01" and expiryDate "2031-01-01"
- AND today is 2026-06-15
- THEN the decision MUST be displayed as "Active"
- AND the remaining validity SHOULD be displayed (e.g., "4 years, 6 months remaining")

#### Scenario: Decision nearing expiry

- GIVEN a decision with expiryDate "2026-03-15"
- AND today is 2026-02-25 (18 days before expiry)
- THEN the decision SHOULD show an amber warning indicator: "Expires in 18 days"

#### Scenario: Expired decision

- GIVEN a decision with expiryDate "2025-12-31"
- AND today is 2026-02-25
- THEN the decision MUST be displayed as "Expired" with a red indicator

#### Scenario: Decision without expiry date

- GIVEN a decision with effectiveDate "2026-03-01" and no expiryDate
- THEN the validity MUST be displayed as "From Mar 1, 2026" (no end date)
- AND the decision MUST be treated as indefinitely valid once effective

---

### REQ-DECISION-003: Decision Types from Case Type

The system SHALL support linking decision types to case types. When creating a decision on a case, only decision types allowed by the case's case type SHOULD be offered.

**Tier**: V1


#### Scenario: Only allowed decision types are available

- GIVEN case type "Omgevingsvergunning" has decision types:
  - "Omgevingsvergunning besluit" (publicationRequired: true, objectionPeriod: "P6W")
  - "Voorlopige voorziening" (publicationRequired: false)
- WHEN the user creates a decision on a case of this type
- THEN only these two decision types MUST be available for selection
- AND the user MAY also create a decision without a decision type (free-form)

#### Scenario: Decision type provides publication rules

- GIVEN decision type "Omgevingsvergunning besluit" has publicationRequired: true and publicationPeriod: "P6W"
- WHEN a decision of this type is created
- THEN the system SHOULD indicate that the decision requires publication
- AND the publication deadline SHOULD be calculated from the decidedAt date

#### Scenario: Create a decision without a decision type

- GIVEN a case where the case type has decision types configured
- WHEN the user creates a decision and leaves decision type empty
- THEN the system MUST allow the decision to be created
- AND all other required fields (title, case) MUST still be validated

---

### REQ-DECISION-004: Decision Validation

The system MUST validate decision data to ensure consistency and completeness.

**Tier**: V1


#### Scenario: Required fields validation

- GIVEN the user is creating a new decision
- WHEN the user submits without a title
- THEN the system MUST reject with "title is required"
- AND submitting without a case reference MUST be rejected with "case is required"

#### Scenario: Expiry date must be after effective date

- GIVEN the user sets effectiveDate "2026-03-01" and expiryDate "2026-02-01"
- WHEN the user submits the decision
- THEN the system MUST reject the request: "expiryDate must be after effectiveDate"

#### Scenario: DecidedBy should be a valid user

- GIVEN the user sets decidedBy to "nonexistent.user"
- WHEN the user submits the decision
- THEN the system SHOULD warn that the user does not exist
- AND the system MAY allow the value for external decision makers

---

### REQ-DECISION-005: Decisions Section on Case Detail

The case detail view MUST display all decisions linked to the case with their validity status.

**Tier**: V1


#### Scenario: Decisions section with no decisions

- GIVEN case #2024-042 has no decisions recorded
- WHEN the user views the case detail
- THEN the Decisions section MUST display "(no decisions yet)"
- AND an "Add Decision" button MUST be visible

#### Scenario: Decisions section with multiple decisions

- GIVEN case #2024-042 has 2 decisions with different validity states
- WHEN the user views the case detail
- THEN both decisions MUST be listed with: title, decided by, decided at, validity period, decision type
- AND each decision MUST be clickable to view/edit details
- AND validity indicators MUST show active/expired/not-yet-effective status

#### Scenario: Decision card shows publication indicator

- GIVEN a decision of type "Omgevingsvergunning besluit" (publicationRequired: true)
- WHEN the decision is displayed on the case detail
- THEN a publication indicator MUST be shown (e.g., "Publication required" badge)
- AND the publication deadline SHOULD be displayed if calculable

---

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
- **ParticipantsSection**: `src/views/cases/components/ParticipantsSection.vue` -- displays all roles grouped by role type name. Resolves participant display names via Nextcloud OCS API (`/ocs/v2.php/cloud/users/{uid}`). Shows initials avatar, role type label, participant name. Supports "Add Participant", "Reassign" (handler), and "Remove" (non-handler) actions.
- **AddParticipantDialog**: `src/views/cases/components/AddParticipantDialog.vue` -- dialog for adding participants with role type selection and user picker.
- **Handler reassignment**: Inline reassign UI with NcSelect user picker. Updates both role `participant` and case `assignee`.
- **Role schema**: Defined in `procest_register.json` with `name`, `description`, `roleType`, `case`, `participant`.
- **RoleType schema**: Defined in `procest_register.json` with `name`, `caseType`, `genericRole` enum: `initiator`, `handler`, `advisor`, `decision_maker`, `stakeholder`, `coordinator`, `contact`, `co_initiator`.
- **Data fetching**: Roles via `objectStore.fetchCollection('role', { '_filters[case]': caseId })`. Role types fetched in parallel.
- **Display name resolution**: `resolveDisplayNames()` fetches Nextcloud user info per participant UID.
- **User picker**: `fetchUsers()` fetches from `/ocs/v2.php/cloud/users/details`.

**Roles -- Not yet implemented:**
- **REQ-ROLE-002: Role type enforcement (V1)**: No validation that assigned role types belong to the case's case type.
- **REQ-ROLE-004: Role-based case access (V1)**: No RBAC enforcement based on role assignments.
- **Notifications**: No Nextcloud notification on handler assignment/reassignment.
- **External contacts**: Only Nextcloud users supported as participants.

**Results -- Partially implemented:**
- **ResultSection**: `src/views/cases/components/ResultSection.vue` -- displays result with name, description, resolved result type.
- **Result/ResultType schemas**: Defined in `procest_register.json`.
- **Not implemented**: Result creation UI (selecting from predefined types during closure), archival metadata display, one-result-per-case enforcement.

**Decisions -- Not implemented:**
- **Decision/DecisionType schemas**: Defined in `procest_register.json`.
- **No UI exists** for decisions on cases. No validity period tracking. No publication indicators.
- **ZGW BRC controller**: `lib/Controller/BrcController.php` provides ZGW-compliant decision API endpoints, but no frontend consumes them.

### Standards & References

- **ZGW APIs (VNG Realisatie)**: Roles map to ZGW `Rol` with `omschrijvingGeneriek`. Results map to `Resultaat` with archival rules. Decisions map to `Besluit`. Full ZGW BRC controller implemented.
- **Schema.org**: Roles typed as `schema:Role`, decisions as `schema:ChooseAction`.
- **CMMN 1.1**: Role assignments follow CMMN case participant patterns.
- **Archiefwet**: Result types implement selectielijst concepts (retain/destroy, retention period).
- **WCAG 2.1 AA**: ParticipantsSection uses sufficient contrast and text labels.
- **Wet open overheid (WOO)**: Decision type publication requirements align with WOO obligations.
- **Competitive reference**: Dimpact ZAC (OPA-based 51+ permissions, formal besluit with publication), xxllnc Zaken (4-level case authorization), ArkCase (participant-based row-level ACL), Flowable (identity links with delegation).

### Specificity Assessment

- **Roles**: Well-specified and mostly implemented. MVP scenarios are clear and actionable.
- **Results**: Well-specified but implementation is incomplete. The result creation flow during case closure needs UI work.
- **Decisions**: Well-specified but entirely unimplemented in the frontend. Data model and ZGW API exist.
- **Open questions:**
  - Should role type enforcement be strict (reject) or advisory (warn)?
  - How should external contacts (non-Nextcloud users) be represented as participants?
  - Should decision publication trigger an n8n workflow or a direct API call?
  - How does the result creation flow interact with case status transitions?
