# case-document-drafts

Generic capability (decision 182: procedures are configuration, code is generic). A case type names,
per purpose, the filinq template a document is drafted from and the data it renders; one service
drafts any declaration through filinq's published `DocumentGenerationRequestedEvent` and files the
result on the case. The Woo decision and inventory are configuration on the Woo case type (see
`woo-request-intake` REQ-WTO-005), not code.

## ADDED Requirements

### Requirement: A case type names the template it drafts a document from (REQ-CDD-001)

The case type schema SHALL carry `documentDrafts`, an object keyed by purpose (for example
`decision`), each with `templateSlug` (required), `templateNamespace` (default `dossiq`), `format`
(default `pdf`), `documentType` (the type the draft is filed under), `title`, `dataKey` (when set,
the built data sits under this key beside `case`; otherwise beside `case` directly) and the data
declaration of REQ-CDD-002. A purpose the case type does not declare SHALL answer `not-declared` and
ask filinq nothing.

#### Scenario: An undeclared purpose
- **WHEN** a draft is asked for a purpose the case type does not declare
- **THEN** the answer SHALL be `not-declared` and no event SHALL be dispatched

### Requirement: The draft data is built from the declaration (REQ-CDD-002)

`CaseDraftData::build()` SHALL build the data from `fields` (`{to, from | value, values, default,
required}`, `from` a dot path on the case), `lists` (`{to, schemaKey, caseField, sortBy, numberAs,
fields}`: the case's related records of the schema the app setting names, sorted, numbered from 1
into `numberAs`, each mapped by its own fields) and `summaries` (`{to, over, field, all, empty,
mixed}`: the `all` answer for the value every row shares, `empty` without rows, `mixed` otherwise).
A value missing from a field's `values` map, or an empty `required` field, SHALL refuse the draft
before filinq is asked, with the reason.

#### Scenario: Rows are numbered stably
- **GIVEN** three related records
- **WHEN** the data is built twice
- **THEN** each record SHALL carry the same number both times, in `sortBy` order

### Requirement: The draft is asked of filinq and filed on the case (REQ-CDD-003)

`CaseDocumentDrafts::draft(caseId, purpose)` SHALL dispatch filinq's
`DocumentGenerationRequestedEvent` with requestingApp `dossiq`, `templateSlug`,
`templateNamespace`, `format`, `storeFile: true`, `data`, `object: {register, schema, id}` of the
case and metadata marking the document outgoing, status `draft` and filed by the caller; then file
the file filinq stored on the case. It SHALL answer `{status, reason, informatieobject, fileId}` with
status `drafted`, `not-declared`, `refused`, `filinq-missing` (filinq not installed, or nobody
answered the event) or `failed` (filinq's error is the reason, or nothing could be filed). Nothing
SHALL be filed unless filinq stored a file.

#### Scenario: Filinq refuses
- **GIVEN** filinq answers the event with an error
- **WHEN** the draft is asked
- **THEN** the answer SHALL be `failed` with filinq's error as the reason, and nothing SHALL be filed
