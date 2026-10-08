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
