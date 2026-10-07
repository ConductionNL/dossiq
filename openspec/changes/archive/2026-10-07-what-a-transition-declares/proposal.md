---
kind: code
depends_on: []
---

# Proposal: what-a-transition-declares

## The rows this closes

**2.39**, area Case core, rated `partial`: "Closing statuses withheld while
something the case depends on is open."

Source field, verbatim: `dossiq#2314, published as 2.33`.

Corpus batch file, `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, the table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **2.39** | 2.33 | Closing statuses withheld while something the case depends on is open | partial | unread |  |
```

The ledger note, verbatim:

> ConsultationService::getBlockingConsultations blocks on an open advice request. Nothing generalises that to other dependencies, and the closing statuses are still offered rather than withheld.

**3.30**, area Tasks and phases, rated `partial`: "Hand-off that places an
obligation elsewhere and returns the case when met."

Source field, verbatim: `dossiq#2314, published as 3.27`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **3.30** | 3.27 | Hand-off that places an obligation elsewhere and returns the case when met | partial | unread | corpus 3.19 |
```

The ledger note, verbatim:

> ConsultationService blocks a case on an advice request and releases it. Nothing generalises the pattern to any other obligation, so each new one is a new service.

**11.41**, area Configuration, rated `partial`: "Explanation written by an
administrator on a status and on a transition."

Source field, verbatim: `dossiq#2314, published as 11.33`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.41** | 11.33 | Explanation written by an administrator on a status and on a transition | partial | unread |  |
```

The ledger note, verbatim:

> statusType.description exists and nothing renders it. There is no text on a transition at all, so the moment a handler is choosing is the moment with no guidance.

**13.26**, area Access and privacy, rated `no`: "Transition the person who
prepared the case may not make themselves."

Source field, verbatim: `dossiq#2314, published as 13.19`.

The table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **13.26** | 13.19 | Transition the person who prepared the case may not make themselves | no | unread |  |
```

The ledger note, verbatim:

> Mandaat is modelled and MandaatVerifier reads it, and ConflictOfInterestService asks whether a handler is related to the applicant. No transition carries a negative flag, so nothing stops the person who wrote a decision from approving it.

## What the competitor evidence is

None for any of the four. All four are among the 98 rows promoted under
decision D1, whose batch file states: "Every competitor column is `unread`,
and none of them is `no`. ... `no` is a reading of a product somebody
opened, and filling these cells with it would fabricate thirty readings per
row."

Row 3.30 cross-references corpus row 3.19. A cross-reference names a
neighbouring question, not a reading of a product on this row.

## Why

A transition is the moment the case moves and the moment a handler needs to
know four things. dossiq tells them none of them.

**Whether it may happen at all.** `ConsultationService::getBlockingConsultations`
knows that an open advice request blocks a case, and it is the only thing
that knows anything of the kind. The closing statuses are still offered, so
the handler picks one and gets a refusal. Every other dependency, an
unanswered aanvullingsverzoek, an unpaid fee, an inspection not yet done, is
a service somebody would have to write.

**What it obliges someone else to do.** Consulting another department is a
hand-off with three parts: the obligation goes out, the case waits, and it
comes back when the obligation is met. dossiq has that pattern once, wired
to advice requests. A second obligation means a second service with the same
three parts written again, which is how a codebase ends up with four of them
that behave differently.

**What it means.** `statusType.description` exists and nothing renders it. A
transition carries no text at all. So the moment the handler is choosing,
which is the only moment guidance is worth anything, is the moment with
none.

**Who may not make it.** Mandaat is modelled and `MandaatVerifier` reads it.
`ConflictOfInterestService` asks whether the handler is related to the
applicant. Neither answers the ordinary four-eyes question: the person who
wrote this decision may not be the person who approves it. No transition
carries that flag, so nothing stops it.

## What changes

- A transition may declare what must be settled before it is available. An
  unsettled dependency withholds the transition rather than refusing it
  afterwards, and the reason is readable where the transition would have
  been.
- Closing statuses are withheld the same way, so a case with an open
  obligation cannot be closed by picking a different word for closed.
- An obligation is one declared thing: it is placed on a person or a unit as
  an engine task, it names what settles it, it blocks what it blocks, and
  meeting it releases the case. The existing advice request becomes the
  first obligation of that kind rather than the only mechanism.
- An administrator may write an explanation on a status and on a transition,
  and both are rendered where a handler reads them: the status on the case,
  the transition in the list the handler is choosing from.
- A transition may declare that whoever performed a named earlier act may
  not perform it. The refusal names the act and the person, and a case type
  may declare who may be asked instead.

## Ownership

dossiq builds all four. Under dossiq ADR-005 the transition engine is a
documented ADR-022 exception and stays dossiq's, because the edge list lives
in `workflowTemplate` objects per case type and OpenRegister has no
lifecycle whose edges are read from a related definition object. What a
transition declares is therefore dossiq's to specify.

Consumed:
- openregister engine tasks (shipped) for the obligation placed on somebody
  else, so dossiq does not build a second work inbox;
- openregister `flow-business-timers` (shipped) for an obligation that has a
  term of its own;
- openregister `lifecycle-declarative-conditions`, to be specified in
  openregister, for evaluating a declared precondition. Until it lands
  dossiq evaluates its own declarations against the same vocabulary;
- openregister object RBAC and the audit trail (shipped) for who performed
  the earlier act the four-eyes rule reads.

## ADRs

- Company ADR-022: the task, the timer and the history are the platform's.
- Company ADR-023: who may make a transition, and who may not, is an action
  mapping an administrator can read, not an `isAdmin()` in a controller.
- Company ADR-031: the precondition, the obligation and the explanation are
  declared on the definition, not written per transition.
- Company ADR-055: a transition performed on behalf of another actor
  validates the actor rather than trusting the request.
- dossiq ADR-005: the transition engine is the documented exception this
  change writes into.

## Size

M. Four declarations on a transition and one generalisation of a pattern
that already exists once.

## The existing spec this extends

`status-transition-engine` for the preconditions, the explanations and the
four-eyes rule, and `consultation-management`, whose advice request becomes
the first declared obligation.

## Out of scope

- The mandaat matrix itself, which is `mandaat-matrix` and answers a
  different question: whether this person may decide at all.
- The relationship check in `ConflictOfInterestService`, which stays as it
  is and is read beside the new rule rather than replaced by it.
- The words a citizen reads, which are `citizen-status-labels` REQ-CT-25.
  The explanations here are for the handler and the administrator.
- Bulk transitions, which keep the per-case isolation
  `case-bulk-status-transition` already specifies; a withheld transition is
  simply not available for that case.
