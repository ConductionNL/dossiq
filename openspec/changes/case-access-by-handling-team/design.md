# Design: case-access-by-handling-team

## D-1. The rule is declared and OpenRegister evaluates it

dossiq keeps no evaluator of its own (REQ-ACC-01). The team rule is written as
ordinary OpenRegister conditional entries on the case schema:

```json
{"read": [
  {"group": "authenticated", "match": {"assignee": "$userId"}},
  "dossiq-background-service",
  {"group": "vergunningen", "match": {"caseType": {"$in": ["<case type uuid>", "..."]}}}
]}
```

`$in` and `$userId` are compiled into the list query by `MagicRbacHandler` and
evaluated by `ConditionMatcher` on a single object, so a list and a get agree
by construction. The projector computes which case type uuids belong to which
group. It does not decide whether a person may read; OpenRegister does.

## D-2. Projected from the case types, not stored per case

A rule per case would have to be rewritten on every case when a case type
gains a team. A rule per group on the schema changes once, when the case type
changes. Every case type row counts, every version included, because a running
case keeps pointing at the version it was opened on.

## D-3. Recomputed whole, from the shipped base

The projection is the shipped case block from the fragment-merged register JSON
plus the team rules computed from every case type. There is no ledger: the
whole block is computed again each time, so a team removed from a case type
loses its rule on the next run, and a register import that wipes the projected
rules is repaired by the `reapply()` that `InitializeSettings` runs after it.
Between the import and the reapply the schema is closed rather than open,
which is the direction a security rule should fail in.

## D-4. Register level for the rest

OpenRegister's rbac-scopes spec cascades a register block onto every schema
that declares none. One block on the register closes the 84 schemas that had
none, and a new schema is closed on the day it ships rather than open until
somebody remembers it.

The background service group is named beside `dossiq-medewerkers` in every
block, because the jobs that write as the background account (fragment 90)
would otherwise be refused the moment the register closes.

## D-5. The staff group is seeded once

`SeedStaffGroup` runs once per instance (app config `staff_group_seeded`).
Running it again would put back somebody an administrator removed on purpose.
It adds the members of every handling team group and every assignee of a case.
The case type and case reads run with `_rbac: false`, because a repair step has
no user and must see every row to seed from it.
