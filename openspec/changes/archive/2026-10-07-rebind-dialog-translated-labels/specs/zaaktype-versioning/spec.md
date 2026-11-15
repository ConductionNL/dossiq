## ADDED Requirements

### Requirement: Translatable case type and status labels read as text in the reader's language (REQ-ZV-11)

Wherever the change case type dialog, the version move dialog or the
rebind journal names a case type or a status, it SHALL resolve a
translatable value stored as a language map to one string: the reader's
language first (a regional code also accepts its base language), then
Dutch, then the first non-empty text in the map. Rows of a case type
SHALL be told apart by that text, read the same way for every reader, so
two statuses with different names SHALL never merge into one. The literal
"Array" SHALL never be shown.

#### Scenario: Status options are separate and readable

- GIVEN a case type whose statuses are stored as `{"nl": "Ontvangen"}`,
  `{"nl": "In behandeling"}` and `{"nl": "Afgehandeld"}`
- WHEN a coordinator previews a rebind onto that case type
- THEN the dialog offers three statuses named "Ontvangen",
  "In behandeling" and "Afgehandeld"

#### Scenario: The reader's language wins

- GIVEN a target case type titled `{"nl": "Omgevingsvergunning", "en": "Environmental permit"}`
- WHEN a coordinator whose language is English opens the dialog
- THEN the target reads "Environmental permit"
- AND for a coordinator whose language is Dutch it reads "Omgevingsvergunning"

#### Scenario: A map without the reader's language falls back

- GIVEN a status stored as `{"de": "In Bearbeitung"}` only
- WHEN an English or Dutch reader previews the rebind
- THEN the status reads "In Bearbeitung"

### Requirement: A version move reads the answers in the register's list shape (REQ-ZV-12)

The version move preview SHALL report as answered every dropped field the
case holds a non-empty value for, reading `case.properties` as the
register stores it: a list of `{propertyDefinition, name, value}` entries.
A legacy name-keyed map SHALL still be read.

#### Scenario: An answered field that the next version drops is named

- GIVEN a case whose `properties` list holds `oppervlakte` with value 42
- AND the next version of its case type has no field `oppervlakte`
- WHEN the handler previews the version move
- THEN `oppervlakte` is listed among the answers about to be lost
