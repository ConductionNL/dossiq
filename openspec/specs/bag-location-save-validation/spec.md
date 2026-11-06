# bag-location-save-validation Specification

## Purpose
The check on saving a case location that comes from the BAG: a syntactically valid nummeraanduiding id is required, and where the check is switched on the address is verified through integriq. Created by change bag-location-save-validation, extended by bag-client-retired (2026-10-30).

## Requirements

### Requirement: LocationBagValidationListener MUST reject a `source=bag` location that lacks a syntactically valid `nummeraanduidingId`

For any `location`-schema object being created or updated with `source = bag`, `nummeraanduidingId` SHALL be present and SHALL match `^\d{16}$`, else the save SHALL be rejected.

A payload that fails either check SHALL be rejected pre-persist (`stopPropagation()` on the OpenRegister `ObjectCreatingEvent`/`ObjectUpdatingEvent`) with a translated error message; the row SHALL NOT be written to the database. Objects on schemas other than `location`, and `location` payloads whose `source` is not `bag`, SHALL NOT be inspected by this rule.

#### Scenario: Non-location schema is ignored

- **GIVEN** an OpenRegister object being created on any schema other than `location`
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT stop propagation, regardless of the payload
  contents

#### Scenario: source absent or not bag is ignored

- **GIVEN** a `location` payload with no `source` field, or `source` set to `pdok-reverse` / `gps`
  / `free`
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT stop propagation

#### Scenario: source=bag with missing nummeraanduidingId is rejected

- **GIVEN** a `location` payload `{source: 'bag', case: 'uuid-x'}` (no `nummeraanduidingId`)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST call `stopPropagation()`
- **AND** the event errors MUST include a translated message referencing the missing BAG id

#### Scenario: source=bag with a malformed nummeraanduidingId is rejected

- **GIVEN** a `location` payload `{source: 'bag', nummeraanduidingId: '12345'}` (not 16 digits)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST call `stopPropagation()`

#### Scenario: source=bag with a well-formed nummeraanduidingId and a dormant adapter is accepted

- **GIVEN** a `location` payload `{source: 'bag', nummeraanduidingId: '0363200000123456'}`
- **AND** the BAG check is switched off (`integration.bag.check` is not `on`, the default)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT call `stopPropagation()`
- **AND** integriq MUST NOT be asked

### Requirement: A BAG location is checked through integriq where the check is switched on

Where the app config key `integration.bag.check` is `on`, the listener SHALL ask integriq whether
the BAG holds the location's nummeraanduiding, and SHALL refuse the save when the BAG holds none.
It SHALL also refuse the save, with a message saying the address could not be checked, when no
clear answer comes: integriq is not installed or has no BAG read, PDOK does not answer, the answer
is about another object, or the answer is simulated. A confirmed location SHALL be saved with
`bagCheck` set to `checked`. Where the key holds any other value, integriq SHALL NOT be asked and
the location SHALL be saved with `bagCheck` set to `not-checked`.

#### Scenario: The check is off

- **GIVEN** `integration.bag.check` is not `on`
- **WHEN** a `source=bag` location with a well-formed id is saved
- **THEN** the save SHALL go through and integriq SHALL NOT be asked
- **AND** the location SHALL carry `bagCheck: not-checked`

#### Scenario: The BAG confirms the address

- **GIVEN** `integration.bag.check` is `on` and integriq answers that the BAG holds the id
- **WHEN** the location is saved
- **THEN** the save SHALL go through and the location SHALL carry `bagCheck: checked`

#### Scenario: The BAG does not hold the address

- **GIVEN** `integration.bag.check` is `on` and integriq answers that the BAG holds no such id
- **WHEN** the location is saved
- **THEN** the save SHALL be refused with a message saying the id was not found in the BAG

#### Scenario: No clear answer is a refusal, not a pass

- **GIVEN** `integration.bag.check` is `on`
- **AND** integriq is not installed, PDOK does not answer, the answer is about another object, or the answer is simulated
- **WHEN** the location is saved
- **THEN** the save SHALL be refused with a message saying the address could not be checked in the BAG

#### Scenario: Integriq too old or absent is named in the refusal

- **GIVEN** `integration.bag.check` is `on`
- **AND** integriq is installed in a version without the BAG read, or is not installed
- **WHEN** the location is saved
- **THEN** the save SHALL be refused
- **AND** the message SHALL say that integriq must be updated, or installed, for the check

### Requirement: An instance that checked BAG locations before the upgrade keeps checking them

On upgrade, a repair step SHALL set `integration.bag.check` to `on` where dossiq's own BAG client
was switched on: `integration.bag.mode` at `test` or `live` (read as the deleted client read it,
trimmed and case-insensitive), or `integration.bag.source` at `pdok`. It SHALL write nothing on any
other instance, so the check stays off there. It SHALL write only while `integration.bag.check` does
not exist, so running it twice changes nothing more and an administrator's own choice is kept. A
failure SHALL be logged and SHALL NOT abort the upgrade. The step SHALL be registered under
post-migration in `appinfo/info.xml`, with the app version moved so the upgrade runs it.

#### Scenario: An instance that ran the check keeps it

- **GIVEN** `integration.bag.mode` is `live` (or `test`), or `integration.bag.source` is `pdok`
- **AND** `integration.bag.check` does not exist
- **WHEN** the app is upgraded
- **THEN** `integration.bag.check` SHALL be `on`

#### Scenario: Every other instance stays off

- **GIVEN** no old BAG setting switched the client on
- **WHEN** the app is upgraded
- **THEN** no app config key SHALL be written

#### Scenario: An administrator's choice is kept

- **GIVEN** `integration.bag.mode` is `live` and `integration.bag.check` is `off`
- **WHEN** the app is upgraded again
- **THEN** `integration.bag.check` SHALL stay `off`
