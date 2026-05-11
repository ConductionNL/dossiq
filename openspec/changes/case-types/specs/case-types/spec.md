---
status: implemented
---
# case-types Specification

## Purpose
Define the V1 admin surface for configuring case types (zaaktypen) in Procest: result types, role types, property definitions, document types, the tab layout that hosts them, and the publish-time validation that ensures a case type is usable before it goes live. Status type, deadline, and confidentiality configuration are already shipped via the earlier `zaaktype-configuratie` change; this spec layers the remaining V1 tabs on top.

## Context
Case types drive every downstream capability: statuses, deadlines, custom properties, required documents, roles, and result/outcome handling. The admin UI is hosted by `CaseTypeDetail.vue`, which composes per-concern tab components. This change introduces the missing V1 tabs (`ResultTypesTab.vue`, `RoleTypesTab.vue`, `PropertiesTab.vue`, `DocumentTypesTab.vue`) and strengthens publish-time validation.

## Requirements

### REQ-CT-RESULT-01: Result Type Management Tab
The case type admin UI MUST provide a tab for managing result types with archival rules.

#### Scenario CT-RESULT-01-1: List result types
- GIVEN a case type with one or more configured result types
- WHEN the admin opens the "Resultaattypen" tab on `CaseTypeDetail.vue`
- THEN each result type MUST be listed with: name, description, archival classification, and retention period

#### Scenario CT-RESULT-01-2: Create result type
- WHEN the admin clicks "Resultaattype toevoegen" and submits the form with name, description, and archival rule
- THEN a new result type MUST be persisted on the case type
- AND the tab MUST refresh to show the new entry

#### Scenario CT-RESULT-01-3: Edit and delete result type
- WHEN the admin edits or deletes an existing result type
- THEN the change MUST be persisted via the object store
- AND deletion MUST be blocked with a Dutch-language error if any case currently references the result type

### REQ-CT-ROLE-01: Role Type Management Tab
The case type admin UI MUST provide a tab for managing role types referencing the generic role registry.

#### Scenario CT-ROLE-01-1: List role types
- WHEN the admin opens the "Roltypen" tab
- THEN each configured role type MUST be listed with: name, description, generic role (rolomschrijvinggeneriek), and required flag

#### Scenario CT-ROLE-01-2: Create role type with generic role selector
- WHEN the admin adds a new role type and picks a generic role from the dropdown ("Initiator", "Belanghebbende", "Behandelaar", etc.)
- THEN the role type MUST be persisted with both the local label and the generic role mapping

#### Scenario CT-ROLE-01-3: Required role validation
- GIVEN a role type marked as required
- THEN cases of this case type MUST surface a warning when the required role is not filled
- AND the role types tab MUST clearly indicate which roles are required

### REQ-CT-PROP-01: Property Definition Management Tab
The case type admin UI MUST provide a tab for managing property definitions used by the custom properties panel.

#### Scenario CT-PROP-01-1: List property definitions
- WHEN the admin opens the "Kenmerken" tab
- THEN each property definition MUST be listed with: key, label, type, required flag, and default value

#### Scenario CT-PROP-01-2: Create property definition
- WHEN the admin adds a new property definition with key, label, type (text/number/date/select), required flag, and optional default
- THEN the definition MUST be persisted on the case type
- AND it MUST become available in the `CustomPropertiesPanel.vue` on cases of this type

#### Scenario CT-PROP-01-3: Edit and delete property definition
- WHEN the admin edits or deletes a property definition
- THEN the change MUST be persisted
- AND existing case property values MUST remain intact; deletion only removes the definition, not historical data

### REQ-CT-DOC-01: Document Type Management Tab
The case type admin UI MUST provide a tab for managing document types with a direction (incoming/outgoing) and a required flag.

#### Scenario CT-DOC-01-1: List document types
- WHEN the admin opens the "Documenttypen" tab
- THEN each document type MUST be listed with: name, description, direction (inkomend/uitgaand/intern), and required flag

#### Scenario CT-DOC-01-2: Create document type
- WHEN the admin adds a new document type with name, direction, and required flag
- THEN the document type MUST be persisted on the case type
- AND required document types MUST appear in `DocumentChecklist.vue` on cases of this type

#### Scenario CT-DOC-01-3: Edit and delete document type
- WHEN the admin edits or deletes a document type
- THEN the change MUST be persisted
- AND existing case documents MUST remain intact; deletion only removes the definition

### REQ-CT-TABS-01: V1 Tab Layout
`CaseTypeDetail.vue` MUST present all V1 admin concerns through a stable, navigable tab layout.

#### Scenario CT-TABS-01-1: Tab order and labels
- WHEN the admin opens a case type detail view
- THEN the tabs MUST be visible in this order: "Algemeen", "Statussen", "Resultaattypen", "Roltypen", "Kenmerken", "Documenttypen", "Besluiten", "Doorlooptijd"
- AND each tab MUST be labeled in Dutch

#### Scenario CT-TABS-01-2: Deep link to tab
- WHEN the admin navigates with a URL fragment selecting a tab (e.g., `#documenttypen`)
- THEN the corresponding tab MUST be active on initial render

### REQ-CT-PUBLISH-01: Publish Validation
The case type publish action MUST be blocked when minimum configuration requirements are not met.

#### Scenario CT-PUBLISH-01-1: Missing status types
- GIVEN a case type with no status types configured
- WHEN the admin clicks "Publiceren"
- THEN publish MUST be blocked with the error: "Een zaaktype moet minimaal een initiele en een eind-status hebben"

#### Scenario CT-PUBLISH-01-2: Missing initial or final status
- GIVEN a case type with status types but none marked initial or none marked final
- THEN publish MUST be blocked with a Dutch-language error identifying the missing flag

#### Scenario CT-PUBLISH-01-3: Validity period
- GIVEN a case type with `validFrom > validTo`
- THEN publish MUST be blocked with the error: "Geldig-vanaf datum moet voor geldig-tot datum liggen"

#### Scenario CT-PUBLISH-01-4: Successful publish
- GIVEN a case type with at least one initial status, one final status, a valid validity period, and a name
- WHEN the admin clicks "Publiceren"
- THEN the case type's `status` MUST transition from `draft` to `published`
- AND it MUST become selectable on the case creation form

## Non-Requirements
- Versioning of case types across published versions (deferred)
- Cross-case-type cloning / templating (deferred)
- Audit history of case-type configuration changes beyond what OpenRegister provides natively

## Dependencies
- OpenRegister `caseType` schema and related schemas: `resultType`, `roleType`, `propertyDefinition`, `documentType`
- `CaseTypeDetail.vue` and the per-concern tab components
- `case-management` capability (consumes property definitions and document types)
- Existing `zaaktype-configuratie` change (status types, deadlines, confidentiality)
