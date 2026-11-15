## ADDED Requirements

### Requirement: The case type tabs follow the board (REQ-CTB-1)

In the simple structure profile, the case type page SHALL show these tabs in
this order: Overview, Fields, Statuses, Phases and tasks, Rules, Rights,
Privacy, Translations, Versions and History. The Portal tab SHALL sit under the
tab strip's Actions menu. The Rules tab SHALL hold the case rules widget.

#### Scenario: The strip reads as the board
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **WHEN** the simple profile builds the case type page
- **THEN** the tabs in the strip SHALL be Overview, Fields, Statuses, Phases and tasks, Rules, Rights, Privacy, Translations, Versions and History
- **AND** Portal SHALL be an overflow tab

### Requirement: Phases and tasks shows the board's four cards (REQ-CTB-2)

The Phases and tasks tab SHALL hold the phase board, Variants, Terms and the
messages to the applicant, in that order.

- The phase board's header link SHALL open the workflow canvas page.
- Every phase SHALL carry an "Add a task" action that opens that page too.
- The workflow canvas SHALL NOT render inline on the tab.
- The document types, decision types and blueprint SHALL move to the Statuses
  tab.

#### Scenario: The canvas is one click away
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type page
- **WHEN** the reader follows "View as flow"
- **THEN** `/settings/case-types/{id}/flow` SHALL show the workflow canvas for that case type

### Requirement: A case type declares its variants (REQ-CTB-3)

`caseType.variants` SHALL be a list of `{ name, description, isDefault }`. The
Variants card SHALL list them and mark the default one.

#### Scenario: A variant is listed
@e2e exclude schema and configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type with the variants Regulier (default) and Met welstand
- **WHEN** its Phases and tasks tab opens
- **THEN** the Variants card SHALL list both, with Regulier marked as the default

### Requirement: The terms read as words (REQ-CTB-4)

`GET /api/case-types/{id}/terms` SHALL answer `decisionTerm`, `suspension` and
`serviceTarget` as sentences in the reader's language. A term of whole weeks
SHALL read in weeks. An unreadable case type SHALL answer 404.

#### Scenario: A term with an extension
@e2e exclude a read endpoint covered by tests/Unit/Service/CaseType/CaseTypeTermsTest.php

- **GIVEN** a case type with processing deadline P56D, an allowed extension of P42D and at most one extension
- **WHEN** its terms are requested
- **THEN** `decisionTerm` SHALL read "8 weeks, extendable once by 6 weeks"

### Requirement: The side facts read as labels (REQ-CTB-5)

The side column's data card SHALL show these rows:

- the domain;
- the origin, "Shipped" for a case type dossiq delivered and "Own case type"
  otherwise;
- the intake form, when one is bound;
- the handling teams.

It SHALL NOT show raw codes such as the handling model or the confidentiality
key.

#### Scenario: A shipped case type
@e2e exclude manifest configuration, covered by tests/vitest/caseTypeBoards.spec.js

- **GIVEN** a case type owned by `__system__`
- **WHEN** its page opens
- **THEN** the data card SHALL read Origin: Shipped
