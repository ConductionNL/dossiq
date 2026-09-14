## ADDED Requirements

### Requirement: A case-type field can be every type the engine validates (REQ-PDM-10)

`propertyDefinition.propertyType` SHALL offer the types OpenRegister's
`PropertyValidatorHandler` validates, and SHALL NOT offer a type the
engine does not validate. Every added type SHALL also be forwarded by
`schemas.case.properties.caseType.x-openregister-extends-form.map`. A type
present in the enum but absent from the map SHALL be a build failure.

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

- **GIVEN** the `propertyDefinition` schema and the extends-form map
- **WHEN** the two are compared
- **THEN** every `propertyType` value SHALL be forwarded by the map
- **AND** a value in one and not the other SHALL fail the build

#### Scenario: no type is offered that the engine cannot check

- **GIVEN** the `propertyType` enum
- **WHEN** each value is compared with the engine's validator list
- **THEN** every value SHALL appear on that list

### Requirement: A field carries its format, its constraints and its help text (REQ-PDM-11)

`propertyDefinition` SHALL carry `format`, `pattern`, `minimum`,
`maximum`, `itemsType`, `ref` and `helpText`, and the extends-form map
SHALL forward each of them. dossiq SHALL NOT implement validation for any
of them: the declared constraint SHALL be enforced by OpenRegister.

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

- **GIVEN** a property carrying `helpText`
- **WHEN** the case form renders it
- **THEN** the text SHALL render as help beside the field
- **AND** it SHALL NOT be rendered as the field's description

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

`propertyDefinition` SHALL carry the `x-openregister-calculations` JSON
expression and the extends-form map SHALL forward it. dossiq SHALL NOT
forward the Twig `computed` key, and SHALL NOT evaluate an expression
itself.

#### Scenario: a fee is computed from two other fields
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a property declaring a calculation over two other properties
- **WHEN** a handler saves values for both
- **THEN** the computed value SHALL be set by the engine

#### Scenario: a Twig expression is not accepted on a case-type property

- **GIVEN** a property definition carrying a Twig `computed` expression
- **WHEN** the case type is published
- **THEN** the expression SHALL NOT be forwarded to the case schema

### Requirement: A field may declare a registry source without dossiq resolving it (REQ-PDM-14)

`propertyDefinition` SHALL carry `x-openregister-property-source` and the
extends-form map SHALL forward it. dossiq SHALL NOT ship a resolver, an
adapter or a registry client for it.

#### Scenario: a case type declares a second address field
@e2e tests/e2e/casetype-field-vocabulary.spec.ts

- **GIVEN** a case type that already has a case location
- **WHEN** an administrator adds a property declaring the `bag` source
- **THEN** the case SHALL carry a second address field
- **AND** its values SHALL be resolved by integriq and not by dossiq

#### Scenario: dossiq ships no resolver for a declared source

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
