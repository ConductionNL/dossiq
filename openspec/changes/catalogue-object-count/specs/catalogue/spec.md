# catalogue Delta

## ADDED Requirements

### Requirement: The line under an object type counts the objects of its schema

When an object type names an OpenRegister schema, its row SHALL carry the number of objects the register holds in
that schema (`objects`), and the line under the type SHALL read the schema title and that count ("Bomenregister · 5
objecten", in the reader's language). When the count cannot be read the line SHALL show the schema title alone; a
free-text type SHALL have no line.

#### Scenario: Boom names a schema with five objects

- **GIVEN** a case links a Boom whose object URL names the schema "Bomenregister", which holds 5 objects
- **THEN** the Boom row carries `objects: 5` and the line under it reads "Bomenregister · 5 objecten"

#### Scenario: The count fails

- **GIVEN** OpenRegister cannot count the objects of the schema
- **THEN** the row carries `objects: null` and the line reads "Bomenregister"
