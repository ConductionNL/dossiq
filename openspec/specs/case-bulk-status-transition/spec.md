# case-bulk-status-transition Specification

## Purpose
TBD - created by archiving change case-bulk-status-transition. Update Purpose after archive.
## Requirements
### Requirement: Bulk transitions go through the engine

Bulk execution SHALL call `StatusTransitionService::execute()` once per case — evaluating guards and dispatching side effects per case — and SHALL NOT write status by any other path. A request SHALL be rejected with 400 when it contains more than 100 case ids, zero case ids, or no transition id.

#### Scenario: Guards evaluated per case

- **GIVEN** three cases where one is missing a required document for the transition
- **WHEN** bulk execute runs with that transition
- **THEN** two cases transition (side effects fire for each) and the third fails with its guard reasons
- **AND** the response reports per-case outcomes and summary counts (2 succeeded, 1 failed)

@e2e exclude Covered by PHPUnit service tests with a mocked engine (guard-fail path) — the engine's own guard behaviour has its own spec/tests.

#### Scenario: Oversized request rejected

- **WHEN** bulk execute is called with 101 case ids
- **THEN** the response is 400 and no case is transitioned

@e2e exclude PHPUnit-covered input validation; no browser surface.

### Requirement: Preview before execute

`POST /api/cases/bulk-transition/preview` SHALL return, per case, whether the transition is available and whether its guards currently pass (with failure reasons), performing no writes.

#### Scenario: Preview reports blockers without writing

- **GIVEN** a selection where one case would fail a role guard
- **WHEN** preview runs
- **THEN** the response marks that case as blocked with the guard's reason and the others as ready
- **AND** no case status changes

@e2e exclude PHPUnit service test asserts read-only behaviour (engine execute never invoked in preview).

### Requirement: Column-scoped selection on the workflow board

The workflow board SHALL offer a selection mode where cases can be multi-selected within a single column; selecting a case in a different column SHALL clear the previous selection. When one or more cases are selected, a bulk-actions bar SHALL offer "Change status…" opening the bulk-transition dialog scoped to that column's available transitions.

#### Scenario: Cross-column selection resets

- **GIVEN** two cases selected in the "Ontvangen" column
- **WHEN** the user selects a case in "In behandeling"
- **THEN** the selection contains only the newly selected case

@e2e exclude Selection reducer logic extracted to a helper and covered by vitest (node-env suite cannot mount SFCs); board rendering unchanged otherwise.

#### Scenario: Dialog previews then executes

- **GIVEN** three selected cases in one column and a chosen transition
- **WHEN** the user opens the bulk dialog
- **THEN** the dialog shows the preview per case (ready/blocked with reasons)
- **AND** confirming executes and shows per-case results without dismissing failures silently

@e2e exclude Dialog request/response orchestration extracted to a helper covered by vitest; endpoint behaviour covered by PHPUnit.

