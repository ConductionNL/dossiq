## ADDED Requirements

### Requirement: REQ-CSD-01 Every case field a person searches declares how it is searched

Each `case` property a person types a term against SHALL declare a
`matchType` of `exact`, `prefix`, `range`, `fuzzy` or `fulltext`. Each
`case` property a list offers as a filter SHALL declare an `inputControl`
of `text`, `select`, `multiselect`, `range`, `date-range` or `boolean`.

The identifier SHALL be `exact`, the title and the description `fulltext`,
every date `range`, the enum and reference fields `exact` behind a
`select`, the tags `fuzzy`, and a party name `prefix`.

A boolean property SHALL declare an `inputControl` and SHALL NOT declare a
`matchType`, because on this platform a declared match type also enlists
the column in the free-text scan, and a boolean column answering to the
word "true" is a wrong answer rather than a better one.

The declaration SHALL live in the register, not in a page config, so the
list, the facet, the API and the portal read one answer.

#### Scenario: The identifier answers to the whole case number
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** a case whose identifier is `2026-0042` and a second case whose
  description mentions `2026-0042`
- **WHEN** a handler searches for `2026-0042`
- **THEN** the first case SHALL be found by its identifier
- **AND** the second SHALL be found by its description and not by its identifier

#### Scenario: Every declared property is one openregister accepts
@e2e exclude structural; a vitest reads the merged register and asserts every declared matchType and inputControl is a member of openregister's PropertySearchProfile vocabularies

- **GIVEN** the merged register definition
- **WHEN** every `case` property that declares a match type or an input control is read
- **THEN** each value SHALL be one openregister declares
- **AND** a schema save SHALL NOT be refused for an unknown one

#### Scenario: A boolean is filterable without joining the scan
@e2e exclude structural; asserted by the same vitest over the merged register

- **GIVEN** the merged register definition
- **WHEN** the boolean `case` properties are read
- **THEN** each SHALL declare an `inputControl`
- **AND** none SHALL declare a `matchType`

### Requirement: REQ-CSD-02 A refused search says where it broke

`_search` takes `AND`, `OR`, `NOT`, brackets, `"phrases"` and a leading or
trailing `*`, with upper-case operators only. dossiq SHALL pass the term
through unchanged, apart from trimming an empty box.

When openregister refuses a malformed term with `400 {error, position,
term}`, a dossiq search surface SHALL show the position and the term
beside the box the term was typed into. It SHALL NOT render the refusal as
an empty result list.

When the position cannot be read, the surface SHALL show the refusal
message on its own rather than nothing.

#### Scenario: An unbalanced bracket points at itself
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** the Cases page
- **WHEN** a handler searches for `(dakkapel AND NOT geweigerd`
- **THEN** a hint SHALL name the position and the term
- **AND** the list SHALL NOT report that nothing matched

#### Scenario: A plain term is unchanged
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** the Cases page
- **WHEN** a handler searches for `dakkapel`
- **THEN** the term SHALL reach openregister as typed
- **AND** no hint SHALL be shown

#### Scenario: The initiator picker refuses out loud
@e2e exclude structural; covered by a vitest mounting the picker over a refused fetch and asserting the hint rather than the empty state

- **GIVEN** the initiator picker on the person tab
- **WHEN** the register refuses the typed term
- **THEN** the picker SHALL show the refusal
- **AND** it SHALL NOT report that no person matched

### Requirement: REQ-CSD-03 A whole-result selection carries the search

A bulk act widened to the whole result SHALL hand the job the search term
along with the filters. A handler told that all four hundred cases
matching this search are selected SHALL NOT have every case in the
register acted on.

Paging and sorting keys SHALL still be dropped, because they describe the
page rather than the result.

#### Scenario: The term reaches the job
@e2e exclude structural; covered by a vitest over readListFilters and buildSelection

- **GIVEN** a Cases list narrowed by a search term and a case type
- **WHEN** the selection is widened to the whole result
- **THEN** the query handed to the bulk job SHALL carry both the term and the case type
- **AND** it SHALL carry no paging or sorting key

### Requirement: REQ-CSD-04 Cases with no result are one lens away

The Cases page SHALL offer a lens for a closed case that records no
result, selected through openregister's declared null grammar
`result_isnull=true`.

dossiq SHALL NOT build a not-set chip in the facet sidebar. A terms facet
carries `facet.missing.results`, and nextcloud-vue's `normalizeFacets()`
keeps only `buckets`, so the count does not reach a consumer.
ConductionNL/nextcloud-vue#1176 owns that, and reading the raw facet
payload per app is the drift the shared normaliser exists to remove.

#### Scenario: The closed cases with nothing recorded
@e2e tests/e2e/case-search-declares-its-fields.spec.ts

- **GIVEN** two closed cases, one with a result and one without
- **WHEN** a handler picks the lens for a closed case with no result
- **THEN** only the case without a result SHALL remain

### Requirement: REQ-CSD-05 An administrator reaches the search index

Admin settings SHALL show openregister's search index status, read from
`GET /apps/openregister/api/settings/search-index`, and SHALL name the
`occ openregister:tables:search-index` verbs that rebuild, snapshot,
restore and report it.

dossiq SHALL keep no copy of that state. When openregister does not
answer, the panel SHALL say so rather than show an empty table.

#### Scenario: The status is read from openregister
@e2e exclude structural; covered by a vitest mounting the panel over a stubbed endpoint

- **GIVEN** an administrator on the dossiq settings page
- **WHEN** the search index panel loads
- **THEN** it SHALL show the table count, the index count and the last run
- **AND** it SHALL name the occ command that rebuilds them

#### Scenario: openregister does not answer
@e2e exclude structural; covered by the same vitest over a failing endpoint

- **GIVEN** openregister refuses the status request
- **WHEN** the panel loads
- **THEN** it SHALL show what went wrong
- **AND** it SHALL NOT show a table of zeroes
