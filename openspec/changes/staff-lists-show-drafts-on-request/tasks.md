# Tasks: staff-lists-show-drafts-on-request

Decision 201. One PR, `--base development`.

## 1. The chip

- [x] 1.1 (`src/manifest.json`, Cases `quickFilters`, chip With drafts after My drafts; `l10n/en.json`, `l10n/nl.json`) Declare the chip With drafts on the Cases page with the filter `{isFinalStatus: false, statusHiddenInLists: false, _drafts: "any"}` (REQ-CM-79).
  - vitest `tests/vitest/caseListLenses.spec.js`: "offers exactly one lens that shows drafts beside the working set", "is not the default lens, so drafts are hidden on the first paint", "asks for drafts on no other staff list". Red first on `origin/development`.

## 2. End to end

- [ ] 2.1 (live pass, decision 139; the spec is written: `tests/e2e/cases-with-drafts.spec.ts`) Run it: a colleague's draft the reader may read is absent under All and present under With drafts. Confirm first that the second account may create a case and that `@self.status: "draft"` on a create is how the instance takes a draft.
