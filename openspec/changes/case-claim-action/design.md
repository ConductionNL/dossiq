# Design: case-claim-action

## D-1. Two handlers, one write each

`claimCase(objectId)` writes `{assignee: currentUser}`; `releaseCase(objectId)`
writes `{assignee: null}`. Both go through the object store's update path so
the audit row is the platform's. No reason field: a claim is not a reassign.

## D-2. Visibility is declared

Claim carries `visibleIf: assignee empty`; Release `visibleIf: assignee ==
@me`. `@me` is the token `one-case-list` already uses on the Mine chip.

## D-3. Refusal is the platform's

If the store refuses the write (RBAC, lock), the handler shows the platform
message and changes nothing. No optimistic update.
