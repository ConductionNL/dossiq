# Design: deelzaken-inherit-the-parent-grants

## D-1. The edge already exists and is declared, not discovered

`case.parentCase` is a uuid referencing `case`. The change declares it as
the hierarchy edge on the schema. `relatedCases` is deliberately not
declared: a link between two cases is not a parent, and access that
travels along links reaches places nobody intended.

## D-2. One guard, not two

`CaseAccessGuard::hasCaseReadAccess()` and `hasCaseMutationAccess()` stop
deciding and ask the platform. The rule this follows is the one the
openregister design writes down: an app that keeps its own answer beside
the platform's produces two answers, and the safe-looking one is not
always the one that runs. The guard keeps what is genuinely dossiq's, such
as status-dependent rules, and loses what is access resolution.

## D-3. The verb does not widen going down

A read grant on a parent is a read grant on the deelzaak. It is not a
mutation grant. This is the measured half of the competitor's behaviour
and the property the tests pin.

## D-4. Provenance on the page, because the question gets asked

A deelzaak opened through an inherited grant shows which case granted it.
Without that, a handler seeing a colleague on a deelzaak cannot tell where
that came from and cannot remove it, because the grant is not on the case
in front of them.

## D-5. The Sharing tab warns before, not after

Sharing a parent reaches its deelzaken. The Sharing tab says so at the
moment of sharing. A share that quietly widens is the failure this row is
about, inverted.
