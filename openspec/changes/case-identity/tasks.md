# Tasks: case-identity

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The case number

- [ ] 1.1 `lib/Settings/dossiq_register.json`, schema `case`: add the
  `x-openregister-calculations` entry for `identifier` per design D1 and set
  `readOnly: true`; do not add a `format`.
  - `@spec openspec/specs/case-management/spec.md`
  - verify on a live instance that a case posted without `identifier` gets
    `YYYY-0001` and the next one `YYYY-0002`
- [ ] 1.2 `src/manifest.json`: `identifier` out of `new-case`'s form on page
  `Dashboard` and read-only in `case-core` on page `CaseDetail`.
- [ ] 1.3 [blocked: openregister a `sequence()` function in
  `x-openregister-calculations` scoped per year] Until it ships, 1.1 is
  declared but inert and 1.2 waits; the field stays free text.

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
