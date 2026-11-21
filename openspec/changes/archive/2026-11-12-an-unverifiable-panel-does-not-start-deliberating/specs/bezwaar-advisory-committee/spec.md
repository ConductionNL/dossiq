## ADDED Requirements

### Requirement: a panel whose independence cannot be verified does not start deliberating

When the advice request moves from `assigned` to `in-deliberation`, the independence check MUST be able to run. When it cannot, the transition MUST be refused. That covers a store read that fails while the check resolves the contested primair besluit, and an objection or decision schema that is not configured. The refusal MUST name no conflicting member. Its reason MUST be "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)". An `independence-check-unverifiable` entry MUST be written to the request's trail before the refusal, and the refusal MUST stand whether or not that entry is written.

#### Scenario: the store fails while the check reads the contested decision
- GIVEN an advice request in `assigned` with panel `["a.bakker", "p.janssen"]`
- AND reading the contested primair besluit fails
- WHEN the bezwaar handler moves the request to `in-deliberation`
- THEN the transition is refused with reason "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)"
- AND the request stays in `assigned`
- AND the request's trail holds an `independence-check-unverifiable` entry with no conflicting member

#### Scenario: the decision schema is not configured
- GIVEN no `decision_schema` is configured
- WHEN the bezwaar handler moves an advice request with a panel to `in-deliberation`
- THEN the transition is refused the same way
- AND the request stays in `assigned`
