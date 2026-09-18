# Design: intake-says-when-the-term-starts

## D-1. Two moments, both stored

`receivedAt` is when the submission arrived. `termStartsAt` is the first
working moment on or after it. Storing both is what makes the sentence
possible later: a handler answering a complaint in March has to be able to
say what the citizen was told in January, and a recomputed answer is not
that.

## D-2. The flag is derived, and stored anyway

`receivedOutsideWorkingHours` is true when the two moments differ. It is
derivable, and it is stored because it is what a report filters on and
what a template branches on, and because Frappe's measured behaviour is a
stamp at create rather than a computation at read.

## D-3. One calendar answers both the confirmation and the term

The confirmation asks the same calendar the term counts on. A second
source would eventually disagree, and the disagreement would surface as a
citizen quoting a date the system does not recognise.

## D-4. The sentence appears only when it is needed

A request filed on Tuesday at ten reads: received, starts, deadline, three
dates, no explanation needed. A request filed on Sunday reads the same
three plus one sentence saying the term starts on the first working day.
An explanation on every confirmation teaches people to stop reading them.

## D-5. On screen first, then in the mail

The moment after pressing send is when someone is paying attention. The
mail repeats it for the record.

## D-6. What the build found that the design did not know

**The calendar arrived first.** The proposal said `terms-on-the-engine-calendar`
was open and `WorkingDayCalculator` would answer until it landed. It landed
(#2941), so this change reads the engine calendar directly through
`WorkingDayRoll`, which already resolves `SlaCalculator` and the organisation
calendar. One resolver, one calendar, which is what D-3 asked for and could not
have had otherwise.

**`roll()` could not be reused, and the reason is the time.** It preserves the
clock time it is given, because a term ends at the end of its day. Asked for an
intake start it would answer nine in the evening on Monday for a Sunday-evening
filing. `firstWorkingMomentAtOrAfter()` keeps the instant the calendar returns,
which is the start of the working day, and asks no `rollToWorkingDay` flag:
whether a term rolls is a per-term legal choice, but when the clock starts is
the calendar's plain answer.

**An unanswered calendar had no place in the design.** It stamps `receivedAt`
and nothing else. The alternative is a weekday rule of dossiq's own, which is a
second calendar, which `NoLocalCalendarTest` refuses and the fleet has been
paying down all quarter.

**The template had two placeholders nothing answered.** The shipped
ontvangstbevestiging body used `{{startDatum}}` and `{{behandelaar}}`, and the
variable map has `startDate` and `handler`. Both rendered as their own braces in
every mail this template has ever sent. Fixed here because the body was being
rewritten anyway.

**The conditional sentence lives in the value.** A template is a flat string
with no `if`, so `{{buitenKantoortijden}}` resolves to the sentence or to the
empty string. `collectUnresolved()` uses `isset()`, so an empty value is
resolved rather than reported missing, which is what makes this work.
