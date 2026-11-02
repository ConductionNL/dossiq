## ADDED Requirements

### Requirement: a panel whose independence cannot be verified does not start deliberating

When the advice request moves from `assigned` to `in-deliberation`, the independence check MUST run and MUST resolve at least one person on the contested primair besluit. When it cannot, the transition MUST be refused. That covers an advice request that names no objection, no object store, an objection or decision schema that is not configured, and a store read that fails. It also covers a chain that ends without a person: no objection on the case, no contested decision on the objection, a contested decision that is not found, or a decision that names no person. Only an empty panel is not checked, because the transition already requires a panel. The refusal MUST name no conflicting member. Its reason MUST be "Onafhankelijkheid van het panel kon niet worden vastgesteld (Awb Art. 7:13 lid 3)". An `independence-check-unverifiable` entry MUST be written to the request's trail before the refusal, and the refusal MUST stand whether or not that entry is written.

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

#### Scenario: the contested decision names no person
- GIVEN an advice request in `assigned` with panel `["a.bakker", "p.janssen"]`
- AND the contested primair besluit has no `decidedBy`, owner, `createdBy`, `steller`, `signatory` or `signatories`
- WHEN the bezwaar handler moves the request to `in-deliberation`
- THEN the transition is refused the same way
- AND the request stays in `assigned`

#### Scenario: the advice request names no objection
- GIVEN an advice request in `assigned` with a panel and neither `bezwaar` nor `objectionProceeding` set
- WHEN the bezwaar handler moves the request to `in-deliberation`
- THEN the transition is refused the same way
