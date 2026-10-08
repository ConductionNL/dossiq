---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# property-definition-management Specification

## Purpose
Provides admin tabs for managing the property definitions, document types, and decision types attached to a case type, completing the seven-tab case-type detail view. Property definitions declare domain-specific required fields with format validation, document types define a required-document checklist with direction and confidentiality classification, and decision types define the formal decision categories that can be recorded on cases.

## Requirements

### Requirement: Property Definition Management Tab
The system SHALL provide an admin tab for managing custom property definitions on a
case type. Property definitions specify domain-specific required fields with format
validation.

#### Scenario: View property definitions tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Properties" tab
- THEN the system MUST display all property definitions linked to this case type
- AND each row MUST show: name, propertyType (badge), isRequired (icon)
- AND an "Add" button MUST be visible

#### Scenario: Add a property definition
- GIVEN an admin on the Properties tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Kadastraal perceelnummer",
  propertyType "text", isRequired true
- THEN the system MUST create a `propertyDefinition` OpenRegister object linked to the current case type
- AND the new property MUST appear in the tab list

#### Scenario: Property type options
- GIVEN the property definition creation form
- WHEN the admin opens the `propertyType` dropdown
- THEN the options MUST include: text, number, date, datetime

#### Scenario: Edit a property definition
- GIVEN a property definition "Kadastraal perceelnummer" with `isRequired = false`
- WHEN the admin sets `isRequired = true`
- THEN the property definition MUST be updated

#### Scenario: Delete a property definition
- GIVEN a property definition "Bouwlagen" not used on any active cases
- WHEN the admin deletes it
- THEN the property definition MUST be removed

### Requirement: Document Type Management Tab
The system SHALL provide an admin tab for managing required document types on a
case type. Document types define a required document checklist with direction
classification.

#### Scenario: View document types tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Docs" tab
- THEN the system MUST display all document types linked to this case type
- AND each row MUST show: name, category, isRequired (icon), confidentiality
- AND an "Add" button MUST be visible

#### Scenario: Add a document type
- GIVEN an admin on the Docs tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Bouwtekening", isRequired true,
  allowedMimeTypes ["application/pdf", "image/png", "image/jpeg"]
- THEN the system MUST create a `documentType` OpenRegister object linked to the current case type
- AND the new document type MUST appear in the tab list

#### Scenario: Delete a document type preserves files
- GIVEN a document type "Situatietekening" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the document type requirement MUST be removed from the case type
- AND existing uploaded files matching this type MUST NOT be deleted

### Requirement: Decision Type Management Tab
The system SHALL provide an admin tab for managing allowed decision types on a case
type. Decision types define the formal decision categories that can be recorded on
cases.

#### Scenario: View decision types tab
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN they click the "Decisions" tab
- THEN the system MUST display all decision types linked to this case type
- AND each row MUST show: name, publicationRequired (icon), isDraft (badge), validFrom
- AND an "Add" button MUST be visible

#### Scenario: Add a decision type
- GIVEN an admin on the Decisions tab for case type "Omgevingsvergunning"
- WHEN they click "Add" and submit Name "Vergunningsbesluit",
  publicationRequired true, isDraft false, validFrom "2026-01-01"
- THEN the system MUST create a `decisionType` OpenRegister object linked to the current case type
- AND the new decision type MUST appear in the tab list

### Requirement: All seven case type tabs MUST render together
The system SHALL complete the case type detail view with all seven tabs integrated
and functional.

#### Scenario: Full seven-tab detail view
- GIVEN an admin editing case type "Omgevingsvergunning"
- WHEN the detail view loads
- THEN all seven tabs (General, Statuses, Results, Roles, Properties, Docs,
  Decisions) MUST render without console errors
- AND switching to any sub-entity tab MUST fetch the correct sub-entities scoped to the case type

### Requirement: You group case types in folders (REQ-PDM-01)

You group case types in folders. The `caseType` schema SHALL carry
`category`, a free word. The Case types index SHALL show a folder sidebar
over the categories in use, with All case types on top.

**Feature tier**: MVP

#### Scenario: A folder narrows the index
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** two types in the category Vergunningen and five in others
- **WHEN** you pick Vergunningen in the folder sidebar
- **THEN** the index SHALL list the two only

### Requirement: You reuse one attribute across case types (REQ-PDM-02)

You reuse one attribute across case types. A `propertyDefinition` SHALL be
valid without a `caseType`; such a row is shared. The Properties tab of a
case type SHALL list the type's own attributes first and the shared ones
under Shared attributes.

**Feature tier**: MVP

#### Scenario: A shared attribute appears on every type
@e2e tests/e2e/case-type-authoring-extras.spec.ts

- **GIVEN** an attribute Kenteken saved without a case type
- **WHEN** you open the Properties tab of any case type
- **THEN** Kenteken SHALL be listed under Shared attributes

### Requirement: Who may read the attribute catalogue (REQ-PDM-03)

Every signed-in user SHALL read the attribute catalogue. A caller with no
session SHALL read nothing from it.

The catalogue is a case type's field vocabulary, not a record about a person.
Handlers read it in their own browser to build the filter bar over their case
list, and a refusal there is answered with an empty filter set rather than an
error. Scoping the read to administrators would therefore show every handler a
case type that declares no fields, and show it silently.

Writing the catalogue is an administrative act, and nothing enforces that yet.
Scoping the write verbs needs a measurement first: the shipped seeders create
these rows through the same permission-checked path with no session user, so a
create rule that refuses an anonymous principal may also refuse
`occ maintenance:repair`.

**Feature tier**: MVP

#### Scenario: A caller with no session reads nothing
@e2e tests/e2e/attribute-catalogue-folders.spec.ts

- **GIVEN** a request that carries no credentials
- **WHEN** it asks for one `propertyDefinition` row
- **THEN** OpenRegister SHALL refuse it

#### Scenario: An ordinary handler reads the field vocabulary
@e2e exclude The rig has no ordinary account whose case list carries a case type with attributes, and the e2e suites that build an unprivileged principal were found on 2026-09-19 to be running as the admin session. Asserting this with the admin would prove nothing about the principal the scenario names.

- **GIVEN** a user who is in no administrative group
- **WHEN** they open the case list of a case type that declares attributes
- **THEN** the filter bar SHALL offer that case type's attributes

### Requirement: Attributes are grouped in folders (REQ-PDM-01)

`propertyDefinition` SHALL carry a facetable `category`. The property
definitions index SHALL show a folder sidebar on it with an All attributes
entry, and rows without a category SHALL be listed under Uncategorised.

#### Scenario: Attributes by folder
@e2e tests/e2e/attribute-catalogue-folders.spec.ts

- **GIVEN** attributes in categories Address and Finance and one without
- **WHEN** you open the property definitions index
- **THEN** the sidebar SHALL list All attributes, Address, Finance and Uncategorised
- **AND** picking Finance SHALL list only its attributes

### Requirement: The case type property picker groups by category (REQ-PDM-02)

The property picker on `#CaseTypeDetail` SHALL group attributes by
`category` with the category as a heading.

#### Scenario: Grouped picker
@e2e tests/e2e/attribute-catalogue-folders.spec.ts

- **GIVEN** the same attributes
- **WHEN** you add a property to a case type
- **THEN** the picker SHALL show Address and Finance as headings with their attributes underneath

### Requirement: A property takes its options from a concept scheme (REQ-PDM-03)

`propertyDefinition` SHALL carry an optional `conceptScheme` reference.
When set, the case data form SHALL offer the scheme's concepts as options
and store the chosen concept's URI; when empty, `enumValues` SHALL rule.
When both are set the scheme SHALL win and the authoring surface SHALL
warn.

#### Scenario: Options come from the scheme
@e2e tests/e2e/code-lists-from-concepts.spec.ts

- **GIVEN** a concept scheme Wijken with three concepts and a property bound to it
- **WHEN** you edit a case of a type with that property
- **THEN** the picker SHALL offer the three concepts

#### Scenario: Inline lists still work
@e2e tests/e2e/code-lists-from-concepts.spec.ts

- **GIVEN** a property with `enumValues` and no scheme
- **WHEN** you edit a case
- **THEN** the picker SHALL offer the inline values

### Requirement: A case-type field can be every type the engine validates (REQ-PDM-10)

`propertyDefinition.propertyType` SHALL offer the types OpenRegister's
`PropertyValidatorHandler` validates, and SHALL NOT offer a type the
engine does not validate. The enum MAY additionally accept a type dossiq
used to offer, so a stored definition keeps working and keeps its value,
and every such value SHALL name the vocabulary type that replaces it.

`schemas.case.properties.caseType.x-openregister-extends-form.map` SHALL
forward the `type` key and every key the definition adds to carry the
vocabulary. A key on the definition that the map does not forward, and a
key the map forwards that the vocabulary does not hold, SHALL both be a
build failure.

#### Scenario: an administrator declares a set-valued answer
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** an administrator on the Properties tab of a case type
- **WHEN** they add a property of type `array` with an item type
- **THEN** the case schema SHALL carry an array property
- **AND** a case SHALL hold more than one value in it

#### Scenario: a document is the value of a field
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** an administrator on the Properties tab
- **WHEN** they add a property of type `file`
- **THEN** a handler SHALL be able to attach a document as that field's value

#### Scenario: the enum and the map cannot drift apart
@e2e exclude {a build check over two JSON objects, asserted and mutation-checked in tests/vitest/propertyDefinitionSchema.spec.js}

- **GIVEN** the `propertyDefinition` schema and the extends-form map
- **WHEN** the two are compared with the published vocabulary
- **THEN** every key the definition adds SHALL be forwarded by the map
- **AND** every key the map forwards SHALL be one the vocabulary holds
- **AND** a key in one and not the other SHALL fail the build

#### Scenario: no type is offered that the engine cannot check
@e2e exclude {compares the enum with the published vocabulary, asserted in tests/vitest/propertyDefinitionSchema.spec.js}

- **GIVEN** the `propertyType` enum
- **WHEN** each value is compared with the engine's validator list
- **THEN** every value SHALL appear on that list, or be one of the five
  dossiq used to offer, each naming its replacement

### Requirement: A field carries its format, its constraints and its help text (REQ-PDM-11)

`propertyDefinition` SHALL carry `format`, `pattern`, `minimum`,
`maximum`, `items`, `ref` and a help text, and the extends-form map SHALL
forward each of them. dossiq SHALL NOT implement validation for any of
them: the declared constraint SHALL be enforced by OpenRegister.

The key names follow the published vocabulary rather than this proposal's
draft spelling, because a form may only forward a key the vocabulary
holds. `itemsType` is `items`, whose value is a shape and not a type name;
the help text is the existing `description`, which is the sentence the case
form renders under the field, so a third text key would be one the renderer
has no role for. `definition` keeps the field's own documentation.

#### Scenario: a multi-line text field is declared
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** an administrator adding a text property
- **WHEN** they choose the multi-line format
- **THEN** the case form SHALL render a multi-line input

#### Scenario: a value outside the declared range is refused
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a number property declaring a minimum of 1 and a maximum of 10
- **WHEN** a handler saves the value 11
- **THEN** the save SHALL be refused
- **AND** the refusal SHALL come from the engine and not from dossiq

#### Scenario: help text is labelled as help
@e2e exclude {the label is asserted in tests/vitest/propertiesTab.spec.js; the rendering beside the field is @conduction/nextcloud-vue's fieldsFromSchema, not dossiq's}

- **GIVEN** a property carrying a help text
- **WHEN** the Properties tab renders the field
- **THEN** the input SHALL be labelled as help
- **AND** the field's own documentation SHALL be a separate input

### Requirement: Choosing enum offers a way to fill the list (REQ-PDM-12)

The Properties tab SHALL offer an input for `enumValues` whenever
`propertyType` is `enum`. Saving an `enum` property with an empty
`enumValues` SHALL be refused, and the refusal SHALL say that a choice
list needs values.

#### Scenario: an administrator fills the choice list
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** an administrator adding a property of type `enum`
- **WHEN** they enter three values and save
- **THEN** the case form SHALL offer those three choices

#### Scenario: an empty choice list is refused rather than shipped
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** an administrator adding an `enum` property with no values
- **WHEN** they save
- **THEN** the save SHALL be refused with a reason

### Requirement: A computed field declares a JSON expression, not a template (REQ-PDM-13)

`propertyDefinition` SHALL carry the JSON expression under the key the
vocabulary publishes for it, `calculation`, and the extends-form map SHALL
forward it. dossiq SHALL NOT forward the Twig `computed` key, which the
vocabulary also holds, and SHALL NOT evaluate an expression itself.

#### Scenario: a fee is computed from two other fields
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a property declaring a calculation over two other properties
- **WHEN** a handler saves values for both
- **THEN** the computed value SHALL be set by the engine

#### Scenario: a Twig expression is not accepted on a case-type property
@e2e exclude {the Twig key is absent from the definition and the map, asserted in tests/vitest/propertyDefinitionSchema.spec.js}

- **GIVEN** a property definition carrying a Twig `computed` expression
- **WHEN** the case type is published
- **THEN** the expression SHALL NOT be forwarded to the case schema

### Requirement: A field may declare a registry source without dossiq resolving it (REQ-PDM-14)

`propertyDefinition` SHALL carry the declared source, and the extends-form
map SHALL forward it as soon as OpenRegister publishes
`x-openregister-property-source` in the vocabulary. Until then the
definition SHALL keep the administrator's answer, the map SHALL NOT forward
a key nobody defines, and the deferral SHALL be asserted so that publishing
the key fails the build rather than going unnoticed. dossiq SHALL NOT ship
a resolver, an adapter or a registry client for it either way.

#### Scenario: a case type declares a second address field
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a case type that already has a case location
- **WHEN** an administrator adds a property declaring the `bag` source
- **THEN** the definition SHALL keep that source
- **AND** its values SHALL be resolved by integriq and not by dossiq

#### Scenario: dossiq ships no resolver for a declared source
@e2e exclude {a read of the dossiq tree, asserted in tests/vitest/propertyDefinitionSchema.spec.js}

- **GIVEN** the dossiq tree
- **WHEN** it is read for a property-source resolver
- **THEN** none SHALL exist

### Requirement: A property whose type this instance does not know keeps its value (REQ-PDM-15)

When a stored `propertyDefinition` carries a `propertyType` this instance
does not offer, dossiq SHALL keep the stored type and the stored value,
SHALL render the field read-only naming the type, and SHALL NOT coerce it
to another type.

#### Scenario: a case type authored on a wider vocabulary opens safely
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a case type carrying a property of an unknown type
- **WHEN** an administrator opens the Properties tab
- **THEN** the property SHALL render read-only with its type named
- **AND** its stored value SHALL be unchanged after the tab is closed
