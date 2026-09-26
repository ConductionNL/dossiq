# Tasks: case-type-version-chain

Tier: V1. Kind: config, plus the code half the re-read did not see. Row 2.3,
and row 3.16's dossiq half.

- [x] 1.1 `src/manifest.json` `#CaseTypeDetail`: widget `case-type-chain`
  (`object-list`, `caseType`, `filter.identifier: @object.identifier`, sort
  `version desc`, columns version, isDraft, validFrom, validUntil), placed
  above `case-type-versions`.
  - `tests/vitest/caseTypeAuthoringManifest.spec.js`: widget present, filter
    and sort as declared, every column bound to a real property
  - `@spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md`
- [x] 1.2 `#CaseTypeDetail` header action `case-type-new-version`. A DIALOG,
  not the `api-call` this task first named: an api-call refreshes the page you
  are already on, so the person who asked for a new version is left on the old
  one with the draft nowhere in sight. Same reason Duplicate is a dialog.
- [x] 1.3 `#CaseTypeDetail` header action `case-type-deprecate`. An `api-call`
  to `POST /api/case-types/{id}/deprecate`, not the object-op this task first
  named: an object-op merges its `values` verbatim, so `validUntil: "@today"`
  would have stored that literal string in a date field. `visibleWhen` is a
  source query counting published successors, because the local operator set
  has no is-set and `supersededBy neq null` reads TRUE on an unset field.
- [x] 2.1 `#CaseTypes`: chips Current versions (default, `supersededBy: "IS
  NULL"`) and All versions. A chip and not a base filter, so an auditor can
  still reach the older versions.
- [x] 3.1 `tests/e2e/case-type-version-chain.spec.ts`; the bulk scenario
  carries a reason-bearing `@e2e exclude`.

## What the re-read sized as S and was not

The chain was declared and the endpoint existed, which is what made this look
like configuration. Three things underneath were not true.

- [x] 4.1 A new version DROPPED its workflow. The copy carried the statuses,
  results, roles, attributes, document types and decision types, and not the
  workflow templates, so clearing `workflowDefinition` was the only honest
  thing the payload could do. Both halves move together now
  (`CaseTypeCopyService`, `DerivedCaseTypePayload`).
- [x] 4.2 Publishing wrote `supersededBy` and left `validUntil` open, so the
  index showed a superseded version valid indefinitely beside its successor.
  Closed on the day the successor takes effect, never today
  (`CaseTypePublishService::retire`).
- [x] 4.3 Row 3.16's dossiq half: `CaseVersionMove`, the preview, the refusal
  that names the status, the journal entry, and `MoveCaseTypeVersionAction`
  for the bulk variant through the job (#2832 handoff). The engine RUN does
  not move with the case: that is openregister's
  `migrate-run-between-versions`, and every answer says so rather than leaving
  it to be noticed.
