---
kind: config
depends_on: []
---

# Proposal: case-type-labels-are-translatable

Gap scan of the parity ledger, 2026-09-18, pack d, row 11.13 "Translation
management in the UI". Rated `no` for dossiq, `yes` for GZAC.

## Why

dossiq ships in 38 languages and its case types speak one. `l10n/` covers the
buttons, the menus and the empty states. The words a municipality actually
writes, a case type's title, a status name, a result type, a document type,
exist once, in whatever language the admin typed. A Frisian or an English
portal shows the Dutch label back to the citizen.

The engine for this is OpenRegister's and it is built, not planned.
`TranslationHandler` normalises a translatable property to a language keyed
object, `TranslationProjectionService` and its listener project it into the
sidecar, `TranslationStatusService` counts what is still missing,
`BulkTranslationService` translates a whole object, and
`i18n-api-language-negotiation` answers `Accept-Language` on reads and
`X-Translation-Target-Language` on writes. `register-i18n` specifies the
language aware object editor and the completeness dashboard.

dossiq marks its labels with a key nothing reads. `dossiq_register.json`
carries `x-translatable` twenty-four times, `dossiq_mock_register.json`
twenty-one, and two fragments carry five more. `StatusTypeLookup` and
`SetStatusHandler` both say in their headers that `statusType.name` is
declared `x-translatable`, and reason about it. OpenRegister reads the bare
key: `TranslationHandler::getTranslatableProperties()` tests
`($propertyDef['translatable'] ?? false) === true`, and `docs/i18n.md` says
"Translatable properties carry `translatable: true`". `x-translatable` returns
nothing from an OpenRegister code search.

So the annotation is decorative. Nobody was careless: the register says
translatable, the docblocks say translatable, and the engine has never once
been asked. That is why this row reads as covered. dossiq's umbrella
`competitor-parity-2026-09` puts 11.13 under what somebody else covers:
"register content is translatable through openregister `register-i18n`; UI
strings stay in l10n by decision C05". The first half of that sentence is
true of OpenRegister and false of dossiq, because of one prefix.

dossiq's own archived `2026-05-11-register-i18n` put "Admin translation
management UI (REQ-I18N-006)" under Out of Scope (V2). Nothing picked it up.

Decision C05 stays. UI strings stay in `l10n`. A case type's title is
register content, which is exactly where C05 sends it.

## What changes

- Every `x-translatable` becomes `translatable`, so the twenty-nine
  annotations already written start being read. The label bearing properties
  the sweep misses are added: `caseType.title` and `description`,
  `statusType.name` and `description`, `resultType.title` and
  `description`, `documentType.title`, `roleType.title`,
  `transition.label`.
- A test that fails on `x-translatable` anywhere in the register, so the
  prefix cannot come back.
- Each carries `sourceLanguage: "nl"`, which OpenRegister's
  `i18n-source-of-truth` requires beside `translatable`, so a changed Dutch
  label marks its translations stale instead of silently disagreeing.
- The case type page gets the language tabs OpenRegister already renders for
  a translatable property, and a completeness chip per language.
- Nothing on the case itself becomes translatable. A case's title is what
  someone wrote about one case, not a label, and translating it would be a
  claim about a citizen's words.

## Ownership

OpenRegister owns the mechanism and the editor. dossiq declares which
properties are labels. The only dossiq code is the declaration and the page
that surfaces the editor.

## ADRs

- Company ADR-022: dossiq consumes OpenRegister's abstractions.
- Company ADR-031: the declaration is schema data.

## Capabilities

- Added: `case-configuration-i18n`: a case type's labels carry every language
  the register declares.

## Impact

`lib/Settings/dossiq_register.json` and the `register.d` fragments that
declare configuration schemas, `src/manifest.json` `#CaseTypeDetail`, one
vitest test, one e2e spec. No PHP.
