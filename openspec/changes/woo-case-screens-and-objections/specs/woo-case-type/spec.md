## ADDED Requirements

### Requirement: A Woo case is assessed from the case page (REQ-WCS-001)

On a case of the Woo request case type, the CaseDetail page SHALL show a `Woo assessment` section
listing every document on the case with its assessment state: outstanding, `openbaar`,
`deels_openbaar` or `niet_openbaar`, with its grounds. An `Assess documents` header action SHALL
open a dialog that sets the classification and grounds for one or several selected documents and
SHALL post them to POST `/api/cases/{id}/woo/assessment` as `{assessments: [...]}`. A refusal from
the endpoint SHALL be shown with the server's message, and nothing SHALL read as saved. The
section and the action SHALL NOT appear on any other case type.

#### Scenario: A handler assesses two documents
- **GIVEN** a Woo case in status "Beoordelen documenten" with three documents, none assessed
- **WHEN** the handler selects two, marks them `deels_openbaar` with ground 5.1.2.e, and saves
- **THEN** POST `/woo/assessment` SHALL be called once with both documents
- **AND** the section SHALL show those two as `deels_openbaar` with 5.1.2.e and one as outstanding

#### Scenario: A refused ground is shown, not swallowed
- **GIVEN** the endpoint refuses a ground as unknown
- **WHEN** the handler saves the assessment
- **THEN** the dialog SHALL show the server's sentence and the documents SHALL stay outstanding

#### Scenario: Not on other cases
- **GIVEN** an omgevingsvergunning case
- **WHEN** its page opens
- **THEN** neither the `Woo assessment` section nor the `Assess documents` action SHALL be present

### Requirement: The Woo term is extended from the case page (REQ-WCS-002)

Amended per decision 182: extending a term is the generic Extend term act of the Next step dialog
(board DqVolgendeStap), and its limit and its notice live in `specs/term-extension-limit/spec.md`
(REQ-TEL-001, REQ-TEL-002). There is no Woo-specific dialog and no `woo-extend` action. This
requirement is the configuration that makes the generic act serve the Wet open overheid (Woo).

The Woo request case type SHALL state `extensionAllowed` true, `extensionPeriod` P14D and
`maxExtensions` 1 (Woo art. 4.4 lid 2: one extension of two weeks, told to the requester with
reasons).

#### Scenario: Extend once, see the requester was not told
- **GIVEN** a Woo case with `deadline` 2026-11-02, no extension, and a requester with no reachable channel
- **WHEN** the handler extends with the reason "Zienswijzen van derden"
- **THEN** the term SHALL end on 2026-11-16
- **AND** the dialog SHALL say that the requester was not told, with the reason
- **AND** Extend term SHALL be greyed out on the case from then on

### Requirement: The Woo decision is made from the case page (REQ-WCS-003)

A Woo case SHALL offer a `Woo decision` header action while it has no decision. Its dialog SHALL
show the count of documents per classification and the outstanding count, SHALL refuse to submit
while any document is outstanding, and SHALL post `{decision: {...}}` to POST
`/api/cases/{id}/woo/decision`. After a stored decision the existing `Publish (Woo)` action SHALL
be reachable without any API call outside the page.

#### Scenario: From assessment to publish on screen
- **GIVEN** a Woo case whose documents are all assessed, and opencatalogi installed
- **WHEN** the handler makes the decision in the dialog and then presses `Publish (Woo)`
- **THEN** the decision SHALL be stored and the publication SHALL be created
- **AND** no step SHALL have needed a call the page does not make

#### Scenario: Outstanding documents block the decision
- **GIVEN** a Woo case with one document outstanding
- **WHEN** the handler opens the `Woo decision` dialog
- **THEN** the submit button SHALL be disabled and the dialog SHALL name the outstanding document
