---
kind: code
depends_on: []
---

# Proposal: one-personal-queue

Round 4 discovery, cluster 64 "One personal queue fed by every mechanism"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Six candidates, no `must`,
seven passers, all seven driven, proving system GitLab. Owner dossiq, size
M, depends on the task as a first-class record. The register's reason for
the cluster carrying nothing: "no change opened".

## Why

The lane's clause on C-tasks-and-phases-26 is the whole cluster: "it is
the thing a caseworker opens first and it is not a work list: it is the
set of things waiting on you across every dossier".

dossiq has a My Work page and it shows tasks. A handler also has cases
assigned to them, coordinator seats, consultations they were asked for,
approvals waiting on their signature, mentions, and work they took over
from an absent colleague. Each of those lives on its own page, so the
question "what is waiting on me" is answered by visiting six places and
remembering the seventh.

## What is actually there

Read against `development` at `172d364f`.

- `src/manifest.json` carries a `MyWork` page and a `MyWorkHome` page with
  a `widget-my-work` slot. Its note records what it is: a tile reading
  OpenRegister's task engine through `useEngineTaskStore`, after
  `remove-casetask` found it pointing at a schema nothing writes. So My
  Work shows engine tasks, and the note says so plainly: "#Dashboard shows
  my tasks only".
- `openspec/specs/my-work/spec.md`, `my-work-landing/spec.md`,
  `add-work-queue/spec.md` and `werkvoorraad-intelligent-queue/spec.md`
  exist. The surface is specified; what feeds it is one mechanism.
- `lib/BackgroundJob/DeadlineNotificationDispatchJob.php` sends term
  notifications. There is no daily digest of a person's own open work.
- Nothing plans a personal item that is not attached to a case, nothing
  gives a person their own stage on a shared case, and there is no
  end-of-day screen.

## The candidates

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-tasks-and-phases-26 | should | partial | one personal queue holds everything waiting on you, fed from every mechanism that can ask |
| C-deadlines-2 | should | partial | a daily digest of your open tasks arrives by mail, beside the assignment notice |
| C-tasks-and-phases-27 | should | no | one screen to close out the day: everything you touched, with a box to record time and an update |
| C-tasks-and-phases-5 | could | no | a planned item goes on the agenda without being attached to a case, from a template |
| C-tasks-and-phases-22 | could | no | each person keeps their own private stage on the same record, beside the shared one |
| C-tasks-and-phases-37 | could | no | your own to-dos are planned into slots on your own day |

Evidence verbatim from the lane
(`_round4/discovery/candidates.json`):

- C-tasks-and-phases-26, `tasks-and-phases.tsv:12`: "gitlab: To-Do List,
  task-done in the header, Free (docs.gitlab.com/user/todos/)". The lane's
  note: "Both refuse to make the caseworker check two lists".
- C-deadlines-2, `deadlines.tsv:25`: "valtimo: User panel
  (UserPanel.md)". Its clause: "the daily digest is the half we do not
  have and it is what stops a task list from being a place people forget
  to visit". The lane's note: "The assignment notice half is 8.6; the
  digest is the new claim".
- C-tasks-and-phases-27, `tasks-and-phases.tsv:35`: "request-tracker:
  Tools, My Day (share/html/Tools/MyDay.html)".
- C-tasks-and-phases-5, `tasks-and-phases.tsv:32`: "glpi: Planning,
  external events (front/planningexternalevent.php, dropdown
  PlanningExternalEventTemplate)".
- C-tasks-and-phases-22, `tasks-and-phases.tsv:33`: "odoo:
  project_task_stage_personal.py". Its clause: "a caseworker's personal
  triage lane on a shared zaak".
- C-tasks-and-phases-37, `tasks-and-phases.tsv:39`: "huly: left rail,
  Planner, plugins/time ToDo + WorkSlot".

**D6 was answered relevance-led.** This cluster has no `must`, so every
member enters on relevance. Five do: a caseworker's own queue, the digest
that makes them open it, the end of day, a planned item with no case, and
a personal lane. One does not, and is recorded below. **D17 was answered
for a broad market**; none of the twenty re-rated candidates is in this
cluster.

## What changes

- One personal queue holds everything waiting on a person: assigned cases,
  coordinator seats, open tasks, consultations asked of them, approvals
  awaiting their signature, mentions, and work they cover for an absent
  colleague.
- Anything that can ask a person for something puts it in that queue
  through one declared contract, so a new mechanism reaches the queue by
  declaring itself rather than by a new page.
- An item leaves the queue when the thing it points at is done, and never
  by being dismissed into nothing.
- A daily digest of a person's open work arrives by mail, at a time they
  choose, and says nothing when there is nothing.
- One screen closes out the day: everything the person touched, with an
  update per item and a place to record time.
- A person plans an item on their own agenda without attaching it to a
  case, from a template.
- A person keeps their own stage on a shared case, private to them, beside
  the case's own status.

## Ownership

dossiq owns the queue, the feeding contract, the digest, the end-of-day
screen, the personal item and the personal stage. They are dossiq
surfaces over dossiq and engine data.

What dossiq consumes:

| half | app | artefact |
|---|---|---|
| the tasks the queue is mostly made of | openregister | the flow task engine and its inbox filters, shipped, read through `useEngineTaskStore`; dossiq `task-search-fields` is the filter half |
| whether a colleague is absent, so their work reaches a stand-in | humaniq | `leave-management`, a spec, consumed by dossiq `substituted-work-reaches-my-work`, open |
| the time box on the end-of-day screen | humaniq | `hours-leaf`, a spec, placed by dossiq `hours-onto-humaniq-leaf`, open. dossiq does not store hours |
| where the digest is routed and who may switch it off | openregister | `notification-routing-per-group-and-scope`, named by the register in this round |
| the screen a person sets their notification preferences on | nextcloud-vue | `notification-preferences-ui`, named by the register in this round |
| the personal agenda item | nextcloud | the Calendar app, through openregister `calendar-provider` and `integration-calendar`, and dossiq `case-appointment-via-calendar-leaf` |
| the tiles a person arranges on their own dashboard | nextcloud-vue | `dashboard-layout-per-user`, the register's artefact for row 10.10 |

Every half above has an artefact. This change opens no request for a new
change in another repo.

## Recorded, not built

| candidate | why not built |
|---|---|
| C-tasks-and-phases-37 | planning your own to-dos into hourly slots. The lane's own clause argues against it for this market: "a caseworker with fourteen open zaken plans by term, not by hour". A gemeente handler's day is driven by statutory dates, and the personal agenda item of C-tasks-and-phases-5 already covers the things that do need a moment. Recorded under D6 and D17 rather than disqualified, and reopened if a customer asks |

## ADRs

- Company ADR-011: search OpenRegister before implementing a utility. The
  queue reads the engine, the calendar and humaniq's hours; it stores none
  of them.
- Company ADR-031: the canonical notification dialect. The digest and the
  queue's arrivals use it and dispatch nothing imperatively.
- Company ADR-102: config absence fails closed with a status. A queue
  source that cannot be reached says so on the queue rather than quietly
  contributing nothing.

## Capabilities

- Modified: `my-work`: the queue holds everything waiting on a person,
  with a digest, an end-of-day screen and a personal stage.
- Modified: `add-work-queue`: anything that asks a person for something
  declares itself as a queue source.

## Impact

`src/manifest.json` (`MyWork`, `MyWorkHome` and the `widget-my-work`
slot), `src/views/widgets/MyWorkWidget.vue`,
`lib/BackgroundJob/` (the digest job), the queue source contract, Dutch
and English strings.

## Where this change disagrees with the register

- **The cluster depends on cluster 53 and can start before it.** The
  register's `depends` reads "the task as a first-class record". The queue
  reads tasks the engine already answers, so the queue, the digest and the
  end-of-day screen can be built now; only the claim affordance for an
  unclaimed task waits on `task-as-a-first-class-record`.

## Out of scope

- The dashboard layout a person arranges. nextcloud-vue, row 10.10.
- Booking a resource or a room. Cluster 60.
- Recording hours. humaniq, and dossiq `hours-onto-humaniq-leaf`.
- Who is absent and who covers them. dossiq
  `substituted-work-reaches-my-work`, open.
