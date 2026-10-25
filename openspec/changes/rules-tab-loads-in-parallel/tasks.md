# Tasks: rules-tab-loads-in-parallel

### Task 1: The Rules tab reads side by side
- **spec_ref**: `openspec/changes/rules-tab-loads-in-parallel/specs/case-types/spec.md#requirement-the-rules-tab-reads-side-by-side`
- **files**: `src/views/settings/tabs/RulesTab.vue`
- [x] Implement: Promise.all in load() and loadCaseType()
- [x] Test: `tests/vitest/rulesTabParallelReads.spec.js`
