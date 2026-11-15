## ADDED Requirements

### Requirement: A case answers which documents still wait for an assessment (REQ-DAS-001)

`GET /api/cases/{id}/document-assessments/outstanding` SHALL answer `{count, documents}` for a
caller who may read the case, where `documents` is a list of `{id, title}` with one entry for
every document on the case that has no assessment and was not set aside. `title` SHALL be the
document's title, or its file name when it has no title, or an empty string when its record
cannot be found. A document whose record cannot be found SHALL stay in the list, because it is
still outstanding.

A caller who is not signed in SHALL get 401. A caller without read access to the case SHALL get
403 with `error` and a `message`. When the register cannot answer, the route SHALL answer 503 and
SHALL NOT answer a list.

Nothing in this capability names a procedure. Which case types assess documents is configuration.

#### Scenario: Three documents, one assessed
- **GIVEN** a case with three documents, one of which has an assessment
- **WHEN** a handler who may read the case asks for the outstanding documents
- **THEN** the answer SHALL carry `count` 2 and the two documents with their titles

#### Scenario: A reader without access
- **GIVEN** a user without read access to the case
- **WHEN** they ask for the outstanding documents
- **THEN** the answer SHALL be 403 and SHALL carry no documents

### Requirement: A case answers every document with its assessment (REQ-DAS-004)

`GET /api/cases/{id}/document-assessments` SHALL answer `{count, outstanding, documents}` for a
caller who may read the case. `documents` SHALL carry one entry `{id, title, outstanding,
assessment}` for every document on the case that was not set aside, titled as REQ-DAS-001 titles
it. `outstanding` SHALL be the same answer the outstanding list gives for that document, and the
top-level `outstanding` SHALL be their number.

`assessment` SHALL be the document's assessment as the caller may read it: its own fields and its
`id`, without the references back to the case and the document. It SHALL be null for an
outstanding document. It SHALL also be null for a document whose assessment the caller may not
read; that document SHALL NOT read as outstanding, because somebody did assess it.

The refusals are those of REQ-DAS-001: 401 without a user, 403 without read access to the case,
503 when the register cannot answer, and never a list beside a refusal.

This is the data of a case page section that lists every document with its assessment. The
section itself waits for its board (decision 162). Which fields of an assessment a screen shows
is configuration of that screen.

#### Scenario: Three documents under review
- **GIVEN** a case with an unassessed document, a document the caller assessed as not public on one ground, and a document set aside
- **WHEN** a handler who may read the case asks for the document assessments
- **THEN** the answer SHALL carry `count` 2 and `outstanding` 1
- **AND** the assessed document SHALL carry its classification and its ground
- **AND** the set-aside document SHALL NOT be in the list

#### Scenario: Another reviewer's assessment
- **GIVEN** a document assessed by a reviewer whose assessment the caller may not read
- **WHEN** the caller asks for the document assessments
- **THEN** the document SHALL be listed with `outstanding` false and `assessment` null

### Requirement: A handler assesses several documents in one dialog (REQ-DAS-002)

A case page header action of type `open-modal` MAY open `DocumentAssessDialog`. The action's
`props` SHALL configure it: `outstandingUrl` and `assessUrl` (with `{caseId}`), `classifications`
(each `{value, label, needsGrounds}`), `grounds` (`{register, schema, valueField, labelField,
filter}`) and `fields` (the names of the document, classification and grounds fields of one
posted assessment).

The dialog SHALL list the outstanding documents by title under "What is still open", with a box
per document and one to select all (board DqWooReview, the "Wat nog openstaat" table). It SHALL
ask for one classification for the selection and, when that classification needs grounds, for at
least one ground read from the configured schema. It SHALL post the whole selection once, as
`{assessments: [...]}`.

After a save the dialog SHALL read the outstanding list again. A refusal SHALL be shown with the
server's sentence, beside the document when the answer names one, and the refused documents SHALL
stay in the list. Nothing SHALL read as saved unless the server stored it.

#### Scenario: Two documents, one request
- **GIVEN** three outstanding documents
- **WHEN** the handler selects two, picks a classification that needs a ground, picks a ground and saves
- **THEN** the configured assess route SHALL be called once with both documents
- **AND** the list SHALL show the one document that is still outstanding

#### Scenario: A refused ground is shown, not swallowed
- **GIVEN** the assess route refuses a ground for a document
- **WHEN** the handler saves
- **THEN** the dialog SHALL show the server's sentence beside that document
- **AND** the document SHALL still be in the list

### Requirement: A case is decided from its assessments in one dialog (REQ-DAS-003)

A case page header action of type `open-modal` MAY open `AssessmentDecisionDialog`. The action's
`props` SHALL configure it: `title`, `outstandingUrl` and `decideUrl` (with `{caseId}`),
`assessments` (`{register, schema, caseField, classificationField}`) and `classifications` (each
`{value, label}`).

The dialog SHALL show the number of documents per classification and the number without an
assessment. While any document is outstanding it SHALL name those documents and SHALL offer
nothing to save. When the outstanding list cannot be read it SHALL say so and SHALL offer nothing
to save either: an unreadable list is not an empty one. When only the counts cannot be read, the
dialog SHALL say so and SHALL still let the handler decide, because the counts inform and the
outstanding list decides.

On save the dialog SHALL post `{decision: {...}}` once, with the summary when the handler gave
one. On success it SHALL ask the page to read the case again. A refusal SHALL be shown with the
server's sentence, SHALL NOT read as decided, and the outstanding list SHALL be read again.

#### Scenario: One document still waits
- **GIVEN** a case with one outstanding document
- **WHEN** the handler opens the dialog
- **THEN** the dialog SHALL name that document and SHALL offer nothing to save

#### Scenario: Everything is assessed
- **GIVEN** a case whose documents are all assessed
- **WHEN** the handler records the decision
- **THEN** the configured decision route SHALL be called once
- **AND** the page SHALL read the case again

#### Scenario: The next step is named in the reader's language
- **GIVEN** a case type whose statuses carry language-tagged names (`{ nl, en }`)
- **WHEN** the handler opens the dialog
- **THEN** the subtitle SHALL name the next step in the reader's language
- **AND** it SHALL NOT read as `[object Object]`
