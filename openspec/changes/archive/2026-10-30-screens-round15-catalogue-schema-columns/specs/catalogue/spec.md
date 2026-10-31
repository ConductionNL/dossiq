## ADDED Requirements

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
