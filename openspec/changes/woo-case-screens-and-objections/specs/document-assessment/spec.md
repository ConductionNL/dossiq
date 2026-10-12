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
