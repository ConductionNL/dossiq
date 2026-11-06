# frontend-locale-completeness delta: dutch-copy-on-the-case-page

## ADDED Requirements

### Requirement: Labels the library draws as written are translated by the app (REQ-FLC-010)

Every label in the app's manifest that the library draws as written SHALL pass
through the app's translation before it reaches the renderer: lens labels,
captions, link texts, banner copy, tab labels, section labels and the tab strip's
own labels. A Dutch reader SHALL see the case page's tabs and the panels under
them in Dutch.

#### Scenario: The case tabs are Dutch
@e2e exclude {the translation pass runs on the built manifest before render; asserted for both structure profiles in tests/vitest/dutchCopyOnTheCasePage.spec.js and checked live with a screenshot}

- **GIVEN** a reader whose language is Dutch
- **WHEN** they open a case
- **THEN** the tabs SHALL read Overzicht, Documenten, Contact, Taken, Geschiedenis
- **AND** the first panel SHALL be headed "Kerngegevens van de zaak"

#### Scenario: The dashboard captions are Dutch
@e2e exclude {asserted on the translated manifest in tests/vitest/dutchCopyOnTheCasePage.spec.js}

- **GIVEN** a reader whose language is Dutch
- **WHEN** they open the Dashboard
- **THEN** the My tasks tile SHALL read "vandaag af" and the SLA tile "haalden hun termijn"

### Requirement: A refused case change is told in the reader's language (REQ-FLC-011)

When the server answers a lifecycle gesture with `code: change_failed`, the page
SHALL show the translated sentence for that code and not the server's English
`error`.

#### Scenario: A failed gesture reads Dutch
@e2e exclude {the failure needs a server fault to provoke; the code mapping is asserted in tests/vitest/dutchCopyOnTheCasePage.spec.js}

- **GIVEN** a reader whose language is Dutch
- **WHEN** a pause, resume or extend fails on the server with `change_failed`
- **THEN** the page SHALL say "De zaak kon niet worden gewijzigd."
