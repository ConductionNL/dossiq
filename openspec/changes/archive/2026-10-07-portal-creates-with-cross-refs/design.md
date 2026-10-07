# Design: portal-creates-with-cross-refs

## D-1. A reference the sender may not read is refused before the write

Both citizen creates declare `crossRefs`, naming `case` and `portalSubject`:
the same collection and the same scope field the `mijnZaken` collection uses.
Portaliq resolves the value through its subject-scoped read and refuses the
whole write with 403 `cross_ref_refused` when it answers nothing. dossiq
writes no check of its own, because by the time the object reaches
OpenRegister the portal subject is gone.

## D-2. The kind and the direction are stamped, not offered

`kind` stays out of the bezwaar's whitelist and arrives from `defaults`. A
bezwaar and a klacht run different statutory clocks, and a form that let the
sender pick would let one arrive dressed as the other. The same holds for a
reply's `direction`: a citizen who could set it could file a message as one
the desk sent.

## D-3. `againstDecisionId` stays out

A bezwaar is usually against a besluit, and `portaalVerzoek` has a field for
one. It is not whitelisted, because a decision carries no portal scope of its
own: there is no field on it that says whose it is, so no guard can be
written. The case it belongs to does carry one, and that is what is guarded.
It re-adds when a decision names the portal subject it belongs to.

## D-4. The inspector submit removes the reference instead of guarding it

The deferred shape had the client send `case` and `template` on a create. The
run already exists and is already scoped by `assignedInspectorRef`, so the
submit is an update on that run: the inspector sends their answers, and the
status comes from the action's `set`. A field that is not accepted needs no
guard.
