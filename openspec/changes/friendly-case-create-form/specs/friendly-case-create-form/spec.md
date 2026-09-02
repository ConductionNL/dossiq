# friendly-case-create-form Specification

**Status:** proposed
**Scope:** dossiq

## Purpose

The New case dialog asks a case handler for the fields a case handler fills. The case schema states which of its properties a person deals with and which are engine plumbing, the New case action narrows to what somebody filing a case types, and a chosen case type contributes its own questions, whose answers are stored as `caseProperty` rows.

## ADDED Requirements

### Requirement: REQ-FCF-001 The New Case Dialog Is The Plain Form

The `new-case` header action SHALL open the plain schema-driven form, never the properties-and-JSON table. The action SHALL declare `includeFields` naming exactly the fields collected at create time: `caseType`, `title`, `description`, `assignee`, `priority`, `confidentiality`, `intakeChannel`, `startDate`, `plannedEndDate`. The case detail page remains the surface for every other property.

#### Scenario: The dialog carries no schema-inspection tabs

- **GIVEN** a case handler is on the dossiq dashboard
- **WHEN** they press "New case"
- **THEN** the dialog SHALL show neither a "Properties" tab nor a "Data" tab
- **AND** it SHALL offer a "Create" button, disabled until the required fields are answered

#### Scenario: The dialog asks only for create-time fields

- **GIVEN** the New case dialog is open
- **THEN** it SHALL render a field for each of the nine declared `includeFields`
- **AND** it SHALL render no field for `result`, `workflowTemplate`, `archiveNomination`, `qualityScore`, `casePlanState`, `statusHistory` or `portalSubject`

### Requirement: REQ-FCF-002 The Schema States Which Properties A Person Deals With

The `case` schema SHALL carry an `order` on every property a handler reads or edits, and `visible: false` on every property written by an engine, computed by OpenRegister, or carried as a flow signal payload.

A property that carries an `order`, or that a manifest `include`/`columns` list names, SHALL NOT be `visible: false`. `fieldsFromSchema` evaluates visibility before the include whitelist, so a hidden property named by a widget renders a blank cell and reports nothing.

#### Scenario: A displayed property is never hidden

- **GIVEN** the case detail Process widget lists `workflowVersion`, `extensionCount` and `handoffSource` in its `include`
- **THEN** none of those properties SHALL be `visible: false`
- **AND** the case `description`, carrying `order: 3`, SHALL render on the create form, the detail data widget and the table

### Requirement: REQ-FCF-003 A Case Type Brings Its Own Questions

`case.caseType` SHALL declare `x-openregister-extends-form`, naming `propertyDefinition` as the definitions schema filtered by the chosen case type, and `caseProperty` as the values schema keyed `case` / `propertyDefinition` / `value`.

When a case type is chosen, the form SHALL render one field per property definition of that type, each with the widget its declared `propertyType` implies and its `defaultValue` seeded. Changing the case type SHALL drop the previous type's answers. Answers SHALL be written as `caseProperty` rows AFTER the case exists, never as properties of the case itself.

#### Scenario: Choosing a case type adds its questions

- **GIVEN** the case type "Cultuursubsidie 2026" has property definitions `plafond` (number, default 800000) and `interimReportFrequency` (enum)
- **WHEN** a handler chooses that case type in the New case dialog
- **THEN** the form SHALL gain a number field labelled "plafond" holding 800000
- **AND** a dropdown labelled "interimReportFrequency" offering that definition's enum values

#### Scenario: Answers are stored against the case, not in it

- **GIVEN** a handler has answered a case type question and pressed Create
- **THEN** the case SHALL be created without any dynamic key among its own properties
- **AND** one `caseProperty` row SHALL exist referencing the created case, the property definition, and the answer

### Requirement: REQ-FCF-004 The Properties Tab Writes What The Schema Declares

The case-type properties tab SHALL write only fields the `propertyDefinition` schema declares. It SHALL write the data type as `propertyType`, using that schema's own enum, and SHALL offer a Required toggle writing `isRequired`. `maxLength` and `requiredAtStatus` SHALL be declared on the schema; `requiredAtStatus` SHALL store a status reference and be resolved back to a name for display.

#### Scenario: A chosen type survives a save

- **GIVEN** a functional admin adds a property definition and chooses the type "Date"
- **WHEN** they save it and the list reloads
- **THEN** the definition SHALL read back as "Date", not as the default
