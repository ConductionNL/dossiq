# Design: field-rules-declared

## D-1. The first list is short and named

Five fields, two groups (`dossiq-coordinators`, `dossiq-quality`), in the
proposal. Anything more is a case-type decision and waits for
`field-rules-by-state`.

## D-2. No dossiq filtering

The form and the data panel render what the platform returns. A vitest
asserts no `src/` code branches on a role for these fields.

## D-3. Two enforcement surfaces, opposite polarity

One declaration, two published shapes, and they read their group lists in
opposite directions. A lifecycle field rule names the groups the rule is taken
FROM; a property authorization block names the groups that KEEP the field. Put
one where the other is read and OpenRegister withholds the field from exactly
the people who were meant to keep it, and nothing anywhere reports it.

So a rule carries both lists, under names that say which is which, and neither
is derived from the other. Deriving would mean inventing the set of every group
on the server. A group written into both loses the field: that resolution is
visible to the person it affects, and the other one hands the field to somebody
the author wrote down as restricted.

## D-4. Both surfaces, not one

The lifecycle half alone would leave a case in a state the type does not declare
with no rule at all: a type mid-edit, a status row deleted, a case migrated in.
The property half alone would lose the refusal message the rule's author wrote.
Both are published, from one declaration, at one moment.

## D-5. The states block has one writer

The role rules are folded into `CaseStateFieldRuleProjector::statesOf()` rather
than written by their own projector. That class replaces the whole entry for a
state it owns, so a second writer on the same entry would have its work deleted
by the next publish of either half, and the deletion would look exactly like a
rule nobody had declared yet.

## D-6. A ledger, because merging can only add

Every case type writes to the same `case` schema, so the property write merges.
Merging alone can never take a rule off again, so
`configuration.x-dossiq-field-roles` records what each case type projects. It
lives in the configuration rather than beside the grants: a marker inside a
grant is a key OpenRegister does not know, and an unknown key is dropped in
silence.

