# case-access-control Delta: a-handling-team-opens-its-unclaimed-cases

**Status**: draft
**Scope**: dossiq's own per-case reads.

## ADDED Requirements

### Requirement: A handling team member opens a case of its case type before it is claimed (REQ-ACC-11)

dossiq's per-case read check SHALL let a member of one of the handling teams of the case's case type (`handling.defaultGroup` and `handling.teams`) read the case, as OpenRegister does under REQ-ACC-10. The case's own `assignedGroup` SHALL NOT grant. Acting on the case SHALL stay with its handler: the acts read SHALL answer such a reader with every act refused and the reason "Claim this case first: only its handler acts on it.", and every act SHALL be refused with 403.

#### Scenario: A team member opens an unclaimed case of their team
@e2e exclude needs a second staff account on a shared instance; covered by CaseAccessGuardReadAccessTest and CaseActsControllerContractTest
- **GIVEN** a case type whose handling team is `behandelaars`
- **AND** an unclaimed case of that type
- **WHEN** a member of `behandelaars` opens it
- **THEN** the case's reads answer
- **AND** the acts read lists every act refused, saying to claim the case first

#### Scenario: Someone outside the handling teams reads nothing
@e2e exclude covered by CaseAccessGuardReadAccessTest
- **GIVEN** a user in the case's `assignedGroup` but in none of the case type's handling teams
- **WHEN** they open the case
- **THEN** dossiq's reads refuse with 403
