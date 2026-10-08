---
kind: code
depends_on: []
---

# Proposal: publishing-refuses-an-unreachable-lifecycle

Competitor map row `dossiq/specs/case-type-publish-validation`:
dimpact-zac, itop, odoo, xxllnc-zaken, znuny, valtimo. "A case model runs
beside a process model, chosen per case type and readable on the case."

The case model and the process model are two declarations that have to
agree. This change is the moment they are checked against each other.

## Why

Publishing a case type is the act that makes its declarations live. Until
then a draft is a sketch nobody runs on. After it, a handler opens a case
and the lifecycle is whatever was published.

`CaseTypePublishService::validate()` already refuses a case type with no
status, with no final status, with an initial status it does not own, with
a looping parent chain, and with a handling switch nothing reads. It never
looked at the moves. So the two declarations could disagree and publish.

Two ways they disagree, both invisible on the page:

**A move nothing can fire.** Both readers of a transition compare its
`fromStatus` to the status the case is in with a strict equality.
`StatusTransitionService::assertTransitionAllowed()` refuses anything
else, and `Transitions\OfferedTransitions::leadsFrom()` never offers it.
Three writers store `*` there as a wildcard meaning "from any status":
`Besluitvorming\WorkflowReferenceResolver`, `Vth\VthWorkflowGraphResolver`
and `Repair\SeedBezwaarWorkflowDefinition`. No reader honours it. Such a
move is authored, stored, exported, drawn in the editor, and never
offered to anybody. It reads as configured and behaves as absent. An
empty `fromStatus`, and one naming a status of another case type, are the
same move with a different spelling.

**A status nothing leads to.** A status declared on the case type that no
sound move targets is in the picker, in the reports and in every export,
and a case can never be in it. The worst version is the final one: a
lifecycle whose closing status cannot be walked to opens cases that can
run for months and never be finished.

## What changes

`CaseTypeReachability` walks the active workflow template's moves from
the status a new case starts in, and answers what a case could never
reach. `validate()` asks it, so publication refuses.

Every finding names the move or the status at fault. A refusal that says
only that the lifecycle is unreachable hands an administrator a graph to
read by hand, which is the reading this walk exists to do for them.

## What does not change

A case type with no workflow template says nothing. Most case types drive
their lifecycle from statuses alone and carry no template, so a finding
there would make the fleet unpublishable on the day this shipped.

An initial status the case type does not own says nothing here either.
`validate()` already refuses that in its own words, and repeating it once
per status would bury the one sentence that says what to do.

The `*` wildcard is reported, not implemented. Making it work is a change
to the transition engine and to what every stored template means, which
belongs in `status-transition-engine` with its own migration. Naming it at
publication stops it reaching a desk today.
