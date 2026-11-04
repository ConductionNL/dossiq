# Proposal: only-the-advice-transition-sets-the-outcome

## Why

An advice request reaches `advice-issued` or `inadmissible` when the committee issues its advice. `AdvisoryCommitteeService::transitionAdviceStatus()` checks that move: the advice fields must be complete, and the advice decision is raised in decidiq. The change creating-into-deliberation-runs-the-panel-check already refuses a write that skips deliberation. A raw write from `in-deliberation` straight into `advice-issued` still bypasses those checks: an API call, a bulk save, an import or a flow write.

## What changes

- A small in-process marker, `InternalWriteMarker`, counts how deep the app is inside its own writes. It is a depth counter on a shared service instance, never request input.
- `transitionAdviceStatus()` runs its save inside the marker.
- `DeliberationStartGuard` refuses a write that moves an advice request into `advice-issued` or `inadmissible` without the marker. The error code is `deliberation-outcome-refused`.
- A save that keeps a request in `advice-issued` or `inadmissible` is not a move into it, so the marker does not apply. That keeps edits after the advice working as they do today.
- `transitionAdviceStatus()` had no caller. The advice request page moved `status` through OpenRegister directly, so with the marker rule nobody could issue committee advice. A new endpoint, `POST /api/bezwaar/advice-requests/{id}/transition`, runs the transition for members of `bezwaarcommissie` and administrators. The page's lifecycle action needs to call it; that manifest change belongs to the screens work.
- The marker is kept per advice request, so a nested save of another request does not count as marked.

## What does not change

- The panel check and the skip rule.
- Bulk saves, imports and flow writes fire no save event until OpenRegister #5376 lands for this schema. From then on, they are refused too when they write one of these statuses.

## Seed data

`lib/Settings/dossiq_mock_register.json` holds advice request `119d5692-4985-47ca-b570-d30fdb44dc6d` in `advice-issued`. Once imports fire the save events, importing it is refused, because the import carries no marker.
