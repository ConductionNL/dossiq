# Tasks: saved-filters-manage-and-defaults

- [ ] 1 On :8080, walk the Weergaven tab of the Cases side panel against the boards. Write down which actions from this spec the library's saved views control lacks.
- [ ] 2 Build what is missing generically in nextcloud-vue, with its tests.
- [x] 3 Declare the shipped views as read-only in `src/manifest.json`, with one view per case type under "Meer standaardweergaven".
  - The Cases page declares its shipped views in `defaultSavedViews` (`src/manifest.json`), which the library lists read-only under Default views; the case-type views are `secondary` (under "More default views"), added per case type by `/api/manifest` (`lib/Service/CaseTypeViews.php`). Each case-type view now shows the case type's own description under its name, and its title when it has none. Tests: `ManifestControllerTest::testManifestCaseTypeViewsShowTheCaseTypeDescription`, `CaseTypeMenuEntryTest::testTheDescriptionIsReadAndCarriedWhenPresent`, `MenuCaseTypesServiceTest::testByIdentifierCarriesTheDescription`.
- [ ] 4 `tests/e2e/case-views-manage.spec.ts`: rename, replace criteria, delete, copy a shipped view, add to and remove from the menu.
