# case-record-import

Generic capability (decision 182: procedures are configuration, code is generic). A case type
declares how another app's stored records become its cases; one importer runs any declaration.
The Woo case type's import of opencatalogi's `wooRequest` objects is configuration on that case
type (see `woo-request-intake` REQ-WTO-004), not code.

## ADDED Requirements

### Requirement: A case type declares how another app's records become its cases (REQ-CRI-001)

The case type schema SHALL carry `recordImports`, a list of declarations, each with: `key`;
`sourceApp`, `sourceRegister` and `sourceSchema` (slugs); `statusField` (default `status`) and
`statuses`, mapping each source status onto `{status, term, result}` where `term` is `running`,
`suspended` or `closed` and `result` an optional result type; `fields`, a list of
`{to, from | value, as, maxLength, values, default, required}` where `to` is a dot path on the case,
`from: "@id"` reads the record's uuid, `as` is `text`, `date`, `dateTime` or `integer`, `values`
maps source values onto case values (an unlisted value refuses the record, an empty one takes
`default`) and `maxLength` cuts text at the last whole word; `matchField`, the case dot path that
holds the source uuid; `formerReference` `{application, from}`, kept in the case's
`formerReferences`; `term`, the source field names of `endDate`, `extensions`, `extensionReason`
and `closedOn`; `stamp` `{caseId, at}`, the source fields written once a record has moved; and
`sourceTimerField`.

`OCA\Dossiq\Service\Import\RecordCaseMapping::map()` SHALL read one record by one declaration into
`{case, term, result}`, refusing (and naming why) a record with an undeclared status, an unknown
term state, an empty required field or a value outside its list.

#### Scenario: Every declared shape lands on the case
- **GIVEN** a declaration with a fixed value, an `@id`, an integer, a date, a moment, a listed value and a cut text
- **WHEN** a record is mapped
- **THEN** each value SHALL be at its dot path in its shape, with the case type and the declared status

#### Scenario: A record the declaration cannot read is refused
- **GIVEN** a record with an undeclared status, or an empty required field, or a value outside its list
- **WHEN** it is mapped
- **THEN** the mapping SHALL refuse it with a reason naming the status, the field or the allowed values

### Requirement: Every declared source record is imported exactly once (REQ-CRI-002)

`OCA\Dossiq\Service\Import\CaseRecordImport::run(string $caseType, string $importKey = '')` and
`dryRun()` SHALL run the declarations of the case type (by uuid or identifier) as the system and
answer `{caseType, imports}`, one entry per declaration:
`{key, sourceApp, installed, imported, alreadyImported, failed: list<{requestId, reference, reason}>, unmigrated, migrated: list<{requestId, reference, caseId, sourceTimer}>}`.
The command `occ dossiq:case:import-records <caseType> [--import=<key>] [--dry-run]` SHALL call it
and print the same numbers per import, exiting 1 when a record failed or the case type is unknown.

For each source record without a stamp it SHALL find a case of that type whose `matchField` holds
the source uuid and skip the create when one exists; otherwise write the mapped case (and the
declared result of a closed status); carry the term (REQ-CRI-003); and only after the case and its
timer both exist, stamp the source and read the stamp back. A record whose case, timer or stamp
could not be completed SHALL NOT count as moved, SHALL be listed in `failed` with the reason, and
SHALL be completed by the next run without a second case. The import SHALL never stop or alter the
source app's own timer; it SHALL answer it in `migrated`. A source app that is not installed SHALL
answer `installed: false` and zeros and write nothing. A dry run SHALL count unmoved records and
write nothing.

#### Scenario: Running the import twice changes nothing
- **GIVEN** an import that already moved every record
- **WHEN** it runs again
- **THEN** `imported` SHALL be 0, `alreadyImported` SHALL equal the number of records, no timer SHALL be armed and `unmigrated` SHALL be 0

#### Scenario: A half-finished record is completed, not duplicated
- **GIVEN** a record whose case was written but whose timer failed to arm in the last run
- **WHEN** the import runs again with the engine available
- **THEN** the existing case SHALL get its timer, the source SHALL be stamped, and only one case SHALL refer to that source

#### Scenario: A refused or dropped stamp is a failure
- **GIVEN** a source schema that refuses the stamp, or drops its keys
- **WHEN** the import runs
- **THEN** the record SHALL be in `failed`, not in `migrated`

### Requirement: An imported record's running term is carried onto the case (REQ-CRI-003)

`OCA\Dossiq\Service\Term\TermCarryOver::carry(string $caseId, array $term, string $definitionSlug, int $extensionDays)`
SHALL put the case's statutory term on the source's state. For `closed` it SHALL complete the term
on `closedOn` (which cancels its timers). For `running` or `suspended`, when the term already ends on
the source's `endDate` with the same extension count and status and a timer, it SHALL keep it.
Otherwise it SHALL cancel the fresh timer, write `endDateCurrent`, `countExtensions` and the status
(`paused` when suspended, `verlengd` when extended, else `lopend`), arm the timer through the
arming code every in-flight term uses (REQ-TOT-006: SLA from the start date to the current end,
breach after the last day), suspend it at once when suspended, and record one `verdaging` event per
extension with the source's reason, the definition's legal basis and the case type's
`extensionPeriod` as its days, so a later rebind keeps it. An engine that refuses the timer SHALL
answer `not-armed`; a case without a statutory term `missing`.

#### Scenario: An extended, suspended source keeps both
- **GIVEN** a source term with one extension, suspended, ending 2026-11-23
- **WHEN** it is carried onto a case whose fresh term ends 28 days after receipt
- **THEN** the term SHALL end 2026-11-23 with one extension, status `paused`, a new timer armed at that end and suspended, and the fresh timer cancelled

#### Scenario: A carried term is not armed twice
- **GIVEN** a term carried by an earlier run
- **WHEN** it is carried again with the same source state
- **THEN** nothing SHALL be cancelled, armed or recorded
