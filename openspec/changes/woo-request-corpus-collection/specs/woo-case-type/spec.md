## ADDED Requirements

### Requirement: A search plan is recorded before collection (REQ-WRC-001)

A Woo case SHALL hold one `wooSearchPlan` with `custodians` (list of `{name, function, userId?}`),
`systems` (source ids as `GET /api/cases/{id}/woo/sources` names them), `periodFrom`, `periodTo`,
`terms`, `recordedBy` and `recordedAt`. POST `/api/cases/{id}/woo/sources/search` and POST
`/api/cases/{id}/woo/sources/add` SHALL refuse with 409 and the sentence "Record the search plan
before collecting" while the case has no plan. Every change to the plan SHALL be on OpenRegister's
audit trail, and the case page SHALL show the plan and its history.

#### Scenario: No plan, no collection
- **GIVEN** a Woo case in status "Zoeken documenten" without a search plan
- **WHEN** the handler searches the sources
- **THEN** the search SHALL be refused with 409 and the plan sentence

#### Scenario: The plan is held with the request
- **GIVEN** a handler records a plan with custodians "Wethouder Ruimte" and "Afdeling Vergunningen", systems files and sharepoint, period 2025-01-01 to 2025-12-31, and terms "Stationsweg"
- **WHEN** the case is read afterwards
- **THEN** the plan SHALL be on the case with those values, `recordedBy` and `recordedAt`

### Requirement: The collection is reported per custodian and system (REQ-WRC-002)

The add SHALL require a `custodian` (one of the plan's custodians) for every pick and SHALL record it
and the `sourceSystem` in the document's `provenance`, beside the REQ-WOO-014 fields. GET
`/api/cases/{id}/woo/collection` SHALL answer per custodian and per system: `documents`, `bytes`,
`excluded`, and the systems searched with zero results. A planned custodian with no documents SHALL
be listed with zero, not left out.

#### Scenario: Volumes per custodian
- **GIVEN** a plan with custodians A and B, and five documents added for A and none for B
- **WHEN** the collection report is read
- **THEN** A SHALL show 5 documents with their total bytes and B SHALL show 0

### Requirement: Every exclusion before review is kept with its reason (REQ-WRC-003)

A candidate set aside before review SHALL be stored as a `wooExclusion` with `case`, the candidate
(`documentRef` when it was on the case, otherwise `source`, `location` and `fileName`), `sha256`
when known, `reason` (`duplicate`, `out-of-period`, `out-of-scope`, `unreadable`), `note`,
`excludedBy` and `excludedAt`. The add SHALL compare the SHA-256 of each pick with the case's
documents and SHALL record a match as `duplicate` naming the document it duplicates, instead of
adding it. A document on the case that has no assessment yet SHALL be excludable with a reason,
and SHALL then leave the outstanding list. The collection report SHALL answer
`{arrived, assessed, excluded, outstanding}` with `arrived = assessed + excluded + outstanding`, and
SHALL list each exclusion.

#### Scenario: A duplicate is listed, not silently dropped
- **GIVEN** a document already on the case
- **WHEN** the handler adds the same bytes from another folder
- **THEN** no second document SHALL be added
- **AND** a `wooExclusion` with reason `duplicate` naming the first document SHALL exist

#### Scenario: What arrived reconciles with what was reviewed
- **GIVEN** 12 candidates arrived: 9 added, 2 duplicates, 1 unreadable; then 1 added document excluded as out of period and 6 assessed
- **WHEN** the collection report is read
- **THEN** it SHALL answer `arrived` 12, `excluded` 4, `assessed` 6 and `outstanding` 2

### Requirement: The selecting query is saved and can be re-run (REQ-WRC-004)

Every search run from a Woo case SHALL be stored as a `wooCollectionQuery` with `case`, `source`,
`terms`, `periodFrom`, `periodTo`, `filters`, `runBy`, `runAt` and `resultKeys` (a stable key per
row: file id, item id or object uuid). POST `/api/cases/{id}/woo/collection/queries/{queryId}/rerun`
SHALL run the same query with the caller's own access and SHALL answer the rows with `new: true`
for each key not in any earlier run of that query and not already on the case. The query SHALL be
readable by any user with read access to the case, so a colleague can re-run it.

#### Scenario: A colleague re-runs the query and sees what is new
- **GIVEN** a stored query on files for "Stationsweg" 2025 that returned 8 rows
- **WHEN** a colleague re-runs it after two new files were saved
- **THEN** the answer SHALL hold 10 rows, of which the 2 new ones carry `new: true`

### Requirement: A new request starts from the configuration of an earlier one (REQ-WRC-005)

A Woo case SHALL hold a `wooRequestConfiguration` with the plan's `custodians`, `systems` and
`terms` (not the period). Starting a Woo case with `startFrom` set to an earlier Woo case uuid, or
to a named template of the `wooRequestConfiguration` schema, SHALL copy that configuration into the
new case and into a new, unrecorded search plan that the handler completes and records. The new case
SHALL name its source in `wooRequestConfiguration.copiedFrom`. Other changes MAY add keys to the
configuration; every key present SHALL be copied.

#### Scenario: Start from the last request
- **GIVEN** a closed Woo case with custodians A and B, systems files and sharepoint, and terms "Stationsweg"
- **WHEN** a handler starts a new Woo case from it
- **THEN** the new case's draft plan SHALL carry A, B, both systems and the terms, with no period
- **AND** `copiedFrom` SHALL name the closed case
