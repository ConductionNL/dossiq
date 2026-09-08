# Tasks: case-identity

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The case number

- [x] 1.1 `lib/Settings/dossiq_register.json`, schema `case`: add the
  `x-openregister-calculations` entry for `identifier` per design D1 and set
  `readOnly: true`; do not add a `format`.
  - `@spec openspec/specs/case-management/spec.md`
  - verify on a live instance that a case posted without `identifier` gets
    `YYYY-0001` and the next one `YYYY-0002`
  - The declaration was already on `development` when this change was applied:
    `readOnly: true`, no `format`, and the exact D1 expression. Nothing about
    it was tested, so it could have been undone by any later edit to a
    10,500-line file without a word. `tests/Unit/Settings/CaseIdentitySchemaTest.php`
    now reads the shipped register and asserts the expression, so the
    declaration this whole change rests on cannot quietly disappear.
- [x] 1.2 `src/manifest.json`: `identifier` out of `new-case`'s form on page
  `Dashboard` and read-only in `case-core` on page `CaseDetail`.
  - `new-case` already omitted it. `case-core` listed it and rendered it
    nowhere: `fieldsFromSchema` drops a `readOnly` property before any
    override is read, so the number was invisible on the case page rather
    than read-only on it. The override is `readOnly: false` (re-admit the
    field) plus `editable: false` (keep it un-typeable); either half alone is
    wrong in a different direction.
- [x] 1.3 ~~[blocked: openregister a `sequence()` function in
  `x-openregister-calculations` scoped per year]~~ UNBLOCKED, and then
  belt-and-braces.
  - OpenRegister ships the operator: `sequence` is in
    `CalculationEvaluator`'s dispatch table and `SequenceService` reserves per
    scope, so the D1 declaration evaluates and 1.2 no longer waits.
  - It ships in the openregister on THIS rig. An install running an older one
    evaluates the same declaration to nothing, in the register, at evaluation
    time — no gate in dossiq can see that, and the symptom is exactly the one
    this change exists to end: a case filed without a number.
  - So `CaseNumberService` + `CaseNumberListener` backfill an EMPTY
    `identifier` on create, in the same `YYYY-NNNN` shape off the same start
    year. When the calculation ran, the field is filled and the service
    writes nothing. The number is MAX + 1 of the year's identifiers, never
    COUNT + 1: a count is one deletion away from handing a live case a number
    another case already holds.

## 2. Tags

- [ ] 2.1 `lib/Settings/dossiq_register.json`, schema `case`: property `tags`
  (array of strings, facet) per design D2.
- [ ] 2.2 `src/manifest.json` page `CaseDetail`: sidebar tab `tags` with a
  `data` widget over `tags` using the tags form widget; page `Cases`: a Tags
  filter in the sidebar.
- [ ] 2.3 `l10n/en.json` and `l10n/nl.json`: Tags, Terms and archive,
  Statutory lead time, Legal basis.

## 3. Terms and archive

- [ ] 3.1 `lib/Settings/dossiq_register.json`, schema `case`: property
  `legalBasis` (string).
- [ ] 3.2 `src/manifest.json` page `CaseDetail`: widget `case-terms` per
  design D3, with `extend: ["caseType"]` and the include path
  `caseType.processingDeadline`; add its layout cell without a reserved void.
  - verify the dotted include renders; if `data` cannot read an extended
    path, show `processingDeadline` on the existing `case-kpi-casetype` stat
    caption and record it here

## 4. Seed and verification

- [ ] 4.1 `lib/Settings/register.d/46-demo-cases-english.json`: identifiers
  in `YYYY-NNNN`, tags and a legal basis on the demo cases per design.
- [ ] 4.2 Add `tests/e2e/case-identity.spec.ts` covering every scenario of
  the delta spec that names it (a number on a new case from the form and
  from the API, a kept number on an existing case, tags on the sidebar and
  as a filter, the Terms and archive block).
- [ ] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
