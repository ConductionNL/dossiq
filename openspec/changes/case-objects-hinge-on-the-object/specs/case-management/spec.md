## ADDED Requirements

### Requirement: A linked object's title and status are read, never copied (REQ-HINGE-01)

The `caseObject` schema SHALL declare, under `x-openregister-lenses`, a
lens onto the linked object's title and a lens onto its status, both
looking through the property that holds the link. Neither SHALL be a
stored property of the schema. The Objects tab on the case page SHALL
show both.

#### Scenario: the object is renamed in its own register
@e2e tests/e2e/case-objects-hinge.spec.ts

- **GIVEN** a case linked to an object
- **WHEN** the object's title changes in its own register
- **THEN** the Objects tab on the case SHALL show the new title
- **AND** nothing on the case record SHALL have been written

#### Scenario: a lens is refused on write
@e2e tests/e2e/case-objects-hinge.spec.ts

- **GIVEN** a case object read back with its lens properties
- **WHEN** a client sends it back unchanged
- **THEN** the write SHALL be refused with a message naming the lens property

### Requirement: An unreadable lens value says so (REQ-HINGE-02)

Where the reader may not open the linked object, a lens column SHALL
render the value as withheld, in words. It SHALL NOT render it blank, and
it SHALL NOT render the marker object. The reason SHALL be available to a
reader who asks for it.

#### Scenario: a handler without access to the object register

- **GIVEN** a case linked to an object in a register the handler may not read
- **WHEN** the handler opens the Objects tab
- **THEN** the Object column SHALL say that the value is withheld
- **AND** it SHALL NOT be empty

#### Scenario: a case linked to nothing

- **GIVEN** a case object whose link resolves to no record
- **WHEN** the handler opens the Objects tab
- **THEN** the Object column SHALL be empty
- **AND** it SHALL NOT say the value is withheld

### Requirement: The case-object schema declares how it lists (REQ-HINGE-03)

The `caseObject` schema SHALL declare, under `x-openregister-list`, the
columns a list of case objects shows and the fields it searches. Every
declared column and search field SHALL name a property the schema
declares. The Objects index SHALL show the declared columns, in the
declared order, before any column of its own.

#### Scenario: a generic list over the schema

- **GIVEN** a surface with no page written for case objects
- **WHEN** it reads the schema's list presentation
- **THEN** it SHALL receive the declared columns and search fields
- **AND** `declared` SHALL be true

### Requirement: An object's own page names the cases it carries (REQ-HINGE-04)

A `caseObject` record SHALL be named after the case it belongs to, so the
reverse view on the linked object lists the cases by name. The name SHALL
fall back to the object identification and then to the object type, so a
record is never unnamed.

#### Scenario: a building with three cases on it
@e2e tests/e2e/case-objects-hinge.spec.ts

- **GIVEN** a building linked to three cases
- **WHEN** a handler opens the building's Referenced by tab
- **THEN** it SHALL list three records
- **AND** each one SHALL carry the title of its case

#### Scenario: a case with no title

- **GIVEN** a case object whose case carries no title
- **WHEN** the record is saved
- **THEN** its name SHALL be the object identification, or the object type

### Requirement: A case location inherits the object's geometry (REQ-HINGE-05)

The `case-location` schema SHALL declare, under
`x-openregister-geo-inheritance`, the reference property it inherits map
features through, and SHALL declare that property. An inherited feature
SHALL name the relation it arrived through and the record it came from. A
location's own feature SHALL outrank an inherited one for the same
purpose, and the inherited one SHALL be marked rather than dropped.

#### Scenario: a case about an address shows the address point
@e2e tests/e2e/case-objects-hinge.spec.ts

- **GIVEN** a case location pointing at a BAG object that holds a point
- **WHEN** the case's map is drawn
- **THEN** it SHALL show that point
- **AND** the point SHALL be marked as inherited, naming the object it came from

#### Scenario: a location that carries its own geometry

- **GIVEN** a case location with its own geometry and an inherited one for the same purpose
- **WHEN** the features are collected
- **THEN** the location's own feature SHALL be returned first
- **AND** the inherited one SHALL be returned marked superseded

### Requirement: The channels a case arrives through are objects (REQ-HINGE-06)

The channels dossiq handles, mail, the portal, the API, the contact
centre and the DSO, SHALL be declared as objects in the `intake-sources`
register. Each SHALL carry a stable slug, a title, a description and the
transport it arrives over. Every channel SHALL be created switched off.

#### Scenario: a fresh install

- **GIVEN** an install with the intake-sources register present
- **WHEN** the repair step runs
- **THEN** five intake sources SHALL exist
- **AND** every one of them SHALL be disabled

#### Scenario: the register is not there yet

- **GIVEN** an install whose OpenRegister has not seeded the intake-sources register
- **WHEN** the repair step runs
- **THEN** it SHALL report that no channel was seeded
- **AND** it SHALL NOT fail the upgrade

### Requirement: An upgrade never switches a channel back on (REQ-HINGE-07)

Where an intake source already exists, the seed SHALL refresh its title,
description, transport and target, and SHALL leave its enabled flag, its
connection, its location, its state and its settings exactly as they are.

#### Scenario: an administrator switched a channel off

- **GIVEN** an intake source an administrator disabled
- **WHEN** the app is upgraded
- **THEN** the source SHALL still be disabled
- **AND** its connection SHALL be unchanged
