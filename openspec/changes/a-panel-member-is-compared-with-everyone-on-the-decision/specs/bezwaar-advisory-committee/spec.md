## MODIFIED Requirements

### Requirement: Committee Composition Tracking

The system SHALL track which committee members participated in each bezwaar case to ensure independence and prevent conflicts of interest. Under Awb Art. 7:13 lid 3, no panel member may have worked on the contested primair besluit. Before an advice request moves from `assigned` to `in-deliberation`, the system MUST compare every panel member with every person on the contested decision. Those persons are the decision's `decidedBy`, its owner (`@self.owner`), `createdBy`, `steller` and `signatory`, and each entry of `signatories`. Any match MUST block the transition.

**Feature tier**: V1

#### Scenario: Committee member was involved in original decision

- **GIVEN** an advice request in `assigned` with panel `["a.bakker", "p.janssen"]`
- **AND** `p.janssen` signed the contested primair besluit
- **WHEN** the bezwaar handler moves the request to `in-deliberation`
- **THEN** the transition is refused with reason "Lid was betrokken bij het bestreden besluit (Awb Art. 7:13 lid 3)"
- **AND** the refusal names `p.janssen` as the conflicting member
- **AND** the request stays in `assigned`
- **AND** the request's trail holds an `independence-check-failed` entry

#### Scenario: every person on the decision is compared, not only the first one found

- **GIVEN** a contested decision owned by `o.smit`, created by `c.devries` and decided by `d.visser`
- **AND** a panel `["a.bakker", "d.visser"]`
- **WHEN** the bezwaar handler moves the request to `in-deliberation`
- **THEN** the transition is refused and names `d.visser`

#### Scenario: Minimum committee composition

- **WHEN** an advisory report is being created
- **THEN** the system SHALL require at least a `committeeChair` to be assigned
- **AND** the system SHALL display a recommendation to have at least 3 committee members (voorzitter + 2 leden) per municipal best practice
