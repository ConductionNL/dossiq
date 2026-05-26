---
retrofit_extensions:
  - REQ-LOC-05
  - REQ-LOC-06
---

# Case Location — server-side validation + persistence (retrofit)

## Requirements

### REQ-LOC-05: LocationService SHALL validate every location payload against the per-source rule matrix and the universal anchor rule

`OCA\Procest\Service\LocationService::validate(array $payload): array` SHALL return an array of error codes (empty = valid) and SHALL enforce the following rules:
- `source` SHALL be present and SHALL be one of `bag` / `pdok-reverse` / `gps` / `free` (else `source.required` / `source.invalid`).
- `case` SHALL be present (else `case.required`).
- `source=bag` requires `nummeraanduidingId` (else `nummeraanduidingId.required`).
- `source=pdok-reverse` requires `latitude` + `longitude` (else `latitude-longitude.required`).
- `source=gps` requires `latitude` + `longitude` + `accuracyRadius` (else `latitude-longitude.required` and/or `accuracyRadius.required`).
- `source=free` requires at least one of `formattedAddress` OR (`latitude` + `longitude`) (else `formattedAddress-or-coordinates.required`).
- Universal anchor rule: every location SHALL carry either `nummeraanduidingId` OR (`latitude` + `longitude`); otherwise emit `bag-or-coordinates.required`.

#### Scenario: BAG source without nummeraanduidingId fails
- **GIVEN** payload `{source: 'bag', case: 'uuid-x'}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `nummeraanduidingId.required` AND `bag-or-coordinates.required`

#### Scenario: GPS source without accuracyRadius fails
- **GIVEN** payload `{source: 'gps', case: 'uuid-x', latitude: 52.0, longitude: 5.0}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `accuracyRadius.required`

#### Scenario: Valid pdok-reverse payload passes
- **GIVEN** payload `{source: 'pdok-reverse', case: 'uuid-x', latitude: 52.0, longitude: 5.0, nummeraanduidingId: '0363010012345678'}`
- **WHEN** `validate()` is called
- **THEN** the result SHALL be an empty array

#### Scenario: Universal anchor rule fires even when source-specific rules pass
- **GIVEN** payload `{source: 'free', case: 'uuid-x', formattedAddress: 'Damrak 1, Amsterdam'}` (no nummeraanduidingId, no coords)
- **WHEN** `validate()` is called
- **THEN** the result SHALL contain `bag-or-coordinates.required`

### REQ-LOC-06: LocationService::attachToCase SHALL persist a validated location to OpenRegister and SHALL surface failure modes as explicit exceptions or null

`OCA\Procest\Service\LocationService::attachToCase(string $caseId, array $location): ?array` SHALL:
- Throw `\RuntimeException('caseId is required')` when `$caseId === ''`.
- Inject the caseId into the payload, call `validate()`, and throw `\RuntimeException('Location payload failed validation: <codes>')` if any errors are returned.
- Resolve the ObjectService via `SettingsService::getObjectService()`; if null, throw `\RuntimeException('OpenRegister is not available')`.
- Read the `register` + `location_schema` IAppConfig keys; if either is empty, throw `\RuntimeException('Location schema is not configured')`.
- Call `objectService->saveObject($register, $schema, $payload)` and return the persisted record. On `\Throwable` during save, log an error including the caseId + APP_ID and return `null` (NOT propagate).
- Normalize the returned object: array passthrough, or `jsonSerialize()` on an object with that method, else `null`.

#### Scenario: Empty caseId throws
- **WHEN** `attachToCase('', ['source' => 'bag', ...])` is called
- **THEN** the method SHALL throw `\RuntimeException('caseId is required')`

#### Scenario: Validation failure throws with all error codes
- **GIVEN** an invalid payload (source missing, no coords/BAG)
- **WHEN** `attachToCase('uuid-x', $bad)` is called
- **THEN** the method SHALL throw `\RuntimeException` whose message starts with `Location payload failed validation:` and contains every emitted error code

#### Scenario: OpenRegister save failure returns null
- **GIVEN** valid payload + configured schema
- **AND** `ObjectService::saveObject()` raises `\Throwable`
- **WHEN** `attachToCase('uuid-x', $valid)` is called
- **THEN** the method SHALL log an error with the caseId
- **AND** SHALL return `null` (NOT propagate the throwable)

#### Notes
- The "swallow Throwable + return null on save error" pattern is observed but worth flagging: callers that need to surface persistence failures must defend against null rather than rely on exceptions.
- ADR-022: this method is the canonical write path for case locations; controllers must not bypass it to call `ObjectService::saveObject` directly.
