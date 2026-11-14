## ADDED Requirements

### Requirement: A grant scheme is a case type (REQ-CT-21)

A grant scheme is the blueprint a category of cases is governed by, which is the
sentence that defines a case type. The system SHALL model a subsidieregeling as
a `caseType` plus its `propertyDefinition` records, and SHALL NOT carry a
parallel `subsidieRegeling` schema for the same concept.

Four of the retired schema's properties map onto fields the case type already
has: `schemeName` onto `title`, `termStart` and `termEnd` onto `validFrom` and
`validUntil`, `requestTermWeeks` onto `processingDeadline`, and `legalBasis`
onto `purpose`. The remaining grant-specific properties become
`propertyDefinition` records scoped to that case type.

`requestTermWeeks` was a bare integer and `processingDeadline` is an ISO-8601
duration. The migration SHALL convert it, because an integer stores happily and
is understood by neither the renderer nor the Awb 4:13 deadline calculation.

`subsidieAanvraag.subsidyScheme` SHALL `$ref` `caseType`. It was already a uuid
reference, so the shape of the property does not change.

#### Scenario: A migrated scheme carries its fields, not just its name
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** an instance holding subsidieRegeling objects
- **WHEN** the migration has run
- **THEN** each scheme SHALL exist as a `caseType`
- **AND** its `validFrom`, `validUntil` and `purpose` SHALL be non-empty, not merely present

#### Scenario: The decision term becomes a duration, not an integer
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme whose `requestTermWeeks` was 13
- **WHEN** the migration has run
- **THEN** the case type's `processingDeadline` SHALL read as an ISO-8601 duration such as `P13W`

#### Scenario: The grant-specific properties survive as property definitions
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme carrying `plafond`, `targetGroup` and `auditorsStatementThreshold`
- **WHEN** the migration has run
- **THEN** each SHALL exist as a `propertyDefinition` on the migrated case type

### Requirement: A property definition can carry an enum or a JSON document (REQ-CT-22)

`propertyDefinition.propertyType` SHALL offer `enum` and `json` alongside the
scalar types. An `enum` carries its allowed values in `enumValues`. A `json`
carries a JSON Schema and is validated as a document rather than as a scalar.

Both exist because of what the alternative does. Flattening a four-value enum to
a bare string keeps the value and loses the constraint, and flattening a JSON
Schema keeps the text and loses the shape. Neither loss raises anything, so a
migration that flattened would report the same success as one that did not.

#### Scenario: An enum property keeps its allowed values
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **GIVEN** a scheme carrying `interimReportFrequency` with four allowed values
- **WHEN** the migration has run
- **THEN** its `propertyDefinition` SHALL have `propertyType` `enum`
- **AND** its `enumValues` SHALL still list those values, because an enum with no `enumValues` is indistinguishable from a string

### Requirement: Schemes are administered on the Case types index (REQ-CT-23)

The `/subsidieregelingen` page and its Subsidy schemes menu entry SHALL be
retired. A grant scheme is administered where every other case type is
administered, on the Case types index, so there is one index over blueprints
rather than two.

Retiring the route SHALL NOT break it. The route SHALL fall through rather than
error, so a bookmark or an old link lands somewhere rather than on a server
error.

The `subsidieRegeling` schema SHALL be retained for one release, marked
deprecated, and removed only once the migration has run everywhere. A schema the
register no longer carries returns zero rows, and a migration that reads nothing
reports the same success as one that had nothing to do. For the same reason the
repair step SHALL report the counts it converted rather than reporting success.

#### Scenario: The menu offers Case types and not Subsidy schemes
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **WHEN** a handler opens the app navigation
- **THEN** it SHALL NOT carry a Subsidy schemes entry
- **AND** it SHALL carry a Case types entry, because the absence check alone would pass on a build where the capability vanished

#### Scenario: The retired route falls through rather than erroring
@e2e tests/e2e/spec-coverage/subsidieregeling-is-a-casetype.spec.ts

- **WHEN** a handler opens `/subsidieregelingen`
- **THEN** no scheme index SHALL render, tested on the page's own create control rather than a heading
- **AND** the page SHALL NOT show a server error
