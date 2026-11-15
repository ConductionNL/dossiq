# Design: case-merge

## D-1. The declaration says what moves

Relink: tasks (engine tasks by subject), documents (Files nodes and
projections), roles, relations, notes, mail links. Keep on the survivor:
every field. Reversal window: 7 days. Declared once in the `mdm-merge`
shape.

## D-2. The term is dossiq's consequence

On the platform's merge event for a `case`, a deferred listener completes
the merged case's active `deadlineInstance` with reason merged and the
survivor's id (its engine timer is cancelled by `markTermijnCompleted()`).
On the reversal event it recreates an instance from the completed one's
dates and re-arms. Nothing else about terms changes.

## D-3. The old number resolves

`mergedInto` is a reference. `email-case-matching` resolves a matched case
through `mergedInto` until it lands on a case without one; `PublicStatusPage`
does the same and shows the survivor's status.

## D-4. Refusals

A case with a signed beschikking or in a final status is refused as a
merge source by the same guard `case-delete-guard` uses, with the rule
named.
