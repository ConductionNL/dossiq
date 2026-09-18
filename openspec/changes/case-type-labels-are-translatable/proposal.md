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

dossiq reaches none of it. `grep -rn '"translatable"' lib/Settings/` returns
zero hits, across `dossiq_register.json` and every `register.d` fragment. A
property that is not marked translatable cannot hold a second language, so
the editor has nothing to show.

dossiq's own archived `2026-05-11-register-i18n` put "Admin translation
management UI (REQ-I18N-006)" under Out of Scope (V2). Nothing picked it up.

## What changes

- The label bearing properties of the configuration schemas are marked
  `translatable: true`: `caseType.title` and `description`,
  `statusType.name` and `description`, `resultType.title` and
  `description`, `documentType.title`, `roleType.title`,
  `transition.label`.
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
