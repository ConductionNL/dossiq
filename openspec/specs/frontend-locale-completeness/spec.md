# frontend-locale-completeness Specification

## Purpose

English is the source language and Dutch is a translation of it. Translation keys
read as English, the two locale files carry the same key set, and no key in use
renders Dutch to an English reader.

## Requirements

### Requirement: Translation keys are English source

Every `t('dossiq', ...)` / `n('dossiq', ...)` call site MUST use an English string as the
translation key. Dutch text MUST NOT be used directly as a translation key.

#### Scenario: Dutch-literal key is rejected

- **GIVEN** a developer writes `t('dossiq', 'Afgesloten')` where `'Afgesloten'` is the intended
  Dutch UI text
- **WHEN** the string is reviewed against this requirement
- **THEN** the call MUST use an English source key instead (e.g. `t('dossiq', 'Closed')`)
- **AND** `l10n/nl.json` MUST map that English key to the Dutch text (`"Closed": "Afgesloten"`)

#### Scenario: English-locale users never see raw Dutch text

- **GIVEN** the Nextcloud UI language is set to English
- **WHEN** any dossiq page renders
- **THEN** no visible string SHALL be untranslated Dutch text (e.g. `Aanbestedingen`, `Actie`,
  `Afgesloten`, `Adres bijgewerkt` rendering verbatim instead of an English equivalent)

### Requirement: en.json and nl.json key sets match

`l10n/en.json` and `l10n/nl.json` MUST contain the same set of translation keys — every key
present in one MUST be present in the other with a non-empty value.

#### Scenario: No missing Dutch translation

- **GIVEN** a translation key exists in `l10n/en.json`
- **WHEN** `l10n/nl.json` is checked for the same key
- **THEN** the key MUST be present with a non-empty Dutch translation
- **AND** a Dutch-locale user MUST NOT see the raw English key literal as fallback text

#### Scenario: Coverage check is automated

- **GIVEN** a new translation key is added to `en.json` without a corresponding `nl.json` entry
- **WHEN** `npm run test:l10n` is executed
- **THEN** the check MUST fail, reporting the specific key(s) missing from `nl.json`

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

### Requirement: Field override labels and the term tile are translated (REQ-FLC-012)

A field override's `label` and a countdown tile's `label` SHALL pass through the
app's translation before they reach the renderer, so a field the manifest
relabels reads in the reader's language on the create form, the edit form and
the case panels.

#### Scenario: The requester field reads Aanvrager
@e2e exclude {the translation pass runs on the built manifest before render; asserted for both structure profiles in tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js and checked live with a screenshot}

- **GIVEN** a reader whose language is Dutch
- **WHEN** they open the new case form, the case edit form or the case's Overview tab
- **THEN** the requester field SHALL be labelled "Aanvrager"

#### Scenario: The term card has one Dutch heading
@e2e exclude {asserted on the translated manifest in tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js and checked live with a screenshot}

- **GIVEN** a reader whose language is Dutch, in the simple structure
- **WHEN** they open a case
- **THEN** the term card SHALL be headed "Termijn" once, and no "Deadline" SHALL show

### Requirement: A refused lifecycle act is told in the reader's language (REQ-FLC-013)

The sentence the server gives for a refused finish, abort or archive SHALL be
translated on the server, so the lifecycle dialog shows it in the reader's
language.

#### Scenario: An act that needs an administrator
@e2e exclude {needs a non-admin user on a case type without a declared group; the translation is asserted in tests/Unit/Service/CaseLifecycleGateTest.php}

- **GIVEN** a reader whose language is Dutch who is not an administrator
- **WHEN** they open the lifecycle dialog on a case whose type declares no group for archiving
- **THEN** the refusal SHALL read "Voor deze handeling is een beheerder nodig."

### Requirement: A refusal MUST reach the reader in their language

A refusal's `message` SHALL be its sentence translated through the reader's translator. A sentence the catalogue does not hold SHALL go out as written, and SHALL never be empty because translation failed. The `error` and `code` keys SHALL keep their slugs.

#### Scenario: Closing without a result answers in Dutch

- **GIVEN** a handler whose language is Dutch
- **WHEN** they close a case whose case type has result types without picking one
- **THEN** the response is 422 with `message` "Kies een resultaat voordat je deze zaak afsluit."
- **AND** `error` is `result-type-required` and `code` is `result_type_required`

#### Scenario: No translator keeps the sentence

- **GIVEN** a request with no translator available
- **WHEN** the same refusal is answered
- **THEN** `message` is "Pick a result before closing this case."
