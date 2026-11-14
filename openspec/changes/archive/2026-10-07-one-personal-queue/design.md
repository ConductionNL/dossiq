# Design: one-personal-queue

## D-1. The queue is a contract, not a union of six queries

GitLab's to-do list is fed by everything that can ask
(`_round4/discovery/candidates.json`, C-tasks-and-phases-26,
`tasks-and-phases.tsv:12`), and the lane's note is the design goal: "Both
refuse to make the caseworker check two lists".

A My Work page that hard-codes six queries grows a seventh the day
somebody adds a mechanism, and the seventh is forgotten. So a queue source
is declared: what it is called, how it is read for a given person, what an
item points at, and what closes it. Adding a mechanism means declaring a
source.

A structural test asserts that every mechanism that can ask a person for
something declares a source, or carries a reason-bearing allowlist entry.
Otherwise the failure is silent, which is how the page came to show tasks
only.

## D-2. An item closes when its subject closes, never by being dismissed

A to-do a person can dismiss is a to-do they will dismiss on a bad
morning, and the case behind it still needs doing. So an item leaves the
queue when the thing it points at is done, taken over, or withdrawn.

What a person may do is order the queue, group it and hide a group for
today. What they may not do is make an item disappear while the work
stands.

## D-3. The digest tells somebody something, or it does not arrive

Valtimo's clause names the purpose: "the daily digest is the half we do
not have and it is what stops a task list from being a place people forget
to visit" (`deadlines.tsv:25`).

A digest that arrives every morning saying nothing trains people to delete
it, so a person with an empty queue gets no mail. The time is theirs to
choose and so is switching it off, through the platform's notification
preferences rather than a dossiq setting, per ADR-031.

The digest names what is waiting and what is overdue and links into the
queue. It does not repeat the assignment notice, which is a different
message at a different moment, and the lane says so: "The assignment
notice half is 8.6; the digest is the new claim".

## D-4. The end of day is a review of what was touched, and time is humaniq's

Request Tracker's My Day lists what you touched with a box to record time
(`tasks-and-phases.tsv:35`).

dossiq builds the list and the update box. It does not build the time box:
hours belong to humaniq, dossiq `hours-onto-humaniq-leaf` places the leaf,
and a second hours store would be the duplication ADR-011 exists to stop.
So the screen places humaniq's leaf per item and shows nothing there when
humaniq is absent, rather than falling back to a dossiq field.

## D-5. A personal agenda item is a calendar event, not a caseless case

GLPI's external events are planned items with no ticket
(`tasks-and-phases.tsv:32`). The wrong way to build this in dossiq is a
case with no case type, which would enter every count and every report.

So it is a calendar event on the person's own calendar, created from a
template, reaching the queue as a source like any other. Nextcloud's
Calendar holds it and dossiq holds nothing.

## D-6. A personal stage is private, and it never speaks for the case

Odoo gives each person their own stage on the same record
(`tasks-and-phases.tsv:33`), and the clause is "a caseworker's personal
triage lane on a shared zaak".

So a personal stage is visible only to its owner, never appears on the
case for anybody else, never enters a report, and never changes the case's
own status. It is a private label on somebody else's shared object, which
is exactly the shape the per-viewer half of the object store is for.

The risk to avoid is a second status two people read differently. So the
case page shows the case's status prominently and the personal stage as
what it is, a private note to self.
