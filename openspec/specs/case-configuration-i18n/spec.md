---
status: done
---

# case-configuration-i18n Specification

## Purpose

dossiq ships in 38 languages and its case types spoke one. `l10n/` covers the buttons, the menus and the empty states; the words a municipality writes, a case type's title, a status name, a result type, a document type, existed once, in whatever language the admin typed.

The engine is OpenRegister's and was already built: `TranslationHandler` normalises a translatable property to a language keyed object, `TranslationProjectionService` projects it into the sidecar, `TranslationStatusService` counts what is still missing, and language negotiation answers `Accept-Language` on reads. This capability is dossiq's half of it: which properties are labels, which language they were written in, and the surface that lets somebody fill the rest.

## Requirements

### Requirement: A case type's labels carry every language (REQ-CFI-01)

You give a case type a title in each language your municipality serves. The
label bearing properties of the configuration schemas SHALL declare
`translatable: true` with `sourceLanguage: "nl"`: `caseType.title` and
`description`, `statusType.name` and `description`, `resultType.title` and
`description`, `documentType.title`, `roleType.title`, `transition.label`.
OpenRegister then stores each as a language keyed object and answers a read
in the language the caller asked for.

The register SHALL declare its own `languages`, source language first, because
`Register::getDefaultLanguage()` returns `languages[0]` and a register that
declares none leaves every property on the hardcoded fallback.

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

#### Scenario: Every translatable property names its source language
@e2e exclude Register declaration, covered by tests/Unit/Settings/TranslatableLabelsTest.php.

- **WHEN** the shipped register files are checked
- **THEN** every property declaring `translatable: true` SHALL also declare
  `sourceLanguage`
- **AND** the dossiq register SHALL declare `languages` with Dutch first

### Requirement: A case is not a label (REQ-CFI-02)

The properties of `case` itself SHALL NOT be translatable. A case title, a
description and a note are what someone wrote about one case, and translating
them would put words in a citizen's mouth. Only configuration schemas carry
translatable properties.

`complaint.subject` is the one instance field that stays marked, and it stays
marked for a reason that is not about labels: `normalizeTranslationsForSave()`
has wrapped every row written since 2026-09-18 into `{"nl": …}`, and unmarking
it without a repair step would render those stored rows as an object.

#### Scenario: The case schema declares no translatable property
@e2e exclude Manifest and register validation, covered by PHPUnit.

- **WHEN** the register fragments are checked
- **THEN** no property of `case` SHALL declare `translatable: true`

### Requirement: The case type page edits its translations (REQ-CFI-03)

`#CaseTypeDetail` SHALL carry a surface on which an admin reads and writes a
case type's labels in every language the register declares, with a
completeness chip per language.

The surface SHALL be dossiq's own widget rather than a library or OpenRegister
rendering. `@conduction/nextcloud-vue` 3.4.0 contains no reference to
`translatable`, `languageMeta` or `sourceLanguage`, so no declared widget can
express a language tab, and OpenRegister renders nothing into a leaf app's
page. The mechanism stays OpenRegister's: the widget reads
`_meta.languageMeta` for which properties are labels, the translation sidecar
for their status, and writes back through the objects API.

A write SHALL carry the whole language keyed map for the property. Sending one
language under `X-Translation-Target-Language` makes
`normalizeTranslationsForSave()` build a fresh single-key map, and whether the
other languages survive then depends on merge behaviour the browser cannot
see.

The surface SHALL NOT translate anything. `BulkTranslationService` is
OpenRegister's and a second way to start it does not belong in the app that
does not own it.

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

#### Scenario: The page offers only the labels the engine holds
@e2e exclude Frontend widget, covered by tests/vitest/caseTypeTranslations.spec.js.

- **GIVEN** a case type whose envelope names two translatable properties
- **WHEN** the page is opened
- **THEN** it SHALL offer exactly those two
- **AND** a property dossiq declares that the envelope omits SHALL NOT be
  offered

### Requirement: A changed source label marks its translations stale (REQ-CFI-04)

Because each translatable property declares `sourceLanguage: "nl"`, a change
to the Dutch value SHALL mark the other languages of that property stale, and
the completeness chip SHALL count a stale translation as missing. A stale
label is a wrong label, not a present one.

This is deliberately not OpenRegister's `completeness`.
`TranslationMapper::getCompletenessByObject()` counts every row with a
non-empty value and never reads its status, so a translation the source moved
out from under still counts as done. The chip applies the rule this
requirement states, in the widget.

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
`lib/Settings/` SHALL contain `x-translatable`.

A change to a register's `languages` SHALL move the register's `version` with
it. `importRegister()` returns on the version gate without comparing content,
unlike the schema path, so a list under an unmoved version reaches no instance
that already holds the register.

#### Scenario: The prefixed key is gone
@e2e exclude Register declaration, covered by PHPUnit.

- **WHEN** the register files are checked
- **THEN** `x-translatable` SHALL appear in none of them
- **AND** every property that carried it SHALL carry `translatable` instead

#### Scenario: The engine sees the mark
@e2e tests/e2e/translatable-labels.spec.ts

- **GIVEN** a running instance that imported the dossiq register
- **WHEN** a case type is read with `?_translationMeta=true`
- **THEN** `_meta.languageMeta` SHALL carry an entry for `title`
- **AND** it SHALL carry no entry for `identifier`, which is not translatable
