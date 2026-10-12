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

Amended 10 October 2026 (Q-dossiq-L1-4, decision 179): the screen reads
the case reference, the received moment, the term start and the deadline
from the submit response. A form submit creates the case in the same
request, so all four exist before the confirmation renders. There is no
queue to wait for and no "follows later" line. Built by
`a-request-form-opens-the-case-at-once` with portaliq's
`submit-creates-the-case-directly`.
