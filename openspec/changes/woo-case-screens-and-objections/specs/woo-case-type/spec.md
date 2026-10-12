## ADDED Requirements

### Requirement: A Woo case is assessed from the case page (REQ-WCS-001)

The Woo request case type SHALL configure the generic assess dialog (document-assessment
REQ-DAS-001 and REQ-DAS-002) as the `Assess documents` header action of the case page: the
outstanding list from `/api/cases/{id}/document-assessments/outstanding`, the post to
`/api/cases/{id}/woo/assessment`, the classifications `openbaar`, `deels_openbaar` and
`niet_openbaar` of which the last two need a ground, and the grounds from the `wooRefusalGround`
list (active and citable). The action SHALL NOT appear on any other case type, nor on an archived
case. No code names the procedure (decision 182): the dialog and the route are generic, and this
requirement is configuration.

A `Woo assessment` section on the case page, listing every document with its assessment, waits
for its board (decision 162). Until then the dialog lists what is outstanding.

#### Scenario: A handler assesses two documents
- **GIVEN** a Woo case in status "Beoordelen documenten" with three documents, none assessed
- **WHEN** the handler selects two, marks them `deels_openbaar` with ground 5.1.2.e, and saves
- **THEN** POST `/woo/assessment` SHALL be called once with both documents
- **AND** the dialog SHALL list the one document that is still outstanding

#### Scenario: A refused ground is shown, not swallowed
- **GIVEN** the endpoint refuses a ground as unknown
- **WHEN** the handler saves the assessment
- **THEN** the dialog SHALL show the server's sentence and the documents SHALL stay outstanding

#### Scenario: Not on other cases
- **GIVEN** an omgevingsvergunning case
- **WHEN** its page opens
- **THEN** the `Assess documents` action SHALL NOT be present

### Requirement: The Woo term is extended from the case page (REQ-WCS-002)

A Woo case with a running term and no extension yet SHALL offer an `Extend Woo term` header action.
Its dialog SHALL require a reason and SHALL post `{reason}` to POST
`/api/cases/{id}/woo/extend-deadline`. On success it SHALL show the new deadline and whether the
requester was told (`noticeStatus`, and `noticeReason` when not sent). A 409 SHALL be shown with
the server's sentence. The action SHALL be hidden once the term has been extended.

#### Scenario: Extend once, see the requester was not told
- **GIVEN** a Woo case with `deadline` 2026-11-02, no extension, and a requester with no reachable channel
- **WHEN** the handler extends with the reason "Zienswijzen van derden"
- **THEN** the dialog SHALL show the new deadline 2026-11-16
- **AND** it SHALL say that the requester was not told, with the reason
- **AND** the `Extend Woo term` action SHALL no longer be offered on the case

### Requirement: The Woo decision is made from the case page (REQ-WCS-003)

The Woo request case type SHALL configure the generic decision dialog (document-assessment
REQ-DAS-003) as the `Woo decision` header action: the outstanding list, the assessments counted
from `wooDocumentAssessment` by `caseRef`, the three classifications, and the post of
`{decision: {...}}` to POST `/api/cases/{id}/woo/decision`. The action SHALL be offered while the
case has no `wooPublicationStatus`, on the Woo request case type only and not on an archived case.
The decision route sets `wooPublicationStatus` to `ready`; the dialog asks the page to read the
case again, so the existing `Publish (Woo)` action, offered on `ready`, SHALL be reachable without
a reload and without any API call outside the page.

#### Scenario: From assessment to publish on screen
- **GIVEN** a Woo case whose documents are all assessed, and opencatalogi installed
- **WHEN** the handler makes the decision in the dialog and then presses `Publish (Woo)`
- **THEN** the decision SHALL be stored and the publication SHALL be created
- **AND** no step SHALL have needed a call the page does not make

#### Scenario: Outstanding documents block the decision
- **GIVEN** a Woo case with one document outstanding
- **WHEN** the handler opens the `Woo decision` dialog
- **THEN** the submit button SHALL be disabled and the dialog SHALL name the outstanding document
