# Design: case-delete-guard

## D-1. One listener, a list of rules

The listener runs each rule and collects the ones that hold. If the list is
non-empty it stops the event with a `CaseHeldException` carrying the rule
slugs (`open-term`, `has-subcases`, `legal-hold`, `in-retention`) and a
message listing them in words. One refusal, every reason.

## D-2. Rules read, never write

Each rule is one bounded query (ADR-058): terms by `case` and status,
sub-cases by `parentCase` with limit 1, the hold flag on the object, the
retention state from OpenRegister's retention metadata. No rule mutates.

## D-3. Registration is by schema

The registrar binds the listener to `register: dossiq, schema: case` at the
registration site, so no other schema constructs it.

## D-4. Bezwaar hold stays

`BezwaarLegalHoldListener` keeps its job; the new guard reads the hold it
sets and reports it as `legal-hold`. Two listeners, one rule each way.
