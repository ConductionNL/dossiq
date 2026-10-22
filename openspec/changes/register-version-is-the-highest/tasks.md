# Tasks: register-version-is-the-highest

### Task 1: A merged schema carries the highest version its fragments declare
- **spec_ref**: `openspec/changes/register-version-is-the-highest/specs/admin-settings/spec.md#requirement-a-merged-schema-carries-the-highest-version-its-fragments-declare`
- **files**: `lib/Service/Settings/RegisterFragmentMerger.php`
- [x] Implement: `isLowerVersion`, used by `deepMerge`
- [x] Test: `tests/Unit/Service/Settings/RegisterFragmentMergerVersionTest.php`
