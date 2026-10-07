---
kind: code
depends_on: [pause-reason-with-chasing]
---

# Proposal: aanvullingsverzoek-as-a-record

## The row this closes

**1.17**, area Intake, rated `partial`: "Request to the applicant to
complete their submission, with a typed reason."

Source field, verbatim: `dossiq#2314, published as 1.14`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **1.17** | 1.14 | Request to the applicant to complete their submission, with a typed reason | partial | unread |  |
```

The ledger note, dossiq's own evidence, verbatim:

> DeadlinePauseService::resumeAfterPauze credits the unused pause back when the aanvulling arrives, which is the clock half. No object represents the request, so nobody can list the cases waiting on an applicant.

## What the competitor evidence is

There is none, and the corpus says so in as many words. This row is one of
the 98 promoted under decision D1, where the batch file states: "Every
competitor column is `unread`, and none of them is `no`. ... `no` is a
reading of a product somebody opened, and filling these cells with it
would fabricate thirty readings per row."

So nothing below claims a competitor does this. The rating is a reading of
dossiq's own tree and nothing more.

## Why

A handler asks an applicant for a missing bank statement. dossiq suspends
the clock under Awb 4:5, and that half is real:
`DeadlinePauseService::resumeAfterPauze` credits the unused pause back when
the aanvulling arrives. What it does not do is write down that an ask
happened.

The consequence is a question nobody can answer. "Which of our cases are
waiting on an applicant, and since when, and for what" needs an object to
count. A suspended timer is not that object: it says the clock stopped, not
what was asked, not what is still missing, and not whether the answer that
came back was complete.

`pause-reason-with-chasing` puts a type on the pause and chases the
applicant on a schedule. It stops one step short of the row: the reason is
a property of the pause, and the row asks for the request itself as a
record on the case.

## What changes

- An `aanvullingsverzoek` schema in `register.d/60-termijnbewaking.json`:
  the case, the party asked, the `pauseReason` that types it, what is
  missing as a list of named items, who asked and when, the hersteltermijn
  date, and a state of open, answered, expired or withdrawn.
- Asking is one action on the case. It writes the request, suspends the
  term through the path that already does so, and arms the chases
  `pause-reason-with-chasing` declares.
- Recording the answer closes the request, names which items arrived and
  which did not, and resumes the clock through the existing credit.
- A request whose hersteltermijn passes with no answer becomes `expired`
  and stays readable. Awb 4:5 lets a request be refused for incompleteness
  and the file has to show what was asked before it can.
- The work list can filter and count on open requests, so "waiting on an
  applicant" is a query rather than a reading of timers.

## Ownership

dossiq builds all of it. What an aanvullingsverzoek is under Awb 4:5 is
case law, not platform behaviour, so the record is dossiq's.

Consumed: openregister `flow-business-timers` (shipped) for the suspend and
resume of the term; dossiq `pause-reason-with-chasing` (open) for the typed
reason and the chase schedule, which this change does not restate.

## ADRs

- Company ADR-022: dossiq does not build a second timer. The suspension
  stays the engine's and the record points at it.
- Company ADR-031: the states and the transitions of the request are
  declared on the schema rather than written as a service state machine.
- Company ADR-038: the requirement ids below carry the canonical form.

## Size

S. One schema, one action, one resolution path and a filter.

## The existing spec this extends

`termijn-pause-extension`, which carries REQ-TERM-002 and REQ-TERM-003 on
the canonical side and REQ-TERM-011 and REQ-TERM-012 from
`pause-reason-with-chasing`. This change adds to it and rewrites none of
them.

## Out of scope

- The chase schedule, the chase text and the budget. Those are
  `pause-reason-with-chasing`.
- The statutory arithmetic of the suspension itself, which
  `termijnbewaking-op-engine-timers` REQ-TOT-002 owns.
- Refusing the application for incompleteness. The request records what was
  asked; the refusal is a decision and belongs to the beschikking path.
