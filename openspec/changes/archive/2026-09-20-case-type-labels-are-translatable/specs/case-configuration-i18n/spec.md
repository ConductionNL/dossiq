## ADDED Requirements

### Requirement: A case type's labels carry every language (REQ-CFI-01)

You give a case type a title in each language your municipality serves. The
label bearing properties of the configuration schemas SHALL declare
`translatable: true` with `sourceLanguage: "nl"`: `caseType.title` and
`description`, `statusType.name` and `description`, `resultType.title` and
`description`, `documentType.title`, `roleType.title`, `transition.label`.
OpenRegister then stores each as a language keyed object and answers a read
in the language the caller asked for.

#### Scenario: A case type answers in the requested language
@e2e exclude Backend language negotiation, covered by Newman.

- **GIVEN** a case type whose title is stored as Dutch and English
- **WHEN** it is read with `Accept-Language: en`
- **THEN** the response SHALL carry the English title

#### Scenario: A missing translation falls back
@e2e exclude Backend language fallback, covered by PHPUnit.

- **GIVEN** a case type with a Dutch title and no Frisian title
- **WHEN** it is read with `Accept-Language: fy`
- **THEN** the response SHALL carry the Dutch title

### Requirement: A case is not a label (REQ-CFI-02)

The properties of `case` itself SHALL NOT be translatable. A case title, a
description and a note are what someone wrote about one case, and translating
them would put words in a citizen's mouth. Only configuration schemas carry
translatable properties.

#### Scenario: The case schema declares no translatable property
@e2e exclude Manifest and register validation, covered by vitest.

- **WHEN** the register fragments are checked
- **THEN** no property of `case` SHALL declare `translatable: true`

### Requirement: The case type page edits its translations (REQ-CFI-03)

`#CaseTypeDetail` SHALL surface the language tabs OpenRegister renders for a
translatable property, so an admin edits the English title on the page that
owns the case type. The page SHALL show a completeness chip per language,
read from the register's translation stats.

#### Scenario: An admin adds an English title
@e2e tests/e2e/case-type-labels-are-translatable.spec.ts

- **GIVEN** a case type with a Dutch title only
- **WHEN** an admin opens the page, picks English and types a title
- **THEN** the English title SHALL be stored against the case type
- **AND** the Dutch title SHALL be unchanged

#### Scenario: The page says what is still missing
@e2e tests/e2e/case-type-labels-are-translatable.spec.ts

- **GIVEN** a case type with two of its four labels translated into English
- **WHEN** an admin opens the page
- **THEN** the English chip SHALL report two of four

### Requirement: A changed source label marks its translations stale (REQ-CFI-04)

Because each translatable property declares `sourceLanguage: "nl"`, a change
to the Dutch value SHALL mark the other languages of that property stale, and
the completeness chip SHALL count a stale translation as missing. A stale
label is a wrong label, not a present one.

#### Scenario: Editing the Dutch title restales the English one
@e2e exclude Backend translation status, covered by PHPUnit.

- **GIVEN** a case type with a Dutch and an English title, both current
- **WHEN** the Dutch title is changed
- **THEN** the English title SHALL be marked stale
- **AND** the English completeness count SHALL drop by one

### Requirement: The annotation is the key OpenRegister reads (REQ-CFI-05)

`lib/Settings/` SHALL declare `translatable`, never `x-translatable`.
OpenRegister's `TranslationHandler` tests `propertyDef['translatable']`, so a
prefixed key marks nothing while reading as a declaration. No file under
`lib/Settings/` SHALL contain `x-translatable` after this change.

#### Scenario: The prefixed key is gone
@e2e exclude Register declaration, covered by vitest.

- **WHEN** the register files are checked
- **THEN** `x-translatable` SHALL appear in none of them
- **AND** every property that carried it SHALL carry `translatable` instead
