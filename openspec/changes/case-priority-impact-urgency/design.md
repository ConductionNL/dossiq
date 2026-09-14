# Design: case-priority-impact-urgency

## D-1. Two stored facts, one derived answer

Impact is how much it matters if this goes wrong. Urgency is how soon.
They are different questions, a different person often knows each, and
multiplying them into one number is the step a product should do rather
than a handler.

A scalar is what somebody types, and what nobody maintains. Deriving from
two stored facts is what an administrator configures once, and it is the
shape D14 chose and the one dossiq's own pending 2.28 proposes.

## D-2. The matrix is per case type, because the answer is not universal

High impact on a bezwaar and high impact on a melding openbare ruimte are
not the same urgency. So the matrix is administered per case type, with an
instance default so a fresh install has one that works.

## D-3. The derived value is what everything already reads

`case.priority` exists and is `facetable`. Making the derived value land
in that field means every existing reader, filter and facet keeps working,
and nothing has to learn a new field name on the day this ships.

Its enum stays `low`, `normal`, `high`, `urgent`. A fifth vocabulary would
be the fifth.

## D-4. An override is a fact, not a state to be overwritten

A wethouder calls. A handler raises the priority. Tomorrow the derivation
runs and puts it back, and nobody notices until the case is late.

So an override is stored beside the derived value, with who set it and
why, and it wins until it is cleared. The derived value keeps being
computed underneath, so clearing the override returns to the right answer
rather than to whatever was last typed.

## D-5. A rule may raise and never lower

Request Tracker's `EscalatePriority.pm` and `LinearEscalate.pm` raise as
the term runs out. Raising is safe. Lowering is not: a rule that lowers
priority on a case somebody deliberately escalated is a rule that hides
work.

So the term rule is one-directional. It is declared, not coded, on
openregister's rules engine, so the condition and the action are readable
by the administrator who has to defend them.

## D-6. The escalation matrix stops inventing its own words

`DeadlineEscalationService::DEFAULT_MATRIX` carries `low`, `medium`,
`high`, `critical` per threshold. Four words that are not the case's four
words, on a constant that `TermijnTimerService` already notes is
hardcoded.

The escalation reads the case's priority. Its own vocabulary becomes a
notification urgency if it needs one, named as such, so nobody reads two
fields called priority and assumes they agree.

## D-7. Colour and order are declared, not drawn

A priority with no order cannot sort a list, and a colour chosen in a
component cannot be themed. So the order and the NL Design System colour
token are declared with the value, the way `statusType.colour` already is,
and nextcloud-vue's list renders what it is given.
