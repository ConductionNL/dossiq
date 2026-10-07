## MODIFIED Requirements

### Requirement: Translatable case type and status labels read as text in the reader's language (REQ-ZV-11)

Wherever the change case type dialog, the version move dialog or the
rebind journal names a case type or a status, it SHALL resolve a
translatable value stored as a language map to one string: the reader's
language first (a regional code also accepts its base language), then
Dutch, then the first non-empty text in the map. There SHALL be one
implementation of that reading, received by the classes that need it.
Rows of a case type SHALL be told apart by that text, read the same way
for every reader, so two statuses with different names SHALL never merge
into one. The literal "Array" SHALL never be shown.

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

#### Scenario: The resolver and the dialog read the map the same way

- GIVEN a case type whose statuses are stored as `{"nl": "Ontvangen"}` and `{"nl": "Afgehandeld"}`
- WHEN the resolver merges the statuses and the dialog lists them
- THEN there are two statuses, named "Ontvangen" and "Afgehandeld"

## ADDED Requirements

### Requirement: A rebind re-arms the case's terms under the target case type (REQ-ZV-13)

When a case is rebound, its running terms SHALL be re-armed under the
target case type's slug. A case type uuid SHALL be turned into its slug
first, because term definitions are keyed by slug and a uuid matches none.

#### Scenario: The uuid becomes the slug

- GIVEN a rebind onto the case type with uuid `6f1c2a0e-uuid-of-kapvergunning` and slug `kapvergunning`
- WHEN the terms are re-armed
- THEN the re-arm is asked for the definitions of `kapvergunning`
