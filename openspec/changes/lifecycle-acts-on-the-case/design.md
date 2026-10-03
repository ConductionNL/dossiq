# Design: lifecycle-acts-on-the-case

## D-1. One menu, drawn from the engine, with refusals drawn before the click

xxllnc puts every act behind one ZAAKACTIES menu
(`_round4/discovery/candidates.json`, C-case-core-31,
`case-core.tsv:36`). dossiq already has the harder half: `CaseActionProvider`
publishes the acts and marks a refused move `blocked: true` with the
guard's own sentence, and the `case-stages` widget disables it with that
sentence.

So the menu is not a new list. It is the same provider answer rendered in
one place, and the header actions the case page carries today move into
it. An act a handler may not perform is shown and disabled with the
reason, never hidden: a hidden act teaches nobody why.

The one act that cannot be gated from a field stays as it is. Suspension
is derived from the `activity` journal rather than a flag, so Resume is
offered and `CaseLifecycleActionDialog` reads `/lifecycle` before it
posts. That is the right shape anyway: the server decides, the menu asks.

## D-2. Ending a case is four acts, and the difference is archival

Dimpact and OpenCase both refuse to collapse ending a case into one verb
(`case-core.tsv:11`). The reason is not vocabulary.

- Finish: the case reached its result. The result type decides retention.
- Abort: an intrekking. There is a result, and it is not a besluit.
- Archive: the case is done being read and moves to its retention rule.
- Reopen: the case comes back, and the fact that it was ended stays in
  the record.

Each carries its own permission and its own recorded reason, and each
writes a different thing into the archival handover
`archief-edepot-handover` already specifies. Collapsing them is how a
withdrawn aanvraag ends up archived as a granted one.

## D-3. Closing early is a close, not a shortcut past the guards

xxllnc closes from the case page mid-process
(`case-core.tsv:20`). The Dutch case is an intrekking or a
niet-ontvankelijkverklaring arriving in phase two of five.

So an early close is the normal close act with the skipped phases
recorded, and the guards that protect the result still run. What is
skipped is the phases, never the checks. A case closed early that cannot
name its result is refused.

OTOBO's Quick Close (`case-core.tsv:21`) is the same act with the outcome
preset, and the lane's own clause is the warning: "and for a Dutch besluit
the form is the point". So the preset fills the outcome and the reason is
still recorded; it never skips the record.

## D-4. A flag's reader may carry a different name, so the test traces the calculation

`statusType.hiddenInLists` is honoured, through the calculated
`case.statusHiddenInLists`, on the Cases All lens. A search for the
declared name finds a form field, a default and four seeds, and reads as
dark. It is not dark; it is mirrored.

Two things follow. The narrow one: the mirror is filtered in one place,
so the same hidden cases still fill the Overdue chip, the Queue page, My
Work, the open counts and the dashboard tiles. Hidden has to mean hidden
everywhere work is counted, or an administrator gets a list and a count
that disagree.

The wider one is the test. A structural test that matches flag names
would have called `hiddenInLists` a defect and been wrong. So it follows
the declared calculation: a flag has a reader when something reads it, or
reads a property calculated from it. A flag with neither is in a
reason-bearing allowlist or it fails, and an allowlisted flag that gains a
reader fails too, so the number can only go down deliberately.

Hidden never means out of search or off the case's own page. A case
somebody cannot find is a different and worse bug.

## D-5. Process-owned status is a declaration, not a code path

Valtimo's position is that the process owns the status and no handler sets
it by hand (`case-core.tsv:34`). It is the opposite of dossiq's, and the
lane says so: "it is a deliberate design position and the opposite of
ours, which is worth recording as a row rather than a preference".

So it is a case type declaration rather than a product decision. A case
type that declares it accepts no hand-set status; every move goes through
a transition. A case type that does not keeps today's behaviour. A gemeente
running both kinds is the normal case.

Per ADR-102, a case type declaring process ownership whose process cannot
be resolved refuses the hand-set rather than falling through to it.

## D-6. Auto-close is administered, recorded, and announced first

Plane counts from the last touch and closes nightly
(`case-core.tsv:7`). The Dutch clause is a bezwaar waiting on the indiener
that should not sit open forever.

The period is declared per case type and defaults to off, because a case
that closes itself in a gemeente without anybody deciding is a besluit
nobody took. Before it closes, the applicant is told it is about to, using
the declared moments `ontvangstbevestiging` builds. The close records that
the product did it and why.

## D-7. Incompleteness is recorded, never blocked and never hidden

xxllnc puts a sentence at the start of the case and one at the end of a
phase (`cross-area.tsv:8`), and the lane names the principle: "One refusal
to lie about the data".

A phone intake cannot always be complete, and refusing it loses the case.
So a required field may be left empty knowingly. The case then says it is
incomplete, names which fields, and carries that state into the working
list. What it must not do is report itself complete, and what it must not
do is refuse the intake.

The incompleteness blocks the acts that need the data, through the same
guard mechanism, and names the missing field when it refuses.

## D-8. A draft case is private, has no clock, and is promoted once

Plane keeps drafts in their own space with `Issue.is_draft`
(`intake.tsv:11`). The Dutch clause is sharper: "a concept-zaak whose Awb
clock has not started is a real object in a gemeente, and the alternative
is a half-filled case that is already overdue".

So a draft binds no term, appears in no working list or count, is visible
only to its author, and is promoted in one act that binds the term and
makes it a case. The draft's creation moment is kept, because when the
aanvraag arrived is a fact somebody will ask about.

A draft is the same shape as the case template in
`starter-content-and-templates`, and both use the same exclusion from
lists and counts, so there is one rule rather than two.

## D-9. Hold and park are a reason and a wake date

Znuny's Pending is a state with a time (`case-core.tsv:48`). Held means
the case is deliberately not being worked, with a reason, and it comes
back on its date.

Held does not stop the statutory clock. Only a suspension under Awb 4:5
does that, and it has its own act. A hold that quietly stopped a term
would be the worst bug in this change.
