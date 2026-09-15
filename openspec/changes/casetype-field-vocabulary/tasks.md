# Tasks: casetype-field-vocabulary

Tier: V1. Kind: code. Size M. Round 4 discovery, depth-study cluster CT-1,
rows A1, A2, A3, A5, A9, A11, A12, A13, B7, B10, plus the A4 defect and
the B2 exposure. Decisions D3 (JSON AST) and D2 (declared source). Waits
on nothing: every engine behind every new type is already shipped in
openregister.

- [x] 1.1 `lib/Settings/dossiq_register.json`: widen
  `propertyDefinition.propertyType` to the types
  `PropertyValidatorHandler.php:44-63` validates (D-2).
  - `tests/vitest/propertyDefinitionSchema.spec.js`
  - `@spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md`
- [x] 1.2 Add `format`, `pattern`, `minimum`, `maximum`, `itemsType`,
  `ref` and `helpText` to `propertyDefinition`, with `format` carrying the
  single-line, multi-line, markdown, html, date and datetime variants
  (D-3, D-4).
- [x] 1.3 Forward every added key through
  `schemas.case.properties.caseType.x-openregister-extends-form.map`
  (D-1).
- [x] 1.4 Add a build check that fails when a `propertyType` value is not
  forwarded by the map, and when a value is offered that the engine does
  not validate (D-1, D-2).
  - `tests/vitest/propertyDefinitionSchema.spec.js`
- [x] 2.1 Forward `x-openregister-calculations` and not the Twig
  `computed` key (D-5).
- [x] 2.2 Forward `x-openregister-property-source`, without shipping a
  resolver (D-8). Follow the key name integriq's
  `registry-backed-field-source` asked openregister for; if that lane
  picks another name, follow it here.
- [x] 3.1 `src/views/settings/tabs/PropertiesTab.vue`: an input per new
  key, including the `enumValues` input that was never there, and a
  refusal on an empty `enum` (D-6).
  - `tests/vitest/propertiesTab.spec.js`
- [x] 3.2 Render an unknown stored `propertyType` read-only with its type
  named, and never coerce it (D-7).
  - `tests/vitest/propertiesTab.spec.js`
- [x] 4.1 Dutch and English strings for every new key and its help text.
- [x] 4.2 `tests/e2e/casetype-field-vocabulary.spec.ts`: declare an array
  field, a file field, a multi-line text field, a second BAG-sourced
  address, a computed fee and an `enum` with values, then fill each one on
  a case; `openspec validate casetype-field-vocabulary --strict`.

## Notes on what shipped, where it differs from the task text

- **1.2 / 2.1 / 2.2, the key names follow the vocabulary.** `itemsType` is
  `items` (D-10), `helpText` is `description` (D-9), and the computed key is
  `calculation`, which is what OpenRegister publishes for the JSON expression
  (`x-openregister-calculations` is in no published list). The source key is
  carried on the definition and not forwarded, because OpenRegister has not
  published it (D-12).
- **4.2, the e2e is written and tagged, not run.** There is no Playwright run
  and no instance on the build host. The selectors are unverified until CI.
- **Not in this change:** the case form renderer reads nine roles today, so
  `pattern`, `minimum`, `maximum`, `items`, `$ref` and `calculation` are
  declared and forwarded but inert until `@conduction/nextcloud-vue` reads
  them. That is the next lane, and it is named in the PR body rather than
  left for someone to discover.
