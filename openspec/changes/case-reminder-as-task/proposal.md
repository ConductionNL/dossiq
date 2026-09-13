---
kind: config
depends_on: [remove-casetask]
---

# Proposal: case-reminder-as-task

Competitor gap register, row 8.4 "Reminders on a case with a responsible
user" (`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

You cannot say "remind Anna on the 3rd to call the applicant". A flow can
schedule a reminder (`lib/Flow/DossiqScheduleReminderNode.php`) and the
dashboard has a `task-reminders` widget, but a reminder you set by hand,
for a named colleague, on a date, does not exist. It is an engine task with
an assignee and a due date, and dossiq only lacks the action that creates
one.

The best competitor in the register: OpenCase, `lib/Db/Reminder.php`
(`_round2/compare/M1-functionality.md`).

## What changes

- Header action Remind on `#CaseDetail`: a small form with who, when and
  what, creating an engine task on the case with that assignee, due date
  and title, kind `reminder`.
- The task shows on My work, on Tasks and on the case's Work tab like any
  task, and its `taskAssigned` notification tells the colleague.
- No new schema, no dossiq job: the engine's due-window filter and the
  notification on assignment do the reminding.

## Ownership

dossiq builds the action and the form. It consumes OpenRegister's engine
task (`/api/flow-tasks`, the surface `remove-casetask` moved the Tasks page
onto) and its assignment notification, both shipped.

## ADRs

- Company ADR-022: a reminder is the platform's task, not a dossiq record.
- Company ADR-049: the placement is declared; the handler is the
  `src/customComponents.js` pattern.

## Capabilities

- Modified: `task-management`: a reminder is a task you set for a
  colleague from the case.

## Impact

`src/manifest.json` `#CaseDetail` header action; one handler and one small
form in `src/customComponents.js` and `src/dialogs/`;
`tests/vitest/caseActionsMenu.spec.js`; one e2e spec. No PHP.
