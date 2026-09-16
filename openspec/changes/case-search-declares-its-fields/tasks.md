# Tasks: case-search-declares-its-fields

Tier: V1. Kind: code. Rows 9.2, 9.1, 9.12. Consumer half of openregister
`search-quality-operators-and-facets` (#3768, #3806, both merged).

- [x] 1.1 Measure the contract on openregister `development` before
  declaring: the property keys are `matchType` and `inputControl`, read by
  `PropertySearchProfile`; the vocabularies are the `MATCH_TYPES` and
  `INPUT_CONTROLS` constants; `PropertyValidatorHandler` refuses an unknown
  one at schema save; `FacetHandler` answers `searchable_fields`;
  `MagicSearchHandler::participatesInFreeText()` decides which columns the
  scan reads.
- [x] 1.2 `lib/Settings/register.d/39-search-declarations.json`: declare
  `matchType` and `inputControl` per case property, per D-3 and D-4.
  - vitest: every declared value is in openregister's vocabulary; no
    boolean declares a match type; the named fields carry the named types
  - `@spec openspec/changes/case-search-declares-its-fields/specs/case-search-via-or-unified-search/spec.md`
- [x] 2.1 `src/utils/searchRefusal.js`: read a refused search off the
  library's ApiError, recover the position, keep the typed term.
  - vitest, mutation-checked
- [x] 2.2 `src/components/search/CaseSearchRefusal.vue` in the Cases page's
  `after-search` slot; registered in `src/registry.js` and
  `src/customComponents.js`; `src/manifest.json` `Cases` gains `slots`.
  - vitest: the hint renders over a refusal and not over an empty result
- [x] 2.3 `src/components/initiator/InitiatorPicker.vue`: show the refusal
  instead of the empty state.
  - vitest over a refused fetch
- [x] 3.1 `src/utils/selectionScope.js`: `_search` is a filter. Remove it
  from `NOT_A_FILTER`.
  - vitest: the term reaches `buildSelection`, paging still does not
- [x] 4.1 `src/manifest.json` `Cases`: the lens for a closed case with no
  result, spelled `result_isnull=true`.
  - vitest over the manifest declaration
- [x] 5.1 `src/views/settings/tabs/SearchIndexTab.vue` reading
  openregister's `/api/settings/search-index`, on the
  `ArchivalSettingsTab` pattern; three lines in
  `src/views/settings/AdminRoot.vue`.
  - vitest: the status renders, and a refusal renders as a refusal
- [x] 6.1 `tests/e2e/case-search-declares-its-fields.spec.ts`, tagged and
  not run locally; `openspec validate case-search-declares-its-fields
  --strict`.
- [ ] 7.1 Re-rate rows 9.2, 9.1 and 9.12 when nextcloud-vue#1176 lands the
  missing-value chip. The lens ships without it; the sidebar chip does not.
