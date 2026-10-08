# Design: intake-triage-and-refusal

## D-1. What a case must answer is a case type declaration, not a schema required list

`case.required` is `["title", "caseType"]` and the channel,
communication channel and confidentiality fields already exist beside
them. Adding three names to the schema's required list would make every
case type demand them, including the ones a gemeente uses for internal
work where a communication channel is meaningless.

So the case type declares its own list. Dimpact makes both required at
creation (`_round4/discovery/candidates.json`, C-intake-8,
`intake.tsv:38`), and the clause says why it matters later: "both are what
the Woo and the Archiefwet later ask about".

The default for a new case type is that the channel and the
confidentiality are required, because the common case is the one the law
asks about.

## D-2. Refusing creation is better than accepting and marking incomplete

`lifecycle-acts-on-the-case` says the opposite for the phone intake, and
both are right, because they are different acts.

A field a handler cannot know yet, an address on a phone call, is
recorded as knowingly incomplete. A field the case type says must be
answered before the case exists, the classification that compiles into the
access rule, cannot be deferred: a case nobody can reach is worse than a
case that was not created.

So the declaration carries which of the two a field is: required before
the case exists, or required before the case is complete. OpenCase's
clause is the test for the first kind: "an unclassified case is
unreachable rather than merely untidy".

## D-3. Narrowing is enforced on the write, not only drawn in the picker

A picker that lists three groups and an API that accepts thirty is a
narrowing that only exists on screen. The Dimpact claim is about who may
be chosen (`intake.tsv:23`), and the enforcement has to be where the case
is written.

So the case type declares the allowed groups and people, the picker reads
that declaration, and the write refuses a value outside it with the rule
named per ADR-050. A refused assignee says which declaration refused it,
so an administrator can fix the case type rather than guess.

## D-4. Refusal is an outcome of routing, and the refused case stays findable

`lib/Service/Routing/Strategy/` has five strategies and every one of them
returns a destination. Refusal is the missing sixth outcome.

xxllnc configures where a refused case goes (`intake.tsv:43`), and the
clause is the whole requirement: "a refused case that goes nowhere is a
lost case". Awb 2:3 agrees, and `inbound-mail-filters` REQ-IMF-03 already
carries the mail half of the doorzendplicht.

So refusal names a department and a role, records the reason and who
refused, and the case stays in the register and in search. A refusal that
deletes or hides is not a refusal, it is a loss.

## D-5. Sleep is a state with a date, and it is not a hold

Plane's `snoozed_till` (`intake.tsv:17`) is on the intake item, before it
is a case. dossiq's equivalent sits on the triage item.

It is not the hold `lifecycle-acts-on-the-case` D-9 describes. A hold is
on a case that exists and whose clock runs. A sleep is on something not
yet accepted, where no statutory clock has started. Keeping them separate
stops a sleep from ever being mistaken for a suspension.

An item that wakes goes back to the queue it came from, at the top of
nobody's personal list, because the person who slept it may have left.

## D-6. The fan-out declares destinations and keeps the relation

GLPI's form destinations are a declared list on the form, with about
thirty fields settable per destination
(`intake.tsv:6`). The lane separates it from ledger row 2.10 precisely:
"2.10 is a hierarchy after the fact, this is the fan-out at intake".

So the form declares its destinations, each destination names a case type
and a department, and the created cases carry a relation to the
submission and to each other. Each department sees its own case and not
the others' content, which is what makes the fan-out usable across a
gemeente rather than a leak.

The relation type wants openregister's `relation-types-with-inverses`,
which does not exist yet. Until it does the fan-out uses the existing
related-cases link, which carries no inverse name, and the requirement
says so rather than pretending the relation is richer than it is.
