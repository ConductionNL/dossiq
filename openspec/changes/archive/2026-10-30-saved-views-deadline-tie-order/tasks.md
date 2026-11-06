# Tasks: saved-views-deadline-tie-order

### Task 1: Saved views break a deadline tie on receivedAt
- **spec_ref**: `openspec/changes/saved-views-deadline-tie-order/specs/case-search-and-lists/spec.md`
- **files**: `lib/Settings/example-sets/zuiddrecht.json`, `lib/Settings/example-sets/zuiddrecht.d/30-menu-views.json`, `src/manifest.json`
- [x] Implement: `receivedAt` asc as the second sort key of every deadline-sorted saved view
- [x] Test: `tests/Unit/Settings/ZuiddrechtSavedViewsTieOrderTest.php`, `ZuiddrechtOwnSavedViewTest`
