# ZGW BRC

## ADDED Requirements

### Requirement: The Besluit resolves to decidiq's Decision (REQ-BRC-020)

`decision_schema` SHALL resolve to decidiq's `decision` schema when this app has
no value of its own configured.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so two apps declaring a `decision` meant whichever row was
reached first answered for both. decidiq's Decision now carries the four BRC
fields it lacked (`deliveryDate`, `expiryDate`, `publicationDate`,
`responsibleOrganisation`), so it can hold the Besluit.

`BrcController` SHALL stay in this app. The standard belongs where it is served
from; only the schema it reads moves.

The lookup SHALL use the `(application, slug)` PAIR, never the slug alone. Slug
alone is the ambiguity this exists to end: it matches this app's own row as
readily as decidiq's, and which one it returns depends on insertion order.

Resolution SHALL happen LAST, only when nothing local answered. An instance that
still has its own `decision_schema` keeps it, because its besluiten are in that
schema; a fresh install has none and lands on decidiq's. Preferring decidiq
unconditionally would point every existing instance at a schema holding none of
its besluiten, and the BRC would answer 404 for every one it has.

Resolution SHALL fail to the empty string when decidiq is absent or carries no
such schema, so the caller behaves exactly as it did when the key was unset.

It SHALL apply to `decision_schema` alone, and not to any other key whose name
contains `decision`.

#### Scenario: A configured instance keeps its own schema

- **GIVEN** an instance with `decision_schema` set
- **WHEN** the value is read
- **THEN** the configured value is returned, not decidiq's.

#### Scenario: An unconfigured instance resolves to decidiq

- **GIVEN** an instance with no `decision_schema` set, and decidiq installed
- **WHEN** the value is read
- **THEN** decidiq's `decision` schema id is returned.

#### Scenario: Without decidiq it resolves to empty

- **GIVEN** an instance with no `decision_schema` set and decidiq absent
- **WHEN** the value is read
- **THEN** the empty string is returned.

#### Scenario: Sibling keys are unaffected

- **WHEN** `decision_type_schema`, `decision_document_schema` or
  `case_decision_schema` is read with no value set
- **THEN** each resolves as it always did, with no fallback.
