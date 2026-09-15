# Tasks: case-type-version-chain

Tier: V1. Kind: config. Row 2.3.

- [ ] 1.1 `src/manifest.json` `#CaseTypeDetail`: widget `case-type-chain`
  (`object-list`, `caseType`, `filter.identifier: @object.identifier`, sort
  `version desc`, columns version, isDraft, validFrom, validUntil), placed
  above `case-type-versions`.
  - `tests/vitest/caseTypeAuthoringManifest.spec.js`: widget present, filter
    and sort as declared
  - `@spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md`
- [ ] 1.2 `#CaseTypeDetail` header action `case-type-new-version`
  (`api-call`, POST `/api/case-definitions/@objectId/new-version`, navigate
  to the returned id).
- [ ] 1.3 `#CaseTypeDetail` header action `case-type-deprecate` writing
  `validUntil: @today`, `visibleIf` `supersededBy` set and `isDraft` false.
- [ ] 2.1 `#CaseTypes`: default filter `supersededBy IS NULL`, chip All
  versions without it.
- [ ] 3.1 `tests/e2e/case-type-version-chain.spec.ts` for the four scenarios;
  `openspec validate case-type-version-chain --strict`.
