## ADDED Requirements

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
