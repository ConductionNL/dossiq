---
kind: feature
---

# Bulk status transitions from the workflow board

## Why

Bulk operations on case lists are a repeatedly-evidenced gap: the 2026-02 feature counsel ranked them SHOULD (3 personas), the Spectr insight base flags "Bulk case operations needed for efficiency in high-volume case types", and 2026-07 user-wish research ranks "bulk actions on results" in the top-12. Procest has bulk for dossier documents and handler reassignment, but a behandelaar who must move 30 identical aanvragen from "Ontvangen" to "In behandeling" clicks through them one by one.

The single write-path invariant of the status-transition engine must hold: bulk is a loop over `StatusTransitionService::execute()` — per-case guard evaluation and side effects — never a shortcut around it.

## What changes

Backend:
- New `lib/Service/BulkStatusTransitionService.php` — `preview(array $caseIds, string $transitionId)` (per case: transition available? guards pass? → predicted outcome, no writes) and `execute(array $caseIds, string $transitionId, ?string $comment)` (loops the engine's `execute()`; per-case success/failure collected; partial success allowed; hard cap of 100 ids per call).
- `StatusTransitionController`: `bulkPreview()` + `bulkExecute()` actions with routes `POST /api/cases/bulk-transition/preview` and `POST /api/cases/bulk-transition/execute` (NoAdminRequired; authenticated; per-case authorization is whatever the engine already enforces per case).

Frontend (workflow board):
- Selection mode on the board: `CaseCard` gets a selection checkbox; selection is scoped to ONE column at a time (same status ⇒ homogeneous available transitions; selecting in another column clears the previous selection).
- A bulk-actions bar (pattern of `src/views/cases/components/BulkActionsBar.vue`) appears when cases are selected, offering "Change status…".
- New `src/dialogs/BulkTransitionDialog.vue` (modal isolation): pick one of the column's available transitions, optional comment, shows the preview result (per-case pass/fail with guard reasons), then Execute applies and reports per-case results.

Tests: PHPUnit for service + controller (engine mocked; cap, partial failure, guard-fail paths), vitest for the extracted selection/preview helper logic (node-env suite, no SFC mounting).

## Impact

Additive. No change to single-case transition behaviour; the engine remains the only write path, so guards (RequiredField/RequiredDocument/Role/Mandaat/Checklist) and side effects (email/webhook/task creation) fire per case exactly as they do today. Partial failures are surfaced, never silently swallowed.

## Capabilities

### New Capabilities
- `case-bulk-status-transition` — behandelaren select multiple cases in a workflow-board column and execute one status transition across them with per-case guard evaluation, preview, and per-case result reporting.
