# Design: custody-and-handover-of-a-case

## D-1. A chain is a record, not a reading of the audit

The audit trail says what changed and when. Turning a sequence of diffs into
"unit A held this from 3 March to 12 April" needs every move to have been
written in a shape that supports the question, and it needs the holding that
is open now to be a row too.

So custody is its own record with a start and an end. The audit still
attributes each move. The two answer different questions and neither
replaces the other.

## D-2. Closing and opening are one act

A holding that ends without the next one starting leaves the case held by
nobody, and a chain with a gap is worse than no chain, because it reads as
an answer. So the transfer closes the current holding and opens the next in
the same write, and a case with no open holding is a defect the reader can
see.

## D-3. A pull needs an answer, a claim does not

An unassigned case belongs to nobody, so taking it needs nobody's
permission. A held case belongs to someone who may be halfway through it.
The difference is not politeness, it is that the holder knows something the
asker does not.

So the pull is a request with two answers, and no is an answer with a reason
attached.

## D-4. An unanswered request escalates rather than expiring

A request that times out silently teaches people not to use it. After the
period the case type declares, the request goes to the unit that holds the
case, which is where the decision belongs when the holder is unavailable.
That is also the case where a pull matters most.

## D-5. Consent is a precondition, not a warning

A dialog that says "are you sure, this is sensitive" moves the
responsibility to the person with the least information. The refusal is
mechanical: without a `toestemming` covering this case, this receiver and
this moment, the hand-off does not happen.

## D-6. The scope travels with the share

Recording a scope that nothing enforces is the defect this row already
names: `toestemming` is declared and nothing reads it. So the scope is
written onto the share at the moment the share is made, and the enforcement
of it is asked of the platform rather than reimplemented here.

## D-7. Not every hand-off needs consent

A move between two teams of the same organisation is not a disclosure. The
gate is on the organisation boundary, and which case types need consent even
inside it is declared per case type, because a Jeugdwet file and a parking
permit are not the same question.
