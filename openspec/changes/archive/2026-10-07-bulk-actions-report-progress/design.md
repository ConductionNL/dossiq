# Design: bulk-actions-report-progress

## D-1. The browser loop goes, and nothing of it survives

A bulk act that runs in the browser stops when the tab closes, reports
nothing anybody can read afterwards, and leaves four hundred statutory
cases in an unknown state. dossiq hands the act to the job and renders
what the job says.

If a task here needs a queue, a retry or a cancel, it is on the wrong side
of the boundary.

## D-2. The skip list is the feature

Progress is a bar. The skip list is the thing a handler acts on: twelve of
four hundred did not move, here they are, here is why each one refused.

So the per-row outcome is rendered as a list a handler can open, not as a
count in a toast that disappears.

## D-3. A justification is a case policy, so dossiq holds it

openregister's job does not know that reassigning four hundred cases is a
thing a coordinator must explain. Dimpact ZAC puts the requirement on the
werkvoorraad, which is where the act is.

So the justification is required by dossiq before the job is handed over,
and it is stored on the act rather than in a log.

## D-4. One case type version per selection, and the refusal comes first

C-case-core-3's clause: "it is the guard that makes the previous row safe
rather than merely reversible". A bulk attribute change across two
versions of a case type writes a value into a field that means two things.

The refusal is at selection time, before the simulation, because a dry run
of an act that should never have been offered is a dry run of the wrong
question.

## D-5. Select-all says which all it means

C-search-17 asks for the whole result set and for the product to say which
of the two you have. A handler who believes they selected four hundred and
selected twenty-five is about to be surprised either way.

So the affordance states the number and the scope in words, and changing
from the page to the whole result is a deliberate second act.

## D-6. Releasing a caseload is the same job

C-case-core-45 is a bulk reassignment with a different reason.
`SubstitutionController` already knows who stands in for whom. It gains no
loop of its own: it builds the selection and hands it to the same job,
with the same justification requirement.
