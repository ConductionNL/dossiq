# Design: dependent-term-follows-predecessor

## D-1. `waitsOn` and `blocks` are one pair

`RELATION_TYPES` gains `waitsOn`; the service writes the inverse `blocks`
on the other side, as it does for the symmetric types. The Related tab
lists both with a direction word.

## D-2. The trigger is the deadline event

The listener handles the create of a `deadlineEvent` with kind `verleng` or
`pauze` on a case, deferred (ADR-078). It reads the cases whose
`relatedCases` name the source with `waitsOn`, bounded to 50 with a warning
beyond, and creates one engine task per dependent for its handler,
`kind: term-follow`, carrying the source case and `daysImpact`.

## D-3. Accepting is the existing extension

The task's action calls `DeadlineExtensionService::extend()` for the
dependent's active instance with `daysImpact` and the reason "follows
<source>", which goes through the same refusal rules (ceiling, supervisor)
as a manual extension. Declining completes the task with no change.

## D-4. Migration to the primitive

When `relation-types-with-inverses` ships, the pair is declared there and
`relatedCases` stops carrying `waitsOn`; the listener reads the relation
instead. One task, marked blocked on that change.
