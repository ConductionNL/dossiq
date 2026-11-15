# Design: first-run-and-the-tour

## D-1. Readiness is reported, not gated

`first-time-setup` REQ-SETUP-PRO-001 already learned this the hard way: a
step that reported success with every counter at zero made the affordance
one-shot and silently useless, and reporting it honestly left a step whose
every click was a 422.

So the readiness list adds nothing that can block. `register-check` stays
the only required step, because without a register nothing works at all.
Everything else is reported: five named items, each done or not done, each
with the screen that satisfies it.

An instance an administrator deliberately leaves half configured is a
legitimate instance. An instance nobody can see the state of is not.

## D-2. A readiness item is a live read, never a stored flag

Zammad's first steps page re-reads the system
(`_round4/discovery/candidates.json`, C-configuration-43,
`configuration.tsv:33`). A stored "done" flag drifts the moment somebody
deletes the thing it recorded.

So each item is a question asked of the tree at read time: is there an
organisation, is a mail account selected, is one case type published, does
one role have a holder, is a working calendar set. An item whose read
throws reads as not done and names the failure, per ADR-102.

## D-3. The declared list and the reported list agree, in both directions

Carried verbatim from REQ-SETUP-PRO-001, because it is the same failure
class one layer up. An item the screen renders that the status never
reports cannot be answered. An item the status reports that no screen
renders cannot be satisfied. A test asserts both directions.

## D-4. The tour is per surface and per person

Plane keys the tour on the profile: `Profile.is_tour_completed`
(`configuration.tsv:67`). dossiq's `walkthrough_completed_version` is one
key for the whole app.

One key means the handler who joins in month nine, after the first
administrator finished the tour, is taught nothing. So completion is per
person and per surface, and a new surface offers its own step to people
who already finished the rest.

## D-5. A tour step that lost its surface says so

The same shape as D-3 and as the retired `seed` step. A step naming a
page that no longer exists is skipped silently today, which is how a tour
quietly stops teaching half the product. The step is reported as broken,
in the same place the readiness items are reported, so somebody can see it.
