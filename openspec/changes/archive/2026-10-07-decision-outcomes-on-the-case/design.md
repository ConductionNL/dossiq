# Design: decision-outcomes-on-the-case

## D-1. dossiq reads the outcome and never recomputes it

decidiq walks the approval. A dossiq-side reimplementation of "has enough
of them signed" would be a second answer to a question with one correct
one, and the two would disagree the first time a threshold changed.

So dossiq reads decidiq's outcome and gates on it. The gate is a guard in
`CaseActionProvider`, which is where every other refusal on a case already
lives, so the refusal is drawn before the click with its own sentence.

## D-2. An unreadable outcome blocks, it does not pass

The failure this design most needs to avoid is a case proceeding because
decidiq was briefly unreachable. That is the fail-open shape
`refusals-carry-a-status` was opened for, and ADR-102 settles it: absence
is a refusal with a status, never a silent allow.

So a case waiting on an approval dossiq cannot read stays blocked and says
that decidiq is unavailable. A handler then knows to wait rather than
believing the approval came through.

## D-3. Inadmissible is a close, with a result, not a dead phase

Dimpact ends intake with an admissibility judgement
(`_round4/discovery/candidates.json`, C-decisions-13). The failure mode it
avoids is a case sitting in an intake phase that nobody will ever work,
which is how a gemeente accumulates an invisible backlog.

So an inadmissible verdict is the ordinary close act from
`lifecycle-acts-on-the-case`, with a result type of
niet-ontvankelijk, the judge recorded, and the applicant told through the
declared moments. It is a close, so it inherits the retention rule and the
archival consequence the result type carries.

## D-4. The remedy clause is declared once and printed, and it starts a clock

xxllnc declares the remedy on the case type
(C-decisions-25). The Dutch requirement behind it is that a besluit
carries a bezwaarclausule naming where and within how long.

So the case type declares the kind, the term in days and the body. The
decision document prints it from that declaration rather than from a
template somebody edited, so a change in the law is one configuration
change and not forty templates.

Sending the decision binds the remedy term as a term instance of its own
kind, which is what makes "is this decision still open to bezwaar"
answerable rather than arithmetic somebody does in their head.
