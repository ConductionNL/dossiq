---
status: done
retrofit_extensions:
  - REQ-CT-17
  - REQ-CT-18
---

# Case Type System Specification

## Purpose

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
└── subCaseTypes[]       — Allowed sub-case types
```

**Standards**: CMMN 1.1 (CaseDefinition), ZGW Catalogi API (ZaakType), Schema.org (`PropertyValueSpecification`), OIO Sag og Dokument (Denmark)
**Feature tier**: MVP (core type CRUD, statuses, deadlines, draft/published, validity), V1 (result types, role types, property definitions, document types, decision types, confidentiality, suspension/extension)

## Data Model

### Case Type Entity

| Property | Type | CMMN / Schema.org | ZGW Mapping | Required |
|----------|------|-------------------|-------------|----------|
| `title` | string | `schema:name` | `zaaktype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `identifier` | string | `schema:identifier` | `identificatie` | Auto |
| `purpose` | string | -- | `doel` | Yes |
| `trigger` | string | -- | `aanleiding` | Yes |
| `subject` | string | -- | `onderwerp` | Yes |
| `initiatorAction` | string | -- | `handeling_initiator` | Yes |
| `handlerAction` | string | -- | `handeling_behandelaar` | Yes |
| `origin` | enum: internal, external | -- | `indicatie_intern_of_extern` | Yes |
| `processingDeadline` | duration (ISO 8601) | CMMN TimerEventListener | `doorlooptijd_behandeling` | Yes |
| `serviceTarget` | duration (ISO 8601) | -- | `servicenorm_behandeling` | No |
| `suspensionAllowed` | boolean | -- | `opschorting_en_aanhouding_mogelijk` | Yes |
| `extensionAllowed` | boolean | -- | `verlenging_mogelijk` | Yes |
| `extensionPeriod` | duration (ISO 8601) | -- | `verlengingstermijn` | Conditional (required if extensionAllowed) |
| `confidentiality` | enum | -- | `vertrouwelijkheidaanduiding` | Yes |
| `publicationRequired` | boolean | -- | `publicatie_indicatie` | Yes |
| `publicationText` | string | -- | `publicatietekst` | No |
| `responsibleUnit` | string | -- | `verantwoordelijke` | Yes |
| `referenceProcess` | string | -- | `referentieproces_naam` | No |
| `isDraft` | boolean | -- | `concept` | No (default: true) |
| `validFrom` | date | -- | `datum_begin_geldigheid` | Yes |
| `validUntil` | date | -- | `datum_einde_geldigheid` | No |
| `keywords` | string[] | -- | `trefwoorden` | No |
| `subCaseTypes` | reference[] | CMMN CaseTask | `deelzaaktypen` | No |

### Status Type Entity

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `statustype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `order` | integer (1-9999) | CMMN Milestone sequence | `statustypevolgnummer` | Yes |
| `isFinal` | boolean | CMMN terminal state | (last in order) | No (default: false) |
| `targetDuration` | duration | -- | `doorlooptijd` | No |
| `notifyInitiator` | boolean | -- | `informeren` | No (default: false) |
| `notificationText` | string | -- | `statustekst` | No |

### Result Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `archiveAction` | enum: retain, destroy | -- | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601) | -- | `archiefactietermijn` | No |
| `retentionDateSource` | enum | -- | `afleidingswijze` | No |

### Role Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:roleName` | `omschrijving` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `genericRole` | enum | -- | `omschrijvingGeneriek` | Yes |

### Property Definition Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `eigenschapnaam` | Yes |
| `definition` | string | `schema:description` | `definitie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `format` | enum: text, number, date, datetime | -- | `formaat` | Yes |
| `maxLength` | integer | -- | `lengte` | No |
| `allowedValues` | string[] | -- | `waardenverzameling` | No |
| `requiredAtStatus` | reference | Status at which this must be filled | `statustype` | No |

### Document Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `category` | string | -- | `informatieobjectcategorie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `direction` | enum: incoming, internal, outgoing | -- | `richting` | Yes |
| `order` | integer | -- | `volgnummer` | Yes |
| `confidentiality` | enum | -- | `vertrouwelijkheidaanduiding` | No |
| `requiredAtStatus` | reference | Status requiring this document | `statustype` | No |

### Decision Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `category` | string | -- | `besluitcategorie` | No |
| `objectionPeriod` | duration (ISO 8601) | -- | `reactietermijn` | No |
| `publicationRequired` | boolean | -- | `publicatie_indicatie` | Yes |
| `publicationPeriod` | duration (ISO 8601) | -- | `publicatietermijn` | No |

## Requirements

---

### REQ-CT-01: Case Type CRUD

The system MUST support creating, reading, updating, and deleting case types. Case types are managed by admins via the Nextcloud admin settings page. See wireframe 3.6 (Admin Settings -- Case Type Management) in DESIGN-REFERENCES.md.

**Feature tier**: MVP

#### Scenario CT-01a: Create a case type

- GIVEN an admin on the Dossiq settings page
- WHEN they click "Add Case Type" and fill in:
  - Title: "Omgevingsvergunning"
  - Purpose: "Beoordelen bouwplannen"
  - Trigger: "Aanvraag van burger/bedrijf"
  - Subject: "Bouw- en verbouwactiviteiten"
  - Processing deadline: "P56D" (56 days)
  - Origin: "external"
  - Confidentiality: "internal"
  - Responsible unit: "Afdeling Vergunningen, Gemeente Amsterdam"
  - Valid from: "2026-01-01"
- AND submits the form
- THEN the system MUST create an OpenRegister object in the `dossiq` register with the `caseType` schema
- AND `isDraft` MUST default to `true`
- AND a unique `identifier` MUST be auto-generated

#### Scenario CT-01b: Update a case type

- GIVEN an existing case type "Omgevingsvergunning"
- WHEN the admin changes the `processingDeadline` from "P56D" to "P42D"
- THEN the system MUST update the OpenRegister object
- AND the change MUST NOT affect existing cases (only new cases use the updated deadline)

#### Scenario CT-01c: Delete a case type with no active cases

- GIVEN a case type "Testtype" that has no cases associated with it
- WHEN the admin deletes the case type
- THEN the system MUST remove the case type and all linked sub-types (status types, result types, role types, property definitions, document types, decision types)
- AND a confirmation dialog MUST be shown before deletion

#### Scenario CT-01d: Delete a case type with active cases -- blocked

- GIVEN a case type "Omgevingsvergunning" with 10 active cases
- WHEN the admin attempts to delete the case type
- THEN the system MUST reject the deletion
- AND display: "Cannot delete case type 'Omgevingsvergunning': 10 active cases are using this type. Close or reassign all cases first."

#### Scenario CT-01e: Case type list display

- GIVEN case types: "Omgevingsvergunning" (published, default), "Subsidieaanvraag" (published), "Klacht behandeling" (published), "Bezwaarschrift" (draft)
- WHEN the admin views the case type list
- THEN each case type MUST display: title, status (Published/Draft), deadline, number of statuses, number of result types, validity period
- AND the default case type MUST be visually indicated (e.g., star icon)
- AND draft types MUST be visually distinct (e.g., warning badge)

---

### REQ-CT-02: Case Type Draft/Published Lifecycle

The system MUST support a draft/published lifecycle for case types. Draft case types MUST NOT be usable for creating cases.

**Feature tier**: MVP

#### Scenario CT-02a: New case type defaults to draft

- GIVEN an admin creating a new case type
- WHEN the case type is created
- THEN `isDraft` MUST be `true` by default
- AND the case type MUST show a "DRAFT" badge in the admin list

#### Scenario CT-02b: Publish a case type -- success

- GIVEN a draft case type "Subsidieaanvraag" with:
  - All required fields filled (title, purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit, validFrom)
  - At least one status type defined: "Ontvangen" (order 1), "In behandeling" (order 2), "Afgerond" (order 3, isFinal = true)
- WHEN the admin sets `isDraft = false`
- THEN the case type MUST become "Published"
- AND the case type MUST become available for creating new cases

#### Scenario CT-02c: Publish a case type -- blocked, no status types

- GIVEN a draft case type "Bezwaarschrift" with no status types defined
- WHEN the admin attempts to publish (set `isDraft = false`)
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type 'Bezwaarschrift': at least one status type must be defined"

#### Scenario CT-02d: Publish a case type -- blocked, no final status

- GIVEN a draft case type with 2 status types, neither marked `isFinal = true`
- WHEN the admin attempts to publish
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type: at least one status type must be marked as final"

#### Scenario CT-02e: Publish a case type -- blocked, validFrom not set

- GIVEN a draft case type with `validFrom` not set
- WHEN the admin attempts to publish
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type: 'Valid from' date must be set"

#### Scenario CT-02f: Unpublish a case type

- GIVEN a published case type "Klacht behandeling" with 3 active cases
- WHEN the admin sets `isDraft = true` (unpublish)
- THEN the system MUST warn: "Unpublishing this case type will prevent new cases from being created. 3 existing cases will continue to function."
- AND if confirmed, the case type MUST revert to draft
- AND existing cases MUST NOT be affected

---

### REQ-CT-03: Case Type Validity Periods

The system MUST support validity windows on case types. Cases can only be created with case types that are within their validity window.

**Feature tier**: MVP

#### Scenario CT-03a: Case type within validity window

- GIVEN a case type "Omgevingsvergunning" with `validFrom = "2026-01-01"` and `validUntil = "2027-12-31"`
- AND today is "2026-06-15"
- WHEN a user views the case type in the creation dropdown
- THEN the case type MUST be available for selection

#### Scenario CT-03b: Case type expired

- GIVEN a case type "Bouwvergunning 2024" with `validUntil = "2025-12-31"`
- AND today is "2026-02-25"
- WHEN a user views the case creation form
- THEN this case type MUST NOT appear in the dropdown (or MUST appear greyed out with "Expired" label)
- AND if selected via API, the system MUST reject with: "Case type 'Bouwvergunning 2024' expired on 2025-12-31"

#### Scenario CT-03c: Case type not yet valid

- GIVEN a case type "Nieuwe Subsidie 2027" with `validFrom = "2027-01-01"`
- AND today is "2026-02-25"
- WHEN a user views the case creation form
- THEN this case type MUST NOT appear in the dropdown (or MUST appear greyed out with "Not yet valid" label)

#### Scenario CT-03d: Case type with no end date

- GIVEN a case type "Klacht behandeling" with `validFrom = "2026-01-01"` and `validUntil` not set
- AND today is "2030-12-31"
- WHEN a user views the case creation form
- THEN the case type MUST be available (no expiry)

#### Scenario CT-03e: Validity displayed in admin list

- GIVEN case types with varying validity periods
- WHEN the admin views the case type list
- THEN each type MUST display its validity range: "Valid: Jan 2026 -- Dec 2027" or "Valid: Jan 2026 -- (no end)"

---

### REQ-CT-04: Status Type Management

The system MUST support defining ordered status types for each case type. Status types control the lifecycle phases a case can go through. See wireframe 3.7 (Admin Settings -- Case Type Detail) in DESIGN-REFERENCES.md.

**Feature tier**: MVP

#### Scenario CT-04a: Add status types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds the following status types:
  1. "Ontvangen" (order: 1)
  2. "In behandeling" (order: 2, notifyInitiator: true, notificationText: "Uw zaak is in behandeling genomen")
  3. "Besluitvorming" (order: 3)
  4. "Afgehandeld" (order: 4, isFinal: true, notifyInitiator: true, notificationText: "Uw zaak is afgehandeld")
- THEN each status type MUST be created as an OpenRegister object linked to the case type
- AND they MUST be ordered by the `order` field
- AND the admin MUST see the ordered list with drag handles for reordering

#### Scenario CT-04b: Reorder status types via drag

- GIVEN a case type with status types in order: [Ontvangen(1), In behandeling(2), Besluitvorming(3), Afgehandeld(4)]
- WHEN the admin drags "Besluitvorming" before "In behandeling"
- THEN the `order` values MUST be recalculated: [Ontvangen(1), Besluitvorming(2), In behandeling(3), Afgehandeld(4)]
- AND the change MUST be persisted

#### Scenario CT-04c: Edit a status type

- GIVEN a status type "In behandeling" (order 2) on case type "Omgevingsvergunning"
- WHEN the admin changes `notifyInitiator` from false to true and sets `notificationText` to "Uw zaak is in behandeling genomen"
- THEN the status type MUST be updated
- AND the change MUST apply to future status transitions (not retroactive)

#### Scenario CT-04d: Delete a status type

- GIVEN a case type "Omgevingsvergunning" with 4 status types
- AND no active cases are currently at the status "Besluitvorming"
- WHEN the admin deletes the "Besluitvorming" status type
- THEN the status type MUST be removed
- AND the remaining status types MUST retain their relative order

#### Scenario CT-04e: Cannot delete status type in use

- GIVEN a case type "Omgevingsvergunning"
- AND 3 active cases are currently at status "In behandeling"
- WHEN the admin attempts to delete "In behandeling"
- THEN the system MUST reject the deletion
- AND display: "Cannot delete status type 'In behandeling': 3 active cases are currently at this status"

#### Scenario CT-04f: At least one final status required

- GIVEN a case type with 3 status types, one marked `isFinal = true`
- WHEN the admin attempts to unmark the final status (set `isFinal = false`)
- AND no other status is marked as final
- THEN the system MUST reject the change
- AND display: "At least one status type must be marked as final"

#### Scenario CT-04g: Status type order is required

- GIVEN an admin adding a new status type
- WHEN they submit without setting the `order` field
- THEN the system MUST reject the submission
- AND display: "Order is required for status types"

#### Scenario CT-04h: Status type name is required

- GIVEN an admin adding a new status type
- WHEN they submit with an empty `name`
- THEN the system MUST reject the submission
- AND display: "Status type name is required"

#### Scenario CT-04i: Status type notification fields

- GIVEN a status type with `notifyInitiator = true`
- WHEN displayed in the admin edit view
- THEN the notification checkbox MUST be checked
- AND the notification text field MUST be visible and editable
- AND the notification text SHOULD be displayed below the status name in the ordered list

---

### REQ-CT-05: Processing Deadline Configuration

The system MUST support configuring a processing deadline on each case type. The deadline is an ISO 8601 duration that controls automatic deadline calculation on cases.

**Feature tier**: MVP

#### Scenario CT-05a: Set processing deadline

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `processingDeadline = "P56D"` (56 days)
- THEN the system MUST store the duration in ISO 8601 format
- AND the admin UI MUST display this as "56 days"

#### Scenario CT-05b: Invalid processing deadline format

- GIVEN a case type in edit mode
- WHEN the admin enters "56 days" (not ISO 8601) as the processing deadline
- THEN the system MUST reject the input
- AND display: "Processing deadline must be a valid ISO 8601 duration (e.g., P56D for 56 days, P8W for 8 weeks)"

#### Scenario CT-05c: Service target (optional)

- GIVEN a case type "Omgevingsvergunning" with `processingDeadline = "P56D"`
- WHEN the admin also sets `serviceTarget = "P42D"` (42 days)
- THEN the service target MUST be stored separately
- AND cases SHOULD display both the service target and the hard deadline

#### Scenario CT-05d: Deadline calculation on case creation

- GIVEN a case type with `processingDeadline = "P56D"`
- WHEN a case is created with `startDate = "2026-03-01"`
- THEN the case `deadline` MUST be calculated as "2026-04-26" (March 1 + 56 days)

---

### REQ-CT-06: Extension and Suspension Configuration

The system MUST support configuring extension and suspension rules on case types.

**Feature tier**: MVP (extension), V1 (suspension)

#### Scenario CT-06a: Enable extension with period

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `extensionAllowed = true` and `extensionPeriod = "P28D"`
- THEN cases of this type MUST allow one deadline extension of 28 days

#### Scenario CT-06b: Extension period required when extension allowed

- GIVEN a case type with `extensionAllowed = true`
- WHEN the admin leaves `extensionPeriod` empty
- THEN the system MUST reject the save
- AND display: "Extension period is required when extension is allowed"

#### Scenario CT-06c: Disable extension

- GIVEN a case type "Klacht behandeling" in edit mode
- WHEN the admin sets `extensionAllowed = false`
- THEN the `extensionPeriod` field MUST be hidden or disabled
- AND cases of this type MUST NOT allow deadline extensions

#### Scenario CT-06d: Enable suspension (V1)

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `suspensionAllowed = true`
- THEN cases of this type MUST allow suspension (pausing the deadline countdown)

#### Scenario CT-06e: Disable suspension (V1)

- GIVEN a case type "Melding" with `suspensionAllowed = false`
- WHEN a handler attempts to suspend a case of this type
- THEN the system MUST reject the suspension

---

### REQ-CT-07: Result Type Management

The system SHALL support defining result types with archival rules for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

**Feature tier**: V1

#### Scenario CT-07a: Add result types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds result types:
  - "Vergunning verleend" (archiveAction: retain, retentionPeriod: P20Y, retentionDateSource: case_completed)
  - "Vergunning geweigerd" (archiveAction: destroy, retentionPeriod: P10Y, retentionDateSource: case_completed)
  - "Ingetrokken" (archiveAction: destroy, retentionPeriod: P5Y, retentionDateSource: case_completed)
- THEN each result type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, archive action, retention period

#### Scenario CT-07b: Edit a result type

- GIVEN a result type "Vergunning verleend" with `retentionPeriod = "P20Y"`
- WHEN the admin changes `retentionPeriod` to "P25Y"
- THEN the result type MUST be updated
- AND the change MUST apply to future case closures only

#### Scenario CT-07c: Delete a result type

- GIVEN a result type "Ingetrokken" not referenced by any closed cases
- WHEN the admin deletes it
- THEN the result type MUST be removed from the case type

#### Scenario CT-07d: Delete result type in use -- blocked

- GIVEN a result type "Vergunning verleend" referenced by 5 closed cases
- WHEN the admin attempts to delete it
- THEN the system MUST reject the deletion
- AND display: "Cannot delete result type 'Vergunning verleend': referenced by 5 closed cases"

#### Scenario CT-07e: Retention date source options

- GIVEN the result type edit form
- WHEN the admin selects the `retentionDateSource` dropdown
- THEN the options MUST include: case_completed, decision_effective, decision_expiry, fixed_period, related_case, parent_case, custom_property, custom_date

---

### REQ-CT-08: Role Type Management

The system SHALL support defining allowed role types for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

**Feature tier**: V1

#### Scenario CT-08a: Add role types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds role types:
  - "Aanvrager" (genericRole: initiator)
  - "Behandelaar" (genericRole: handler)
  - "Technisch adviseur" (genericRole: advisor)
  - "Beslisser" (genericRole: decision_maker)
- THEN each role type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, generic role

#### Scenario CT-08b: Generic role options

- GIVEN the role type creation form
- WHEN the admin selects the `genericRole` dropdown
- THEN the options MUST include: initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator

#### Scenario CT-08c: Role types restrict case role assignment

- GIVEN a case of type "Omgevingsvergunning" with role types ["Aanvrager", "Behandelaar", "Technisch adviseur", "Beslisser"]
- WHEN a user adds a participant to the case
- THEN the role selection MUST only show roles from the case type's role type list
- AND the user MUST NOT be able to assign "Zaakcoordinator" if it is not defined

#### Scenario CT-08d: Edit a role type

- GIVEN a role type "Technisch adviseur" with genericRole "advisor"
- WHEN the admin renames it to "Externe adviseur"
- THEN the name MUST be updated
- AND existing role assignments on cases MUST reflect the new name

#### Scenario CT-08e: Delete a role type not in use

- GIVEN a role type "Beslisser" not assigned on any active cases
- WHEN the admin deletes it
- THEN the role type MUST be removed from the case type

---

### REQ-CT-09: Property Definition Management

The system SHALL support defining custom field requirements for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

**Feature tier**: V1

#### Scenario CT-09a: Add property definitions

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds property definitions:
  - "Kadastraal nummer" (format: text, maxLength: 20, requiredAtStatus: "In behandeling")
  - "Bouwkosten" (format: number, requiredAtStatus: "Besluitvorming")
  - "Oppervlakte" (format: number, no requiredAtStatus)
  - "Bouwlagen" (format: number, no requiredAtStatus)
- THEN each property definition MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, format, max length (if set), required at status (if set)

#### Scenario CT-09b: Property format options

- GIVEN the property definition creation form
- WHEN the admin selects the `format` dropdown
- THEN the options MUST include: text, number, date, datetime

#### Scenario CT-09c: Property with allowed values (enum)

- GIVEN the admin creating a property definition "Bouwtype"
- WHEN they set `allowedValues = ["Nieuwbouw", "Verbouw", "Uitbreiding", "Renovatie"]`
- THEN cases of this type MUST only accept values from this list for the "Bouwtype" field

#### Scenario CT-09d: Property required at status blocks status change

- GIVEN a property "Kadastraal nummer" with `requiredAtStatus` referencing "In behandeling"
- AND a case that has not filled this property
- WHEN the user attempts to advance the case to "In behandeling"
- THEN the system MUST reject the status change
- AND display: "Cannot advance to 'In behandeling': required property 'Kadastraal nummer' is missing"

#### Scenario CT-09e: Property with maxLength validation

- GIVEN a property "Kadastraal nummer" with `maxLength = 20`
- WHEN a user enters a value with 25 characters
- THEN the system MUST reject the input
- AND display: "Value exceeds maximum length of 20 characters"

#### Scenario CT-09f: Delete a property definition

- GIVEN a property definition "Oppervlakte" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the property definition MUST be removed
- AND existing property values on cases SHOULD be preserved (not deleted) but the field SHOULD no longer appear for new cases

---

### REQ-CT-10: Document Type Management

The system SHALL support defining required document types for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

**Feature tier**: V1

#### Scenario CT-10a: Add document types

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds document types:
  - "Bouwtekening" (category: "Tekening", direction: incoming, order: 1, requiredAtStatus: "In behandeling")
  - "Constructieberekening" (category: "Tekening", direction: incoming, order: 2, requiredAtStatus: "In behandeling")
  - "Situatietekening" (category: "Tekening", direction: incoming, order: 3, requiredAtStatus: "In behandeling")
  - "Welstandsadvies" (category: "Advies", direction: internal, order: 4, requiredAtStatus: "Besluitvorming")
  - "Vergunningsbesluit" (category: "Besluit", direction: outgoing, order: 5, requiredAtStatus: "Afgehandeld")
- THEN each document type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, direction, required at status

#### Scenario CT-10b: Direction options

- GIVEN the document type creation form
- WHEN the admin selects the `direction` dropdown
- THEN the options MUST include: incoming, internal, outgoing

#### Scenario CT-10c: Document type required at status blocks status change

- GIVEN a document type "Welstandsadvies" with `requiredAtStatus` referencing "Besluitvorming"
- AND a case that has no "Welstandsadvies" file uploaded
- WHEN the user attempts to advance the case to "Besluitvorming"
- THEN the system MUST reject the status change
- AND display: "Cannot advance to 'Besluitvorming': required document 'Welstandsadvies' is missing"

#### Scenario CT-10d: Document type with confidentiality

- GIVEN a document type "Vergunningsbesluit" with `confidentiality = "case_sensitive"`
- WHEN a file of this type is uploaded to a case
- THEN the file SHOULD inherit the confidentiality level "case_sensitive"

#### Scenario CT-10e: Delete a document type

- GIVEN a document type "Situatietekening" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the document type MUST be removed from the case type
- AND existing uploaded files MUST NOT be deleted (files remain, only the requirement is removed)

---

### REQ-CT-11: Decision Type Management

The system SHALL support defining decision types for each case type.

**Feature tier**: V1

#### Scenario CT-11a: Add decision types

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds a decision type:
  - Name: "Vergunningsbesluit"
  - Category: "Vergunning"
  - Objection period: "P42D" (42 days)
  - Publication required: true
  - Publication period: "P14D" (14 days)
- THEN the decision type MUST be created as an OpenRegister object linked to the case type

#### Scenario CT-11b: Decision type restricts case decisions

- GIVEN a case of type "Omgevingsvergunning" with decision type "Vergunningsbesluit"
- WHEN a user creates a decision on the case
- THEN the decision type selection MUST only show types defined by the case type

#### Scenario CT-11c: Decision type with objection period

- GIVEN a decision type "Vergunningsbesluit" with `objectionPeriod = "P42D"`
- WHEN a decision of this type is recorded with `effectiveDate = "2026-03-01"`
- THEN the system SHOULD calculate and display the objection deadline: "2026-04-12"

---

### REQ-CT-12: Confidentiality Default

The system SHALL support confidentiality defaults on case types. Cases inherit the case type's confidentiality level.

**Feature tier**: V1

#### Scenario CT-12a: Set confidentiality default

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `confidentiality = "internal"`
- THEN new cases of this type MUST default to confidentiality "internal"

#### Scenario CT-12b: Confidentiality level options

- GIVEN the case type confidentiality dropdown
- WHEN the admin opens the dropdown
- THEN the options MUST include: public, restricted, internal, case_sensitive, confidential, highly_confidential, secret, top_secret
- AND the options MUST be ordered from least to most restrictive

#### Scenario CT-12c: Overriding confidentiality on a case

- GIVEN a case type with `confidentiality = "internal"`
- AND a case created with this type (default "internal")
- WHEN the handler changes the case confidentiality to "confidential"
- THEN the case MUST update to "confidential"
- AND the audit trail MUST record the change

---

### REQ-CT-13: Default Case Type Selection

The system MUST support selecting a default case type in admin settings. The default case type is pre-selected when creating new cases.

**Feature tier**: MVP

#### Scenario CT-13a: Set default case type

- GIVEN case types "Omgevingsvergunning" (published), "Subsidieaanvraag" (published), "Klacht" (published)
- WHEN the admin marks "Omgevingsvergunning" as the default
- THEN "Omgevingsvergunning" MUST appear with a visual indicator (e.g., star) in the admin list
- AND the "New Case" form MUST pre-select "Omgevingsvergunning"

#### Scenario CT-13b: Only published case types can be default

- GIVEN a draft case type "Bezwaarschrift"
- WHEN the admin attempts to mark it as default
- THEN the system MUST reject the action
- AND display: "Only published case types can be set as default"

#### Scenario CT-13c: Change default case type

- GIVEN "Omgevingsvergunning" is the current default
- WHEN the admin sets "Subsidieaanvraag" as the new default
- THEN "Subsidieaanvraag" MUST become the default
- AND "Omgevingsvergunning" MUST lose its default status (only one default at a time)

---

### REQ-CT-14: Case Type Validation Rules

The system MUST enforce validation rules when creating or modifying case types.

**Feature tier**: MVP

#### Scenario CT-14a: Title is required

- GIVEN a case type creation form
- WHEN the admin submits with an empty title
- THEN the system MUST reject with error: "Title is required"

#### Scenario CT-14b: Processing deadline is required

- GIVEN a case type creation form
- WHEN the admin submits without a processing deadline
- THEN the system MUST reject with error: "Processing deadline is required"

#### Scenario CT-14c: Processing deadline must be valid ISO 8601 duration

- GIVEN a case type in edit mode
- WHEN the admin enters "two months" as the processing deadline
- THEN the system MUST reject with error: "Processing deadline must be a valid ISO 8601 duration (e.g., P56D, P8W, P2M)"

#### Scenario CT-14d: Valid ISO 8601 durations accepted

- GIVEN a case type in edit mode
- WHEN the admin enters any of: "P56D" (56 days), "P8W" (8 weeks), "P2M" (2 months), "P1Y" (1 year)
- THEN the system MUST accept the input
- AND display the human-readable equivalent

#### Scenario CT-14e: Required fields for case type

- GIVEN a case type creation form
- WHEN the admin leaves any of these fields empty: purpose, trigger, subject, origin, confidentiality, responsibleUnit
- THEN the system MUST reject the submission
- AND display validation errors for each missing required field

#### Scenario CT-14f: ValidUntil must be after validFrom

- GIVEN a case type with `validFrom = "2026-01-01"`
- WHEN the admin sets `validUntil = "2025-12-31"` (before validFrom)
- THEN the system MUST reject with error: "'Valid until' must be after 'Valid from'"

#### Scenario CT-14g: Extension period required when extension allowed

- GIVEN a case type with `extensionAllowed = true`
- WHEN the admin leaves `extensionPeriod` empty
- THEN the system MUST reject with error: "Extension period is required when extension is allowed"

---

### REQ-CT-15: Case Type Admin UI Tabs

The case type edit page MUST be organized into tabs for managing the type and its sub-types. See wireframe 3.7 in DESIGN-REFERENCES.md.

**Feature tier**: MVP (General, Statuses), V1 (Results, Roles, Properties, Docs)

#### Scenario CT-15a: Tab layout

- GIVEN the admin editing a case type "Omgevingsvergunning"
- WHEN the edit page loads
- THEN the page MUST display tabs: General, Statuses, Results, Roles, Properties, Docs
- AND the "General" tab MUST be active by default
- AND a "Save" button MUST be visible at the top

#### Scenario CT-15b: General tab content

@e2e exclude Three of the fields this scenario enumerates cannot be honestly
asserted today, and one of them is a defect rather than a gap. `serviceTarget`
IS rendered by `GeneralTab.vue` and DOES take input, but it is declared in no
schema, so OpenRegister discards it on save while the page reports success:
measured 2026-09-12, a write of `serviceTarget: "P14D"` came back absent from a
fresh read while `suspensionAllowed` on the same write survived. See #2592. A
test asserting the field renders would pass and would certify data loss, which
is worse than no test. `suspensionAllowed` is in the schema but `GeneralTab`
renders no control for it, and `isDraft` (the published/draft status this
scenario names) has no control on this tab either. The exclusion is protective,
not stale: the rendered control a later reader will see is exactly the problem.

- GIVEN the admin on the "General" tab
- THEN the tab MUST display editable fields for: title, description, purpose, trigger, subject, processing deadline (with ISO 8601 helper), service target, extension allowed (with conditional period), suspension allowed, origin, confidentiality, publication required (with conditional text), valid from, valid until, status (published/draft)

#### Scenario CT-15c: Statuses tab content

@e2e exclude The ordered list, the drag handles, the order number, the name,
the isFinal checkbox and the Add control all ship. `notifyInitiator`, with its
conditional text field, does not exist: it is absent from the `statusType`
schema and from `StatusesTab.vue`. Asserting only the clauses that happen to
pass would be the shape this programme exists to remove, so the scenario is
excluded whole until the field is built or the clause is dropped.

- GIVEN the admin on the "Statuses" tab
- THEN the tab MUST display an ordered list of status types with drag handles
- AND each status type MUST show: order number, name, isFinal checkbox, notifyInitiator checkbox (with conditional text field)
- AND an "Add" button MUST be available

#### Scenario CT-15d: Results tab content (V1)

- GIVEN the admin on the "Results" tab
- THEN the tab MUST display a list of result types
- AND each result type MUST show: name, archive action, retention period
- AND an "Add" button MUST be available

#### Scenario CT-15e: Roles tab content (V1)

- GIVEN the admin on the "Roles" tab
- THEN the tab MUST display a list of role types
- AND each role type MUST show: name, generic role
- AND an "Add" button MUST be available

#### Scenario CT-15f: Properties tab content (V1)

@e2e exclude Wording drift only, and recorded rather than repaired so nobody
re-opens it: every clause ships. The scenario says "format" and
`PropertiesTab.vue` labels the control Type while binding the `format` field,
so the requirement holds and the label does not match it. Worth one rename in
whichever direction the product prefers; not worth a test asserting a label.

- GIVEN the admin on the "Properties" tab
- THEN the tab MUST display a list of property definitions
- AND each property MUST show: name, format, max length (if set), required at status (if set)
- AND an "Add" button MUST be available

#### Scenario CT-15g: Docs tab content (V1)

@e2e exclude The Docs tab ships and lists document types with a name and an
Add control, but `direction` (incoming/internal/outgoing) exists in neither the
`documentType` schema nor `DocumentTypesTab.vue`. The tab instead shows
Category and Confidentiality, which this scenario does not mention, so the
requirement and the surface have drifted apart in both directions.

- GIVEN the admin on the "Docs" tab
- THEN the tab MUST display a list of document types
- AND each document type MUST show: name, direction (incoming/internal/outgoing), required at status (if set)
- AND an "Add" button MUST be available

---

### REQ-CT-16: Case Type Error Scenarios

The system MUST handle error scenarios gracefully for case type operations.

**Feature tier**: MVP

#### Scenario CT-16a: Publish incomplete case type

- GIVEN a case type with title and processing deadline filled but no purpose, trigger, or subject
- WHEN the admin attempts to publish
- THEN the system MUST reject with validation errors listing all missing required fields

#### Scenario CT-16b: Add status type without order

- GIVEN an admin adding a status type to a case type
- WHEN they submit without setting the `order` field
- THEN the system MUST either reject with "Order is required" or auto-assign the next sequential order number

#### Scenario CT-16c: Duplicate status type order

- GIVEN a case type with status type "Ontvangen" at order 1
- WHEN the admin adds a new status type "Intake" also at order 1
- THEN the system MUST reject with error: "A status type with order 1 already exists. Each status type must have a unique order."

#### Scenario CT-16d: Delete case type with closed cases

- GIVEN a case type "Subsidieaanvraag" with 5 closed cases and 0 active cases
- WHEN the admin attempts to delete the case type
- THEN the system MUST warn: "This case type is referenced by 5 closed cases. Deleting it will remove the type reference from those cases."
- AND if confirmed, the deletion SHOULD proceed

### Requirement: Case Type Pre-Seeded Data

@e2e exclude Bezwaar/Beroep case types are V1 seed data imported via repair step; covered by PHPUnit.

The system SHALL provide pre-seeded case types that are imported via the repair step. In addition to any existing pre-seeded case types, the system SHALL now include Bezwaar and Beroep case types with their associated status types, role types, and workflow templates.

**Feature tier**: V1

The repair step SHALL import the following new case types alongside existing ones:

| Case Type | Processing Deadline | Extension | Suspension | Origin |
|-----------|-------------------|-----------|------------|--------|
| Bezwaar | P6W | P6W | Yes | external |
| Beroep | P26W | No | Yes | external |

Each case type SHALL include its associated:
- Status types (see bezwaar-lifecycle and beroep-escalation specs)
- Role types (see bezwaar-lifecycle spec)
- Workflow template (see workflow-definition-model spec)

#### Scenario: Bezwaar and Beroep case types are available after installation

- **WHEN** the Dossiq app repair step runs for the first time or after an update
- **THEN** case types "Bezwaar" and "Beroep" SHALL exist in the dossiq register
- **AND** each SHALL have its complete set of status types, role types, and an active workflow template
- **AND** existing case types SHALL NOT be affected by the addition

#### Scenario: Pre-seeded case types are not duplicated on re-run

- **WHEN** the repair step runs again on an installation that already has Bezwaar and Beroep case types
- **THEN** the system SHALL NOT create duplicate case types
- **AND** existing customizations to the case types SHALL be preserved

<!-- BEGIN retrofit-2026-05-24-case-types -->

### REQ-CT-17: Dossiq SHALL expose case-definition export endpoints + ZIP package format

@e2e exclude Backend PHP export controller spec; ZIP download and import covered by PHPUnit.

`OCA\Dossiq\Controller\CaseDefinitionController` SHALL provide `GET /api/case-definitions/{id}/export` that returns a ZIP package (via `DataDownloadResponse`) containing the case type and all linked dependencies (workflow templates, role/group mappings, document templates) needed for round-trip portability to another dossiq instance. The ZIP SHALL be produced by `CaseDefinitionExportService::exportCaseDefinition()` and SHALL embed a `manifest.json` describing the package schema version, source instance, and contained object refs.

#### Scenario: Export a published case type
- **GIVEN** a published case type with workflow templates + roles
- **WHEN** a behandelaar calls `GET /api/case-definitions/{id}/export`
- **THEN** the response SHALL be a ZIP download containing `case-type.json`, all linked `workflow-template-*.json` files, role/group mapping definitions, and a top-level `manifest.json`

### REQ-CT-18: Dossiq SHALL validate + import case-definition packages with explicit conflict reporting

@e2e exclude Backend PHP import service spec; package validation and import covered by PHPUnit.

`CaseDefinitionImportService::validatePackage()` SHALL inspect a ZIP package, parse `manifest.json`, and return a structured report of: (a) missing required files, (b) schema-version compatibility, (c) name/slug collisions against existing case types and templates, and (d) cross-reference integrity. Validation SHALL be a pure read — no side effects on the dossiq instance.

`CaseDefinitionImportService::importCaseDefinition()` SHALL run validation first, then create the case type and all linked objects atomically. On collision, the importer SHALL accept a caller-provided `conflictResolution` mode (`reject`, `rename`, `replace`) and SHALL surface its decisions in the response so the admin can audit what was created versus replaced.

`CaseDefinitionController::validate()` SHALL expose validation-only HTTP access (`POST /api/case-definitions/import?dryRun=true`) so admins can review a package before committing.

#### Scenario: Dry-run validates without persisting
- **WHEN** an admin calls `POST /api/case-definitions/import?dryRun=true` with a ZIP body
- **THEN** the response SHALL include the structured validation report and no objects SHALL be created

<!-- END retrofit-2026-05-24-case-types -->

---

### Requirement: You give each status a colour and a list visibility (REQ-CT-19)

You give each status a colour and choose whether it shows in lists. The
`statusType` schema SHALL carry `colour`, one of the NL Design System hue
names, and `hiddenInLists`, a boolean. The status badge on the case and the
Workflow board column SHALL render in that colour. The Cases index SHALL
leave cases in a hidden status out unless you ask for closed cases.

**Feature tier**: MVP

#### Scenario: A coloured status shows on the board
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the status In behandeling of a type has the colour orange
- **WHEN** you open the Workflow board for that type
- **THEN** the In behandeling column header SHALL render in the orange token

#### Scenario: A coloured status shows on the case
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the status In behandeling of a type has the colour orange
- **WHEN** you open a case currently in that status
- **THEN** the case's status badge SHALL render in the orange token

> **Added 2026-09-12.** REQ-CT-19 has always said "the status badge on the case
> AND the Workflow board column", and only the board half had a scenario. The
> case half had a test and nothing for it to cite, so it was anchorless and
> credited nothing. The badge is where a handler actually reads the status, so
> the half without a scenario was the half that matters most.

#### Scenario: A hidden status keeps its cases out of the list
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the status Afgehandeld is marked hidden in lists
- **AND** three cases sit in Afgehandeld
- **WHEN** you open the Cases index
- **THEN** none of the three SHALL be listed
- **AND** the Closed chip SHALL list them

### Requirement: You derive a case type from a parent (REQ-CT-20)

You derive a case type from a parent and change only what differs. The
`caseType` schema SHALL carry `parentCaseType`, a reference to another case
type. A child SHALL inherit the parent's statuses, results, properties and
deadlines, and a row the child declares with the same name SHALL replace the
parent's. A chain that returns to itself SHALL be refused on save.

**Feature tier**: MVP

#### Scenario: A child shows its parent's statuses
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** the type Bezwaar has four statuses
- **AND** the type Bezwaar (verkort) names Bezwaar as its parent and declares none
- **WHEN** you open Bezwaar (verkort)
- **THEN** its Statuses tab SHALL list the four statuses marked Inherited

#### Scenario: A child overrides one deadline
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** Bezwaar has a processing deadline of 12 weeks
- **AND** Bezwaar (verkort) sets its own deadline to 6 weeks
- **WHEN** you file a case of Bezwaar (verkort)
- **THEN** the case's deadline SHALL be 6 weeks after its start date

#### Scenario: A child inherits a deadline it does not declare
@e2e tests/e2e/case-type-parent-chain.spec.ts

- **GIVEN** Bezwaar has a processing deadline of 12 weeks
- **AND** Bezwaar (standaard) names Bezwaar as its parent and sets no deadline of its own
- **WHEN** you file a case of Bezwaar (standaard)
- **THEN** the case's deadline SHALL be 12 weeks after its start date

#### Scenario: A cycle is refused
@e2e tests/e2e/case-type-parent-chain.spec.ts

- **GIVEN** Bezwaar (verkort) names Bezwaar as its parent
- **WHEN** you set Bezwaar's parent to Bezwaar (verkort) and save
- **THEN** the save SHALL fail with a message naming the cycle

### Requirement: You configure everything a status is, on the page (REQ-CT-10)

You configure a status without editing register JSON. The Statuses tab of a
case type SHALL edit every property the `statusType` schema declares: name,
order, description, colour, role, whether it is final, whether cases in it stay
out of the Cases index, and its checklist. The add form and the edit form SHALL
be the same form. A status saved before a property existed SHALL open with that
property unset rather than with a guessed value, and saving it SHALL NOT write
back any property the schema does not declare.

**Feature tier**: MVP

#### Scenario: A functional administrator gives a status a colour and a role
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** a case type with a status called In behandeling
- **WHEN** the administrator edits it, picks the colour orange and the role in progress, and saves
- **THEN** the status row SHALL show that colour
- **AND** a flow addressing the in-progress role SHALL resolve to this status

#### Scenario: A status asks for a checklist
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** a status being edited
- **WHEN** the administrator adds the checklist item Check identity and marks it required
- **AND** saves
- **THEN** the status row SHALL report one checklist item
- **AND** a case entering that status SHALL get a task called Check identity

#### Scenario: A blank checklist row is not saved
@e2e exclude Pure mapping, covered by tests/vitest/statusTypeForm.spec.js.

- **GIVEN** a status with one filled checklist item and one empty row
- **WHEN** the administrator saves
- **THEN** only the filled item SHALL be stored, because a task with no title is unactionable

#### Scenario: An older status opens without inventing values
@e2e exclude Pure mapping, covered by tests/vitest/statusTypeForm.spec.js.

- **GIVEN** a status saved before colour, role, hiddenInLists and checklist existed
- **WHEN** the administrator opens it
- **THEN** its colour SHALL be unset rather than grey, because a form that opens on grey saves grey
- **AND** saving it SHALL NOT carry back the notifyInitiator and notificationText properties it arrived with

### Requirement: A grant scheme is a case type (REQ-CT-21)

A grant scheme is the blueprint a category of cases is governed by, which is the
sentence that defines a case type. The system SHALL model a subsidieregeling as
a `caseType` plus its `propertyDefinition` records, and SHALL NOT carry a
parallel `subsidieRegeling` schema for the same concept.

Four of the retired schema's properties map onto fields the case type already
has: `schemeName` onto `title`, `termStart` and `termEnd` onto `validFrom` and
`validUntil`, `requestTermWeeks` onto `processingDeadline`, and `legalBasis`
onto `purpose`. The remaining grant-specific properties become
`propertyDefinition` records scoped to that case type.

`requestTermWeeks` was a bare integer and `processingDeadline` is an ISO-8601
duration. The migration SHALL convert it, because an integer stores happily and
is understood by neither the renderer nor the Awb 4:13 deadline calculation.

`subsidieAanvraag.subsidyScheme` SHALL `$ref` `caseType`. It was already a uuid
reference, so the shape of the property does not change.

#### Scenario: A migrated scheme carries its fields, not just its name
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** an instance holding subsidieRegeling objects
- **WHEN** the migration has run
- **THEN** each scheme SHALL exist as a `caseType`
- **AND** its `validFrom`, `validUntil` and `purpose` SHALL be non-empty, not merely present

#### Scenario: The decision term becomes a duration, not an integer
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme whose `requestTermWeeks` was 13
- **WHEN** the migration has run
- **THEN** the case type's `processingDeadline` SHALL read as an ISO-8601 duration such as `P13W`

#### Scenario: The grant-specific properties survive as property definitions
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme carrying `plafond`, `targetGroup` and `auditorsStatementThreshold`
- **WHEN** the migration has run
- **THEN** each SHALL exist as a `propertyDefinition` on the migrated case type

### Requirement: A property definition can carry an enum or a JSON document (REQ-CT-22)

`propertyDefinition.propertyType` SHALL offer `enum` and `json` alongside the
scalar types. An `enum` carries its allowed values in `enumValues`. A `json`
carries a JSON Schema and is validated as a document rather than as a scalar.

Both exist because of what the alternative does. Flattening a four-value enum to
a bare string keeps the value and loses the constraint, and flattening a JSON
Schema keeps the text and loses the shape. Neither loss raises anything, so a
migration that flattened would report the same success as one that did not.

#### Scenario: An enum property keeps its allowed values
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme carrying `interimReportFrequency` with four allowed values
- **WHEN** the migration has run
- **THEN** its `propertyDefinition` SHALL have `propertyType` `enum`
- **AND** its `enumValues` SHALL still list those values, because an enum with no `enumValues` is indistinguishable from a string

### Requirement: Schemes are administered on the Case types index (REQ-CT-23)

The `/subsidieregelingen` page and its Subsidy schemes menu entry SHALL be
retired. A grant scheme is administered where every other case type is
administered, on the Case types index, so there is one index over blueprints
rather than two.

Retiring the route SHALL NOT break it. The route SHALL fall through rather than
error, so a bookmark or an old link lands somewhere rather than on a server
error.

The `subsidieRegeling` schema SHALL be retained for one release, marked
deprecated, and removed only once the migration has run everywhere. A schema the
register no longer carries returns zero rows, and a migration that reads nothing
reports the same success as one that had nothing to do. For the same reason the
repair step SHALL report the counts it converted rather than reporting success.

#### Scenario: The menu offers Case types and not Subsidy schemes
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **WHEN** a handler opens the app navigation
- **THEN** it SHALL NOT carry a Subsidy schemes entry
- **AND** it SHALL carry a Case types entry, because the absence check alone would pass on a build where the capability vanished

#### Scenario: The retired route falls through rather than erroring
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **WHEN** a handler opens `/subsidieregelingen`
- **THEN** no scheme index SHALL render, tested on the page's own create control rather than a heading
- **AND** the page SHALL NOT show a server error

### Requirement: Creating a case type asks the twelve authoring fields, in two columns (REQ-CT-24)

The Add control on the Case types index SHALL open a form scoped to the fields
an author fills when naming a new blueprint, laid out in two columns at large
dialog width.

The form SHALL ask for `title`, `identifier`, `category`, `handlingModel`,
`confidentiality`, `processingDeadline`, `validFrom`, `isDraft`,
`parentCaseType`, `description`, `purpose` and `trigger`, and SHALL NOT ask for
the other twenty-nine properties the `caseType` schema declares. Every field it
omits SHALL remain editable on the case type's own detail page, so narrowing the
create form takes nothing away.

The form SHALL open on `title`. Not one of the schema's properties carries an
`order`, so the field sort falls through to alphabetical and an unordered form
opens on `category` with `title` in tenth place.

`description`, `purpose` and `trigger` SHALL render as multi-line fields and
SHALL span both columns. The schema declares all three as plain strings with no
`maxLength`, which resolves to a single-line input for what is a paragraph.

The form SHALL NOT ask for `initialStatus`. It is a reference to a `statusType`
filtered by `caseType`, and at create time the case type has no id and therefore
no statuses, so the picker would fetch unfiltered and offer every other type's
statuses. A type's statuses are authored on its detail page, once it exists.

#### Scenario: The create form asks the twelve authoring fields and not the rest
@e2e tests/e2e/case-type-create-form.spec.ts

- **WHEN** an author opens the Add control on the Case types index
- **THEN** the form SHALL ask for each of the twelve authoring fields
- **AND** it SHALL NOT ask for the versioning, relation, privacy or specialist coding fields

#### Scenario: The form opens on the title rather than alphabetically
@e2e tests/e2e/case-type-create-form.spec.ts

- **WHEN** an author opens the create form
- **THEN** the first field SHALL be the title
- **AND** the fields SHALL follow the declared authoring order rather than an alphabetical one

#### Scenario: The fields are laid out in two columns
@e2e tests/e2e/case-type-create-form.spec.ts

- **WHEN** an author opens the create form
- **THEN** the single-line fields SHALL occupy exactly two columns, measured from their rendered positions rather than from a class name
- **AND** the three prose fields SHALL each span the full width of the form

#### Scenario: The starting status is not asked for at create time
@e2e tests/e2e/case-type-create-form.spec.ts

- **WHEN** an author opens the create form
- **THEN** it SHALL NOT offer a starting status field

### Requirement: An exported case type carries its own configuration (REQ-CT-40)

You move a case type between instances and it arrives whole.
`CaseDefinitionExportService::exportComponent()` SHALL read each requested
component from OpenRegister and SHALL NOT return a fixed empty shape. The
`schema` component carries the case type object and its property definitions,
`statuses` its status types and the transitions its workflow templates
declare, `permissions` its role types and their group bindings, `documents`
its document types, `metadata` its result types and the decision types the
case type references, and `workflows` the workflow templates bound to it.

The transitions SHALL come from the workflow templates and not from the
status types, because `statusType` declares none: a `transitions` list read
off the status types would be empty beside a populated `statusTypes`, which
reads as a case type whose statuses connect to nothing. The decision types
SHALL come from the case type's own reference list for the same reason: they
carry no `caseType` back-reference, so read as rows they answer the empty set
on every instance.

#### Scenario: A seeded case type exports its statuses
@e2e exclude Backend export service, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, test testASeededCaseTypeExportsItsStatuses.

- **GIVEN** a case type with two status types and one transition between them
- **WHEN** the export runs with the `statuses` component
- **THEN** `statuses.json` SHALL list both status types by id and title
- **AND** it SHALL list the transition with its source and target status

#### Scenario: An unknown case type is refused
@e2e exclude Backend export service, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, test testAnUnknownCaseTypeIsRefusedBeforeAnythingIsWritten.

- **GIVEN** a case type id that no object answers to
- **WHEN** the export runs
- **THEN** the service SHALL throw
- **AND** no ZIP SHALL be written

### Requirement: The manifest names what the package contains (REQ-CT-41)

`buildManifest()` SHALL take `caseType.slug` and `caseType.title` from the
case type object rather than echoing the requested id, and SHALL fill
`dependencies` with every object ref the exported components point at, so an
importer can refuse a package whose references it cannot resolve.

#### Scenario: The manifest lists the workflow templates
@e2e exclude Backend export service, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, test testTheManifestNamesWhatThePackageCarries.

- **GIVEN** a case type bound to one workflow template
- **WHEN** the export runs with every component
- **THEN** `manifest.json` `dependencies` SHALL contain that template's ref
- **AND** `manifest.json` `caseType.slug` SHALL be the object's slug

### Requirement: An import writes the objects or says it did not (REQ-CT-42)

`CaseDefinitionImportService::importComponent()` SHALL create or update the
OpenRegister objects of its component under the caller's `conflictResolution`
mode, and SHALL return the ids it created and the ids it replaced. A
component that writes nothing SHALL NOT report `status: 'success'`.

The import SHALL keep the ids the package carries. Every child row carries a
`caseType` back-reference by id, so minting a new id for the case type would
leave every status type, role type and document type pointing at nothing. A
CONFLICT SHALL be this instance already holding that id, which is a different
question from the package carrying one. A component whose rows were all
skipped under `skip` SHALL report `skipped` rather than `success`: leaving
what is already here alone is not a write.
`importWorkflows()` SHALL deploy each workflow entry through the existing
workflow path, or SHALL return `status: 'error'` naming the entry it could
not deploy. Counting files SHALL NOT be reported as an import.

#### Scenario: An imported case type exists afterwards
@e2e exclude Backend import service, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, tests testEveryComponentCarriesRealRows and testAComponentThatWritesNothingIsNotASuccess.

- **GIVEN** an empty register and a package holding one case type with two
  statuses
- **WHEN** the import runs
- **THEN** the case type and both status types SHALL exist in the register
- **AND** the response SHALL name the three created ids

#### Scenario: A failed write is reported as an error
@e2e exclude Backend import service, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, test testAHalfWrittenComponentIsRolledBack.

- **GIVEN** a package whose `statuses.json` references a case type that is not
  in the package
- **WHEN** the import runs
- **THEN** the `statuses` component SHALL report `status: 'error'`
- **AND** no status type SHALL have been created

### Requirement: A case type survives a round trip (REQ-CT-43)

Export then import SHALL reproduce the case type. A case type exported from
one register and imported into an empty register SHALL match the original on
every exported property, status type, transition, role binding, document type
and result type.

#### Scenario: Export and import reproduce the case type
@e2e exclude Backend round trip, covered by tests/Unit/Service/CaseDefinitionPortabilityTest.php, test testACaseTypeSurvivesARoundTrip.

- **GIVEN** a case type with two statuses, one role, one document type and one
  workflow template
- **WHEN** it is exported and imported into an empty register
- **THEN** the imported case type SHALL match the original field by field
- **AND** its statuses, role, document type and workflow template SHALL match

### Requirement: A status has a label the applicant reads (REQ-CT-25)

`statusType` SHALL carry optional `publicLabel` and `publicDescription`.
Every citizen-facing surface SHALL show `publicLabel`, or `name` when it is
empty, and `publicDescription` when set and nothing at all when it is not:
`#PublicStatus`, the portal contribution, and an OpenRegister access link
minted on the case. The case SHALL carry the resolved words as
`statusPublicLabel` and `statusPublicDescription`, because those surfaces are
handed the case and cannot follow its status reference. The ZGW mapping SHALL
write `publicLabel` to `statustype.statustekst`. The status editor on the case
type page SHALL offer both fields.

#### Scenario: The applicant reads the public label
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status "Toets register B" with public label "We check your application"
- **WHEN** somebody with no account opens a link to a case in that status
- **THEN** they SHALL read "We check your application", and neither the status
  name nor its internal description

#### Scenario: No label, no change
@e2e tests/e2e/citizen-status-labels.spec.ts

- **GIVEN** a status without a public label
- **WHEN** a case in that status is read through a link
- **THEN** it SHALL show the status name, and no description at all

#### Scenario: The author sets it on the status row
@e2e tests/e2e/case-type-status-authoring.spec.ts

- **GIVEN** the status editor of a case type
- **WHEN** you open a status
- **THEN** Public label and Public description SHALL be offered

### Requirement: A case type sets when its cases turn critical and almost due in the queue

The case type MUST offer two optional integer fields, `queueCriticalDays`
(0 to 60) and `queueWarningDays` (0 to 120), on the General tab of the case
type editor, labelled "Critical from, working days left" and "Almost due from,
working days left", with a hint that an empty field uses the instance default
from the admin settings. They steer only the deadline tier of the work queue
(see `werkvoorraad-intelligent-queue`); they are separate from
`statutoryWarningDays` and `plannedWarningDays`, which name a warning on one
specific term.

#### Scenario: The editor offers both fields, empty by default
@e2e exclude Asserted by tests/vitest/caseTypeQueueThresholds.spec.js over the General tab; a browser run needs a published case type fixture the shared rig resets.

- GIVEN an administrator opens a case type's General tab
- THEN two number fields for the queue thresholds MUST be shown
- AND both MUST be empty when the case type sets neither

#### Scenario: A value saves on the case type
@e2e exclude The save is the case type editor's existing save of its form; the schema accepting the field is asserted by tests/Unit/Settings/QueueThresholdSchemaTest.php.

- GIVEN an administrator enters 10 in "Critical from, working days left"
- WHEN the case type is saved
- THEN the case type MUST carry `queueCriticalDays` 10

### Requirement: A case type names the teams that handle it (REQ-CT-44)

A case type SHALL name the teams that handle its cases as Nextcloud group ids:
its default group (`handling.defaultGroup`, REQ-SEED-01) and the groups in
`handling.teams`. The default group SHALL always count as a handling team.
`CaseTypeHandling::teams()` SHALL be the one reader and SHALL return the
default group first, then `handling.teams` in order, without duplicates and
without empty ids. An administrator SHALL set `handling.teams` on the case type
form as "Handling teams", a choice of Nextcloud groups, and saving SHALL keep
the other switches of the handling block. A user is in a team when Nextcloud
says they are a member of that group; dossiq SHALL keep no member list of its
own for this.

#### Scenario: The default group and the extra teams together
@e2e exclude A pure reader over a case type row, asserted in CaseTypeHandlingSwitchesTest.
- **GIVEN** a case type whose default group is `vergunningen` and whose `handling.teams` is `["handhaving", "vergunningen", ""]`
- **WHEN** its handling teams are read
- **THEN** they SHALL be `["vergunningen", "handhaving"]`

#### Scenario: A case type that names only a default group is linked
@e2e exclude A pure reader, asserted in CaseTypeHandlingSwitchesTest.
- **GIVEN** a case type with a default group `woo` and no `handling.teams`
- **WHEN** its handling teams are read
- **THEN** they SHALL be `["woo"]`

#### Scenario: An administrator adds a handling team
@e2e exclude The field's write is asserted in tests/vitest/generalTabHandlingTeams.spec.js; the e2e instance has no Nextcloud groups seeded for case types.
- **GIVEN** an administrator on a case type's General tab
- **WHEN** they choose "Toezicht en handhaving" under Handling teams
- **THEN** the form SHALL hold `handling.teams` `["handhaving"]`
- **AND** the default group, handler, messages and intake screen of the block SHALL be unchanged

### Requirement: A shipped case type is published unless it says otherwise (REQ-CT-45)

Every case type the app ships SHALL declare `isDraft`. A shipped case type meant for
handlers SHALL declare `isDraft: false`, so the new case form offers it on a fresh
install. On an existing install the app SHALL publish, once, the shipped case types
that were stored as drafts only because their seed left `isDraft` unset, and SHALL
NOT change them again after that run.

#### Scenario: A fresh install offers every shipped case type
@e2e exclude a fresh install is not something an e2e run can make; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testEveryShippedCaseTypeDeclaresIsDraft

- **GIVEN** the shipped case type seeds
- **WHEN** they are read
- **THEN** every case type SHALL declare `isDraft`, and the 13 named in this change SHALL declare `false`

#### Scenario: An existing install publishes the seeded drafts once
@e2e exclude a repair step has no screen; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testTheSeededDraftsArePublishedAndNothingElse

- **GIVEN** an install where "Sloopmelding" is stored with `isDraft: true` and an administrator's own case type is a draft
- **WHEN** the repair step runs
- **THEN** "Sloopmelding" SHALL be stored with `isDraft: false`
- **AND** the administrator's own draft SHALL be left a draft

#### Scenario: A later draft is left alone
@e2e exclude a repair step has no screen; proven by tests/Unit/Repair/PublishSeededCaseTypesTest.php::testARecordedRunDoesNotRunAgain

- **GIVEN** the step has run once on this instance
- **WHEN** an administrator makes "Sloopmelding" a draft and repair runs again
- **THEN** "Sloopmelding" SHALL stay a draft

### Requirement: A case type save is judged by the flow it names @e2e exclude backend save listener, no UI change: covered by PHPUnit

A check made while a case type is saved SHALL read the moves of the flow the
incoming case type names in `workflowDefinition`. Only when that name is empty
or does not resolve to a stored flow SHALL it fall back to the case type's
active flow.

#### Scenario: An upgraded instance holds an older active flow

- **GIVEN** an instance whose active flow for the Woo case type has no move from Ontvangst to Afgehandeld
- **AND** the shipped case type names flow `cb967581-…`, which has that move
- **WHEN** the shipped case type is saved
- **THEN** the portal withdrawal check passes and the case type is written

#### Scenario: The named flow is not stored

- **WHEN** the saved case type names a flow the instance does not hold
- **THEN** the check judges the active flow, as before

### Requirement: Packages installed on upgrade are followed by the register import @e2e exclude repair step run by occ upgrade, no UI: covered by PHPUnit

The shipped package step SHALL run on install and on every upgrade, and a
package at the version already installed SHALL write nothing. When the step
installed a package, it SHALL import the register configuration again, unforced,
and name every shipped object that import still could not write.

#### Scenario: The Woo flow arrives with the upgrade

- **GIVEN** an upgraded instance holding the Woo case type at version 1, without a handling team and without flow `cb967581-…`
- **WHEN** the upgrade runs the package step
- **THEN** the `woo-verzoek` package writes the flow
- **AND** the register is imported again, so the case type is updated to 1.4.7 with `handling.defaultGroup: behandelaars` and the flow

#### Scenario: Nothing new to install

- **WHEN** every automatic package is already current
- **THEN** the register is not imported again

### Requirement: An open case without a team takes its case type's handling team @e2e exclude repair step run by occ upgrade, no UI: covered by PHPUnit

On install and on every upgrade, every open case whose `assignedGroup` is empty
SHALL be given its case type's current handling group
(`handling.defaultGroup`). A case that has a team, a closed case, and a case of
a case type without a handling group SHALL be left as they are. Only
`assignedGroup` SHALL be written, and a second run SHALL write nothing.

#### Scenario: A case filed while its case type had no handling block

- **GIVEN** an open case of the Woo case type with no `assignedGroup`
- **AND** the case type now has `handling.defaultGroup: behandelaars`
- **WHEN** the upgrade runs the repair step
- **THEN** the case's `assignedGroup` is `behandelaars` and its other fields are unchanged

#### Scenario: A case that already has a team

- **WHEN** an open case's `assignedGroup` names another team
- **THEN** the repair step leaves it

#### Scenario: A closed case

- **WHEN** a case without a team is in a final status
- **THEN** the repair step leaves it

### Requirement: A case type answers its phases with their tasks (REQ-CTP-1)

`GET /api/case-types/{id}/phases` SHALL answer the case type's statuses as
phases in their order, each with the tasks of the active workflow definition
whose step names that status, in step order, with title, role, lead time and
whether the step is required, and the acts available in every phase. A step
whose task is switched off SHALL be left out; a step naming an unknown status
SHALL be listed under `unplaced`. An unreadable case type SHALL answer 404.

#### Scenario: Tasks sit under their phase
@e2e exclude a read endpoint covered by tests/Unit/Service/CaseType/CaseTypePhasesTest.php

- **GIVEN** a case type with statuses Ontvangst and Beoordeling and steps on both
- **WHEN** its phases are requested
- **THEN** each step SHALL be listed under its status, in order

### Requirement: Example rows are written in the shape their schemas declare

Before the writer gets them, a package's example rows SHALL have every
property that JsonEncodedStringProperties lists for their schema encoded as a
JSON string, and each row SHALL match its schema's types, enums and required
properties.

#### Scenario: A shipped package installs with its example case
- GIVEN an instance that loaded example data
- WHEN bezwaar-beroep is installed
- THEN its example case is written, with `statusHistory` as a JSON string

#### Scenario: Every shipped example row matches its schema
- GIVEN every `examples/*.json` file of every shipped package
- WHEN its rows are prepared as the installer writes them
- THEN no row has a property of another type than its schema declares

### Requirement: A case type's statuses and fields are edited from its page (REQ-CT-PARTS-1)

The CaseTypeDetail page MUST open a status or a field of the case type on a
page of its own from its list, where the record is edited, and MUST offer
deleting a row from the list.

#### Scenario: open a status
- GIVEN a case type with statuses
- WHEN the admin clicks a status in the Statuses list
- THEN the StatusTypeDetail page opens for that status

#### Scenario: delete a field
- GIVEN a case type with a field
- WHEN the admin chooses Delete on the field's row
- THEN the field is deleted through the object API, with an Undo

### Requirement: A case type is deleted only while it is an unused draft (REQ-CT-DEL-1)

Deleting a case type through any path MUST be refused when it is published or
when a case uses it, and an allowed delete MUST remove the case type's status
types.

#### Scenario: delete a published case type
- GIVEN a published case type
- WHEN it is deleted through the object API
- THEN the delete is refused with "A published case type cannot be deleted"

#### Scenario: delete an unused draft
- GIVEN a draft case type no case uses, with two status types
- WHEN it is deleted
- THEN the delete goes ahead and both status types are removed

### Requirement: An email template keeps its earlier wording on every write path (REQ-CT-TPL-1)

A change to an email template's name, subject or body MUST keep the earlier
wording as an inactive copy and raise the template's version, whichever API
made the change.

#### Scenario: edit a message from the case type page
- GIVEN an active template at version 2
- WHEN its body is changed through the object API
- THEN an inactive copy at version 2 holds the earlier body
- AND the template is at version 3 with previousVersion pointing at the copy

### Requirement: The Case types pages are the only case type editor (REQ-CT-EDIT-1)

Case types MUST be created and edited on the Case types and CaseTypeDetail
pages; the Nextcloud admin settings MUST link there instead of carrying an
editor of their own.

#### Scenario: an administrator looks for the case type editor in admin settings
- GIVEN the dossiq admin settings page
- WHEN the administrator reads the Case type management section
- THEN it links to the Case types page

#### Scenario: edit a status's checklist
- GIVEN a case type with a status
- WHEN the administrator opens the case type's Statuses tab
- THEN the statuses editor offers the status's checklist and field rules

#### Scenario: edit or reorder a status that has a capacity
- GIVEN a status with capacity 12 and a phase term
- WHEN the administrator renames it, or drags it to another position
- THEN the saved status still has capacity 12 and its phase term

### Requirement: A case type states its service target and notification domain (REQ-CT-SVC-1)

A case type MUST be able to carry a service target, an ISO 8601 duration the
organisation aims to finish a case within. The case type page MUST show the
service target with the terms and the notification domain with the case type,
and the create form MUST ask both.

#### Scenario: set a service target at creation
- GIVEN the Case types page
- WHEN an administrator creates a case type with service target P42D and notification domain Zaken
- THEN the case type page shows P42D under Terms and Zaken under Case type

### Requirement: Stored legacy archival actions are mapped onto the enum (REQ-CT-ARCH-1)

On upgrade, a result type whose `archivalAction` holds `retain` MUST be
rewritten to `bewaren`, and one holding `destroy` to `vernietigen`. The step
MUST read and write as the system user, MUST leave every other value alone,
and running it again MUST change nothing.

#### Scenario: a result type saved by the retired editor
- GIVEN a result type with archivalAction `destroy`
- WHEN dossiq is upgraded
- THEN its archivalAction reads `vernietigen`

#### Scenario: a second upgrade
- GIVEN every legacy value has been mapped
- WHEN the step runs again
- THEN nothing is written

### Requirement: A case type carries a colour and an icon

A case type SHALL have an optional `colour` from the hue list `statusType.colour` uses (which includes `teal`) and an
optional `icon` naming a registered icon. Every shipped case type SHALL name both. The case type form and card SHALL
offer the colour as a select with a swatch per hue and the icon as a select that shows each icon. The type pill on a
case, the case type tag in the case lists and a pinned case type's menu entry SHALL show the case type's colour and
icon.

#### Scenario: a Woo request

- **GIVEN** a case of the shipped Woo-verzoek case type
- **THEN** its type pill is red with the document icon
- **AND** the Woo-verzoek entry in the menu shows the document icon

#### Scenario: a case type without them

- **GIVEN** a case type with no colour and no icon
- **THEN** its pill and tags draw as before and its menu entry shows the filter icon

### Requirement: A shipped status takes the colour its boards draw

Every status dossiq ships SHALL name the colour the palette gives its name. An upgrade SHALL give a status dossiq seeded
that colour while the status holds no colour, grey, or the colour dossiq shipped it with, and SHALL leave any other
colour, and every status an administrator created, as it is.

#### Scenario: a case in progress

- **GIVEN** a case in the shipped status "In behandeling"
- **THEN** its status pill is purple

#### Scenario: a colour an administrator chose

- **GIVEN** a shipped status whose colour an administrator set to red
- **WHEN** dossiq upgrades
- **THEN** the status stays red

### Requirement: A status badge sets the theme's pill text

A status badge SHALL set its text to the theme's pill text for its hue (`--nl-color-<hue>-text`) when the theme names
one, and to the hue mixed 65% into the main text (the main text for a `-light` colour) otherwise.

#### Scenario: under the Zuiddrecht set

- **GIVEN** a case in a status coloured blue
- **THEN** its status pill text is the set's #234A78 on #EAF0F7

### Requirement: The case type tabs follow the board (REQ-CTB-1)

In the simple structure profile, the case type page SHALL show these tabs in
this order: Overview, Fields, Statuses, Phases and tasks, Rules, Rights,
Privacy, Translations, Versions and History. The Portal tab SHALL sit under the
tab strip's Actions menu. The Rules tab SHALL hold the case rules widget.

#### Scenario: The strip reads as the board
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **WHEN** the simple profile builds the case type page
- **THEN** the tabs in the strip SHALL be Overview, Fields, Statuses, Phases and tasks, Rules, Rights, Privacy, Translations, Versions and History
- **AND** Portal SHALL be an overflow tab

### Requirement: Phases and tasks shows the board's four cards (REQ-CTB-2)

The Phases and tasks tab SHALL hold the phase board, Variants, Terms and the
messages to the applicant, in that order.

- The phase board's header link SHALL open the workflow canvas page.
- Every phase SHALL carry an "Add a task" action that opens that page too.
- The workflow canvas SHALL NOT render inline on the tab.
- The document types, decision types and blueprint SHALL move to the Statuses
  tab.

#### Scenario: The canvas is one click away
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type page
- **WHEN** the reader follows "View as flow"
- **THEN** `/settings/case-types/{id}/flow` SHALL show the workflow canvas for that case type

### Requirement: A case type declares its variants (REQ-CTB-3)

`caseType.variants` SHALL be a list of `{ name, description, isDefault }`. The
Variants card SHALL list them and mark the default one.

#### Scenario: A variant is listed
@e2e exclude schema and configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type with the variants Regulier (default) and Met welstand
- **WHEN** its Phases and tasks tab opens
- **THEN** the Variants card SHALL list both, with Regulier marked as the default

### Requirement: The terms read as words (REQ-CTB-4)

`GET /api/case-types/{id}/terms` SHALL answer `decisionTerm`, `suspension` and
`serviceTarget` as sentences in the reader's language. A term of whole weeks
SHALL read in weeks. An unreadable case type SHALL answer 404.

#### Scenario: A term with an extension
@e2e exclude a read endpoint covered by tests/Unit/Service/CaseType/CaseTypeTermsTest.php

- **GIVEN** a case type with processing deadline P56D, an allowed extension of P42D and at most one extension
- **WHEN** its terms are requested
- **THEN** `decisionTerm` SHALL read "8 weeks, extendable once by 6 weeks"

### Requirement: The side facts read as labels (REQ-CTB-5)

The side column's data card SHALL show these rows:

- the domain;
- the origin, "Shipped" for a case type dossiq delivered and "Own case type"
  otherwise;
- the intake form, when one is bound;
- the handling teams.

It SHALL NOT show raw codes such as the handling model or the confidentiality
key.

#### Scenario: A shipped case type
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type owned by `__system__`
- **WHEN** its page opens
- **THEN** the data card SHALL read Origin: Shipped

### Requirement: The Overview tab summarises the translations

The case type Overview tab SHALL show one row per language the register declares, with the number of its labels that are
translated and not out of date, and a pill that says the language is complete or how many labels it misses. Edit SHALL
open the Translations tab, which keeps the editor.

#### Scenario: DqZaaktypeOverzicht

- **GIVEN** a case type with two translatable labels, Dutch the source and one current English label
- **THEN** the Overview tab reads "Nederlands … Compleet" and "Engels 1 van 2 labels … 1 ontbreken"

### Requirement: An administrator reloads the shipped templates

dossiq SHALL offer `POST /api/packages/reload`, which installs every shipped
package that is installed on this instance or marked `install: auto`, one at a
time through the same install as a single package (REQ-CT-49), and answers one
outcome per package. A package at the shipped version SHALL write nothing and
answer `current`. The endpoint SHALL refuse anyone who is not an administrator
before any package is read or written, and SHALL answer 500 when a package failed,
else 200. The case type list SHALL offer it in its Actions menu as "Reload
templates", after a confirmation.

#### Scenario: Only installed and automatic packages are reloaded
@e2e exclude no user interface beyond a menu entry; proven by tests/Unit/Service/CaseDefinition/PackageInstallerTest.php::testAReloadInstallsWhatIsInstalledOrAutomaticAndNothingElse

- **GIVEN** an on-request package that was never installed
- **WHEN** an administrator reloads the templates
- **THEN** nothing SHALL be written
- **AND** after the package is installed once, a reload SHALL answer `current` for it and write nothing

#### Scenario: A non-administrator changes nothing
@e2e exclude the admin check is the AuthorizedAdminSetting middleware; proven by tests/Unit/Controller/PackageControllerTest.php::testReloadIsAdminOnlyAndRefusesWithoutAUser

- **GIVEN** a request without a user, or from a user who is not an administrator
- **WHEN** it posts to `/api/packages/reload`
- **THEN** the installer SHALL not be called

### Requirement: The case type list exports its selection

The case type list SHALL allow its export and the `caseType` schema SHALL be
flagged exportable (`configuration.exportable`), so the Actions menu offers
"Export selection as JSON" for the selected case types.

#### Scenario: Two selected case types
@e2e exclude configuration only; proven by tests/Unit/Settings/CaseTypeListExportTest.php
- **GIVEN** two case types selected in the list
- **WHEN** an administrator chooses Export selection as JSON
- **THEN** one JSON file SHALL hold those two case types

### Requirement: The blueprint counts inherited and overridden fields

The case type blueprint SHALL count its fields: all of them, those a parent type gave, and those the type overrides by
declaring a row with the same name as a parent's. The parent type card SHALL show those counts for a type with a parent.

#### Scenario: DqZaaktypeVelden

- **GIVEN** Omgevingsvergunning regulier, a child of Omgevingsvergunning, with 14 fields of which 6 come from the parent and none replace a parent's
- **THEN** the card reads "Erft van Omgevingsvergunning", "Geërfde velden 6 van 14", "Overschreven 0"

### Requirement: A case type says whether a late decision costs a penalty

A case type SHALL be able to say whether a late decision costs the applicant a penalty, and the most it can come to.
The terms endpoint SHALL answer it as a sentence that names the notice of default the penalty waits on, and the
Termijnen card SHALL show it.

#### Scenario: DqZaaktype

- **GIVEN** a case type with `penaltyOnLateDecision: true`
- **THEN** its Termijnen card reads "Dwangsom bij niet tijdig beslissen: Ja, na een ingebrekestelling"

### Requirement: The Fields tab draws the board's cards

The case type page's Fields tab SHALL hold the fields editor and the field rights only. The case type form, the raw
terms and the case list columns SHALL stay on the page on a Properties tab listed under the tab strip's Actions menu.

#### Scenario: Opening the Fields tab

- **GIVEN** an administrator on a case type page
- **WHEN** they open the Fields tab
- **THEN** they see the Fields card and the "who sees and fills what" card, with the choice list and the parent type beside them

#### Scenario: Editing the raw terms

- **GIVEN** the Terms card on Phases and tasks
- **WHEN** the administrator presses Edit
- **THEN** the Properties tab opens with the terms form on it

### Requirement: The Overview draws a case type's statuses as coloured tiles

The Statuses card on a case type's Overview SHALL list the statuses in their order as tiles, each with the status's
colour, its name, and "Start", "Term suspended" or "End" when the status is the intake, waits on the applicant or is
final, followed by a line saying where colour and order apply.

#### Scenario: A permit case type

- **GIVEN** a case type whose first status has role intake and whose last two are final
- **WHEN** an administrator opens its Overview
- **THEN** the first tile ends in "Start", the last two in "End", and each tile shows its status colour

### Requirement: Variant toevoegen sits in the Varianten title row

The Varianten card on a case type's Phases and tasks tab SHALL draw its add button in the card's title row.

#### Scenario: Adding a variant

- **WHEN** an administrator presses "Variant toevoegen" beside the Varianten title
- **THEN** the name and description fields open above the variants

### Requirement: The case type flow page loads its workflow when opened directly

The page `/settings/case-types/:id/flow` MUST wait until the app's object stores are registered before it reads the case
type's workflow versions, so that a direct load shows the same workflow as a visit through "View as flow".

#### Scenario: Opening the flow page directly
- GIVEN a case type with a published workflow
- WHEN a user opens `/settings/case-types/{id}/flow` directly
- THEN the page shows a loading state until the stores are up
- AND then draws the workflow editor for that case type, not "No workflow defined"

### Requirement: The case type pill names its version

A case type page's status pill SHALL read "Versie {version} gepubliceerd" for a published version and "Versie
{version} concept" for a draft.

#### Scenario: A draft fifth version

- **GIVEN** case type version 5, a draft
- **THEN** the pill beside the domain reads "Versie 5 concept" in amber

### Requirement: The Gegevens card says how many cases run on the type

The case type's Gegevens card SHALL end with "Lopende zaken": the number of cases of this case type that have not
reached a final status.

#### Scenario: A permit case type with 43 open cases

- **THEN** the Gegevens card reads "Lopende zaken 43"

### Requirement: The Historie card names the case type's reference

The Historie card beside a case type's Phases and tasks SHALL end in "Kenmerk {identifier}".

#### Scenario: The permit case type

- **THEN** the Historie card ends in "Kenmerk ZT-OMV-REG"

### Requirement: The Velden table shows inherited fields and where each comes from

The Velden table SHALL list a case type's own fields (editable) followed by the fields it inherits (read only), with a
Herkomst column ("Eigen veld" or "Geërfd van {type}") and an In de lijst column saying whether the case list of this
case type shows the field.

#### Scenario: A regulier permit inheriting from Omgevingsvergunning

- **GIVEN** a case type with its own field Bouwkosten, shown in its case list, and the inherited field Locatie
- **THEN** Bouwkosten reads Eigen veld · Ja and Locatie reads Geërfd van Omgevingsvergunning, without row actions

### Requirement: The Statuses tab reads as the board

The Statuses tab SHALL subtitle Statuses and Results with what each list is for, SHALL label each Add with what it adds,
SHALL list a status by name, list colour, whether it is final and its public label, and SHALL show a result's retention
period in words.

#### Scenario: DqZaaktypeStatussen

- **GIVEN** the Statuses tab of a case type
- **THEN** Statuses reads "Volgorde, kleur en wat de aanvrager ziet" under its title with "Status toevoegen", and a
  result kept ten years reads "10 jaar"

### Requirement: The blueprint reads as a summary card

The case type blueprint SHALL render as a titled card that states the number of statuses with how many are final, the
number of results, and the number of attributes with how many are inherited and from which parent, and SHALL keep every
row reachable in the same card.

#### Scenario: DqZaaktypeStatussen

- **GIVEN** a case type with 6 statuses of which 2 final, 3 results and 14 attributes of which 6 inherited
- **THEN** the card reads "6, waarvan 2 eindstatussen", "3" and "14 velden, 6 geërfd van Omgevingsvergunning"

### Requirement: The Rules tab lists rules as rows

The Rules tab SHALL draw "Rules on the case" as a titled card with one row per rule, titled by the field the rule writes
or by its kind, with one line saying what it does and a pill (Calculated, On, Off), and SHALL keep trying a rule
against a case behind a disclosure.

#### Scenario: DqZaaktypeRegels

- **GIVEN** the Rules tab of a case type
- **THEN** the deadline calculation reads "Uiterste datum" with its first descriptive sentence and "Berekend", and no
  table wider than the card is drawn

### Requirement: The case type's tabs say how much each holds

The case type page's tab strip SHALL show a count after Fasen en taken (the tasks over all phases), Regels (the
rules of this case type) and Vertalingen (the languages the case type is kept in), as it does after Velden,
Statussen and Versies.

#### Scenario: A permit case type with six tasks, three rules and two languages

- **GIVEN** a case type whose phases run six tasks, with three rules, in a register kept in Dutch and English
- **THEN** the tabs read "Fasen en taken 6", "Regels 3" and "Vertalingen 2"

### Requirement: The Versies card says each version's state and offers the next step

The case type's side Versies card SHALL label a draft "Concept", a version closed for new cases "Vastgezet" and
any other version "Actief". On a published version it SHALL end with "Een gepubliceerde versie verandert niet
meer. Wijzigen maakt een nieuwe versie." above "Nieuwe versie maken" across the card, which opens the new version
dialog; on a draft it SHALL end with "Publiceren".

#### Scenario: Version 4 of Omgevingsvergunning regulier, published

- **GIVEN** versions 4 (in force), 3 and 2 (closed)
- **THEN** the card reads Actief, Vastgezet, Vastgezet and ends with the note and "Nieuwe versie maken"

#### Scenario: Version 5, a draft

- **THEN** the card reads Concept for version 5 and ends with "Publiceren"

### Requirement: The Termijnen card follows the board order

The Termijnen card SHALL list the decision term, then the penalty on a late decision, then the suspension.

#### Scenario: DqZaaktype

- **THEN** the card reads Beslistermijn, Dwangsom bij niet tijdig beslissen, Opschorten

### Requirement: The Overview's Regels card lists the case type's own rules

The Overview tab's Regels card SHALL list the case type's own rules as the Rules tab does ("Als … / dan …" with an
Aan or Uit pill), without row menus, an Add button, the schema inventory or the trial, and SHALL say "Nog geen regels
voor zaken" when there are none. "Regel toevoegen" in its header SHALL open the Rules tab.

#### Scenario: Omgevingsvergunning regulier with three rules

- **THEN** the card reads "Als Bouwkosten groter is dan € 100.000 / dan taak Toets door constructeur toevoegen" with
  Aan, and two more rows

### Requirement: The messages card is the board's data table

The Berichten aan de aanvrager card SHALL be drawn as the board's card table and SHALL end with the opt-out note in an
info box.

#### Scenario: DqZaaktype

- **THEN** Moment, Kanaal, Bericht read as a header band over 40px rows, and the note sits in a blue box

### Requirement: The Vertalingen summary is a titled card

The Overview tab's translations summary SHALL be a card titled Vertalingen with a Bewerken button that opens the
Translations tab.

#### Scenario: DqZaaktypeOverzicht

- **THEN** Vertalingen sits beside Privacy as a card, its rows Nederlands and English inside it

### Requirement: A field rule can be limited to statuses

A case type's field rule SHALL take an optional list of statuses. A rule with statuses SHALL be enforced only while a
case is in one of them; a rule without statuses SHALL hold in every status.

#### Scenario: Bouwkosten read only for the intake once the case is in behandeling

- **GIVEN** a rule readOnly on bouwkosten for Intaker with statuses In behandeling and Afgehandeld
- **THEN** an Intaker may change bouwkosten while the case is Ontvangen, and not after

### Requirement: The Fields tab shows the field rights per role and status

The Fields tab SHALL show "Wie wat ziet en invult, per rol en status" as a matrix with a column per status and a row
per field a rule names or a status requires; a cell SHALL say Verplicht, the roles that lose the field or may only
read it, or Bewerken when nothing restricts it.

#### Scenario: DqZaaktypeVelden

- **THEN** Bouwkosten reads "Verplicht" under Ontvangen and "Alleen lezen voor Intaker" under In behandeling

### Requirement: The Rules tab reads side by side

The Rules tab SHALL start the reads of the rule vocabulary, the case schema, the rule inventory, the case type, its
blueprint and its document type, message template and role lists before any of them answers.

#### Scenario: DqZaaktypeRegels

- **WHEN** an administrator opens the Rules tab of a case type
- **THEN** all reads are under way at once, and the rows show once the slowest has answered

### Requirement: The Statuses table writes the list colour as a word

The Statuses table on a case type SHALL show each status's list colour as its translated palette name in plain text.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statuses tab
- **THEN** the colour column reads Blauw, Oranje, Paars, Groenblauw, Groen, Rood as plain words

### Requirement: The blueprint facts are a two-column list on the card's inset

The blueprint summary card SHALL draw its facts as a label column as wide as the longest label, left aligned, beside the
values, 8px 16px apart, without an inset of its own inside the card.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statussen tab
- **THEN** "Statussen", "Resultaten" and "Kenmerken" start at the card's inset with their values beside them

### Requirement: Status and result names are strong text, a status with its colour dot

The Statussen and Resultaten tables SHALL draw their names as strong text without an underline, the Statussen names
with a dot in the status colour, and the archival action in sentence case.

#### Scenario: DqZaaktypeStatussen

- **WHEN** an administrator opens the Statussen tab
- **THEN** "Ontvangen" has a blue dot and no underline, and a result's archival action reads "Vernietigen"

### Requirement: The Rules tab is headed and adds a rule from the start

The Rules tab SHALL be headed "Rules on the case" with its line, and SHALL offer "Add rule" at the start under the
heading, with a plus.

#### Scenario: DqZaaktypeRegels

- **WHEN** an administrator opens the Rules tab
- **THEN** it reads "Regels op de zaak", the line under it, and "Regel toevoegen" with a plus under that

### Requirement: Every Rules tab row reads in Dutch

Every title and line the Rules tab shows for the case schema's rules SHALL have a Dutch translation.

#### Scenario: DqZaaktypeRegels

- **WHEN** an administrator opens the Rules tab in Dutch
- **THEN** a row reads "Dagen te laat" with "Berekend uit de zaak, bij elk opslaan." under it, not "Days overdue"

### Requirement: A package install reaches the rows it shipped, whoever runs it

A package install SHALL read and write the rows it ships past the caller's access check, so a CLI run with no user refreshes a case it shipped earlier. A plain install SHALL still keep a row changed here, and only `--over-local-changes` SHALL let the package's keys replace it.

#### Scenario: jeugdwet 1.12.0 over local changes from occ

- **GIVEN** case e0b306d2 was shipped by jeugdwet 1.0.0 without `legalBasis`
- **WHEN** `occ dossiq:package:install jeugdwet --over-local-changes` runs
- **THEN** the case reads `legalBasis` "Jeugdwet artikel 2.3" and `initiatorDisplayName` "Huisarts, met toestemming van de ouders"

#### Scenario: a plain install keeps a local edit

- **GIVEN** the case's assignee was changed here
- **WHEN** the package is installed without `--over-local-changes`
- **THEN** the case is kept as it is

### Requirement: The Jeugdwet case type is drawn teal

The jeugdwet package SHALL give its case type the colour teal.

#### Scenario: DqSociaalDomein

- **THEN** the "Melding Jeugdwet" type pill is teal

### Requirement: A package install reports what its refresh did

`occ dossiq:package:install` SHALL print how many rows already present were refreshed and which were kept.

#### Scenario: jeugdwet over local changes

- **THEN** the output reads "2 row(s) refreshed, 1 kept as this instance holds them: c-1"

### Requirement: A case type marks its side statuses

A status type SHALL be able to say it is off the main path (`offPath`), and the case's stages strip SHALL leave such a status out unless the case is in it.

#### Scenario: Melding Jeugdwet

- **THEN** the strip draws Melding ontvangen to Afgehandeld, without Verlenging aangevraagd and Ingetrokken

### Requirement: A task card names its role and term, or says what the task is

The phases endpoint SHALL give each task `roleName`, the name of the role type its step routes to (`routingRule.roleType`
or `assigneeRole`), read in the reader's language from the case type's role types and those it inherits; empty when the
step routes to none. The Fasen en taken card of a task with a term SHALL read the role and the term; of a task without a
term, its description, or its role when it has none. The Nextcloud group SHALL show only for a task routed to no role.

#### Scenario: A task with a term

- **GIVEN** the step "Volledigheid toetsen" routed to role type Intaker with a term of 5 working days
- **WHEN** the Fasen en taken tab is drawn
- **THEN** its card reads "Intaker · 5 werkdagen"

#### Scenario: A task without a term

- **GIVEN** the step "Ontvangstbevestiging" with no role, no term and the description "Automatisch, bericht aan de aanvrager"
- **WHEN** the Fasen en taken tab is drawn
- **THEN** its card reads "Automatisch, bericht aan de aanvrager", not "0 werkdagen"

### Requirement: The field rights matrix draws its rows as the board does

The field rights matrix SHALL set its heads and cells at the normal line height, vertically centred, pad its body cells
11px 12px, and draw the field name in weight 400 on the card's own ground.

#### Scenario: DqZaaktypeVelden

- **GIVEN** the matrix with five columns whose heads wrap to five lines
- **WHEN** the Fields tab is drawn
- **THEN** the head row is about 105px high and "Bouwkosten" reads in the regular weight on white

### Requirement: The case type Historie card draws compact rows

The side card Historie of a case type SHALL draw its stacked rows compact, 10px above and below, as DqZaaktype draws them.

#### Scenario: DqZaaktype Historie

- **GIVEN** the case type page on Fasen en taken in the board look
- **WHEN** its Historie card lists "Versie 4 gepubliceerd" with "Lars de Koning · 2 okt"
- **THEN** that row is 56px high

### Requirement: The In the list column of the fields table filters as drawn

The case type fields table SHALL draw a sort control and a filter on its "In the list" column, as on its other columns.
Field names SHALL keep their words whole without giving up that filter (the reorder grip's width is held by nc-vue).

#### Scenario: DqZaaktypeVelden

- **GIVEN** the Fields tab of a case type
- **WHEN** the Velden table is drawn
- **THEN** "In de lijst" has a sort control and a funnel, and "Welstandsgebied" reads on one line

### Requirement: Bezwaar and beroep install on request as one package (REQ-CT-50)

The app SHALL ship the bezwaar and beroep case types, their status types, role types, guarded fields and flows as the case definition package `bezwaar-beroep`, marked `install: on-request`. The setup action `seed` SHALL install that package through the package installer. When the package is installed or already current the action SHALL answer success and record `setup_seed_done`; when the installer refuses it or a write fails the action SHALL answer 422 with the installer's message and SHALL NOT record the step. Every transition in the package's flows SHALL run between two statuses of its case type, and every field a transition guards on SHALL be declared for that case type.

#### Scenario: An administrator installs bezwaar and beroep

- **GIVEN** an administrator on an instance without the bezwaar and beroep case types
- **WHEN** they run the setup action `seed`
- **THEN** the action SHALL answer success
- **AND** the Bezwaar and Beroep case types SHALL open on the case type page, each with its published flow
- **AND** running the action again SHALL answer success and write nothing

### Requirement: An administrator lists and installs packages through the API (REQ-CT-52)

The app SHALL answer `GET /api/packages` with every shipped package, its version, its install mode and the version installed here, and `POST /api/packages/{id}/install` by installing that package through the package installer. Both SHALL require an administrator. An install that is installed or already current SHALL answer 200; a package that is unknown, invalid or needs a capability this instance lacks SHALL answer 422 with the installer's reason; a write that failed and was taken back SHALL answer 500.

#### Scenario: An on-request package installs through the API
@e2e tests/e2e/packages/besluitvorming.spec.ts

- **GIVEN** an administrator and a shipped package marked `on-request`
- **WHEN** they post to `/api/packages/{id}/install`
- **THEN** the response SHALL be 200 with status `installed` or `current`

#### Scenario: A refused install says why
@e2e exclude no user interface; proven by tests/Unit/Controller/PackageControllerTest.php

- **GIVEN** a package id that is not shipped
- **WHEN** an administrator posts to `/api/packages/{id}/install`
- **THEN** the response SHALL be 422 with the installer's message

## UI References

- **Case Type List**: See wireframe 3.6 in DESIGN-REFERENCES.md (admin settings, case type cards with status/deadline/validity)
- **Case Type Editor**: See wireframe 3.7 in DESIGN-REFERENCES.md (tabbed interface: General, Statuses, Results, Roles, Properties, Docs)

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Cases reference case types for behavioral controls (statuses, deadlines, confidentiality, document requirements, property requirements, result types, role types).
- **OpenRegister**: All case type data is stored as OpenRegister objects in the `dossiq` register under the respective schemas (caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType).
- **Nextcloud Admin Settings**: Case type management is exposed via the Nextcloud admin settings panel (`OCA\Dossiq\Settings\AdminSettings`).

### Current Implementation Status

**Substantially implemented (MVP).** Core case type CRUD and status type management are functional.

**Implemented:**
- Case type CRUD via OpenRegister object store -- create, read, update, delete case types as OpenRegister objects in the `dossiq` register with the `caseType` schema.
- Case type list display (`src/views/settings/CaseTypeList.vue`) with title, isDraft badge (Draft/Published), processing deadline (formatted via `durationHelpers.js`), validity period, default star icon, delete action, set-as-default action (published only).
- Case type detail/edit with tabbed interface (`src/views/settings/CaseTypeDetail.vue`) -- General and Statuses tabs implemented. Publish/unpublish buttons with validation error display.
- General tab (`src/views/settings/tabs/GeneralTab.vue`) with all core fields: title, description, purpose, trigger, subject, processing deadline, service target, extension allowed/period, suspension allowed, origin, confidentiality, publication required/text, valid from, valid until.
- Statuses tab (`src/views/settings/tabs/StatusesTab.vue`) with ordered status type list, drag-and-drop reorder, inline editing, add/delete, isFinal checkbox, notifyInitiator toggle with notification text.
- Draft/published lifecycle with publish validation (publish errors displayed in UI).
- Default case type selection stored via `SettingsService` config key `default_case_type`.
- Case type validation utilities (`src/utils/caseTypeValidation.js`).
- All case type sub-entity schemas defined in `dossiq_register.json` and mapped in `SettingsService::SLUG_TO_CONFIG_KEY`: caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType.
- ZGW Catalogi API compatibility via `ZtcController` (`lib/Controller/ZtcController.php`) and `ZgwZtcRulesService` (`lib/Service/ZgwZtcRulesService.php`).

**Not yet implemented (V1):**
- REQ-CT-07: Result type management tab (schema exists, no UI).
- REQ-CT-08: Role type management tab (schema exists, no UI).
- REQ-CT-09: Property definition management tab (schema exists, no UI).
- REQ-CT-10: Document type management tab (schema exists, no UI).
- REQ-CT-11: Decision type management (schema exists, no UI).
- REQ-CT-12: Confidentiality default enforcement on case creation (field exists, enforcement unclear).
- Backend validation for publish prerequisites (at least one status type, at least one final status, validFrom set).
- Delete case type blocking when active cases reference it.
- Status type name uniqueness validation within a case type.
- Duplicate order number detection and auto-renumbering.

### Standards & References

- **ZGW Catalogi API (VNG)**: Direct mapping to ZaakType, StatusType, ResultaatType, RolType, Eigenschap, InformatieObjectType, BesluitType. The `ZtcController` implements ZGW Catalogi API endpoints.
- **CMMN 1.1**: CaseDefinition concept for case type, Milestone sequence for status types, TimerEventListener for processing deadlines.
- **Schema.org**: `PropertyValueSpecification` for property definitions.
- **ISO 8601**: Duration format for all time-based fields (processingDeadline, extensionPeriod, retentionPeriod, objectionPeriod, publicationPeriod).
- **GEMMA**: ZaakType configuration follows GEMMA zaakgericht werken reference architecture.
- **Archiefwet / Selectielijst**: Result types with archiveAction (retain/destroy) and retentionPeriod follow Dutch archiving legislation.

### Specificity Assessment

This is a comprehensive, highly detailed spec that is implementation-ready for both MVP and V1. It includes complete data models with field-level ZGW mappings.

**Strengths:** Exhaustive data model tables with type/required/mapping columns. 16 requirements with detailed scenarios. Clear feature tier separation. Validation rules explicitly specified.

---

**Missing/Ambiguous:**
- No specification of how sub-entity schemas (statusType, resultType, etc.) relate to each other via OpenRegister references (reference resolution mechanics).
- No specification of bulk operations (e.g., import multiple status types at once).
- Case type versioning strategy not specified -- can a published type be edited in-place or must it be versioned?
- No specification of case type search/filter in the admin list.

**Open questions:**
1. Should editing a published case type require unpublishing first, or can it be edited in-place with a warning?
2. How should the system handle changes to a case type that affect existing cases (e.g., removing a status type that cases are currently at)?
3. Should the `subCaseTypes` field enforce a tree structure (no cycles) and how is this validated?
