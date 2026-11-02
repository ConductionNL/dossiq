# Proposal: every-move-into-deliberation-runs-the-panel-check

## Why

Under Awb Art. 7:13 lid 3, no panel member of a bezwaaradviescommissie may have worked on the contested primair besluit. `AdvisoryCommitteeService::transitionAdviceStatus()` runs that check before an advice request moves from `assigned` to `in-deliberation`. Nothing in the app calls that method, though. The advice request page moves `status` through its lifecycle actions, and those write the object in OpenRegister directly. So in production the panel check never ran, and a conflicted or unverifiable panel could start deliberating.

## What changes

- A pre-save listener on OpenRegister's `ObjectUpdatingEvent` watches advice requests.
- When a write moves an advice request into `in-deliberation`, the listener runs the same panel check as `transitionAdviceStatus()`.
- A refused check stops the write. OpenRegister answers the write with the refusal, and the request keeps its old status.
- The refusal is recorded on the request's trail first, as `independence-check-failed` or `independence-check-unverifiable`.
- A check that fails with an unexpected error also stops the write.
- Writes that do not move the status into `in-deliberation` are not checked.

## What does not change

- The panel check itself, its reasons and its trail entries.
- The advice request page and its lifecycle actions. No manifest change is needed.
- Creating an advice request directly in `in-deliberation` is out of scope. The only writer, `assignToCommittee()`, creates it in `assigned`.
