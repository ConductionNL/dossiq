# catalogue Specification

## Purpose
What dossiq does for catalogue. The requirements were first written by the change `screens-round15-catalogue-schema-columns` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-screens-round15-catalogue-schema-columns/`) and cover: An object type shows the schema its link names (REQ-CAT-OT-2); The catalogue names its attributes folder Attributen; An object type says in how many case types it is used; The line under an object type counts the objects of its schema.

## Requirements

### Requirement: An object type shows the schema its link names (REQ-CAT-OT-2)

For each object type, the catalogue SHALL show the title, property count and
views of the OpenRegister schema that a linking `caseObject.objectUrl` names
(`/apps/openregister/api/objects/<register>/<schema>/…`), and SHALL leave
those columns empty for a type whose links name no OpenRegister schema.

#### Scenario: A tree register and a free-text type
@e2e exclude covered by tests/Unit/Service/Catalogue/ObjectTypeCatalogueTest.php

- **GIVEN** links of type Boom, one with an OpenRegister objects URL for a schema with 9 properties including a geo property, and links of type building with no URL
- **WHEN** the catalogue lists the object types
- **THEN** Boom shows that schema's title, "9 properties" and "Map, Table", and building shows no schema

### Requirement: The catalogue names its attributes folder Attributen

The catalogue SHALL label its attributes folder "Attributen" in Dutch and "Attributes" in English, without changing
the Dutch word "Kenmerken" the case type pages use for a type's fields.

#### Scenario: A Dutch administrator opens the catalogue

- **WHEN** they open Instellingen / Catalogus
- **THEN** the folders read Zaaktypen, Attributen, Sjablonen, Objecttypen

### Requirement: An object type says in how many case types it is used

Each object type row SHALL carry the number of distinct case types of the cases that link an object of that type,
besides its number of cases, and the Objecttypen table SHALL show it under "Gebruikt in" as "N zaaktypen". When the
case schema is not configured the number SHALL be unknown and the cell empty.

#### Scenario: Boom linked from three cases of two case types

- **GIVEN** cases c1 and c2 of Woo-verzoek and c3 of Omgevingsvergunning link a Boom
- **THEN** the Boom row reads "Gebruikt in 2 zaaktypen" and still counts 3 cases

### Requirement: The line under an object type counts the objects of its schema

When an object type names an OpenRegister schema, its row SHALL carry the number of objects the register holds in
that schema (`objects`), and the line under the type SHALL read the schema title and that count ("Bomenregister · 5
objecten", in the reader's language). Without OpenRegister's ObjectService the line SHALL show the schema title alone;
a count OpenRegister refuses SHALL fail the catalogue read rather than show a number. A free-text type SHALL have no
line.

#### Scenario: Boom names a schema with five objects

- **GIVEN** a case links a Boom whose object URL names the schema "Bomenregister", which holds 5 objects
- **THEN** the Boom row carries `objects: 5` and the line under it reads "Bomenregister · 5 objecten"

#### Scenario: No count available

- **GIVEN** OpenRegister's ObjectService is not available
- **THEN** the row carries `objects: null` and the line reads "Bomenregister"
