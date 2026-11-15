## ADDED Requirements

### Requirement: A case type names the teams that handle it (REQ-CT-44)

A case type SHALL name the teams that handle its cases as Nextcloud group ids:
its default group (`handling.defaultGroup`, REQ-SEED-01) and the groups in
`handling.teams`. The default group SHALL always count as a handling team.
`CaseTypeHandling::teams()` SHALL be the one reader and SHALL return the
default group first, then `handling.teams` in order, without duplicates and
without empty ids. An administrator SHALL set `handling.teams` on the case type
form as "Handling teams", a choice of Nextcloud groups, and saving SHALL keep
the other switches of the handling block. A user is in a team when Nextcloud
says they are a member of that group; dossiq SHALL keep no member list of its
own for this.

#### Scenario: The default group and the extra teams together
@e2e exclude A pure reader over a case type row, asserted in CaseTypeHandlingSwitchesTest.
- **GIVEN** a case type whose default group is `vergunningen` and whose `handling.teams` is `["handhaving", "vergunningen", ""]`
- **WHEN** its handling teams are read
- **THEN** they SHALL be `["vergunningen", "handhaving"]`

#### Scenario: A case type that names only a default group is linked
@e2e exclude A pure reader, asserted in CaseTypeHandlingSwitchesTest.
- **GIVEN** a case type with a default group `woo` and no `handling.teams`
- **WHEN** its handling teams are read
- **THEN** they SHALL be `["woo"]`

#### Scenario: An administrator adds a handling team
@e2e exclude The field's write is asserted in tests/vitest/generalTabHandlingTeams.spec.js; the e2e instance has no Nextcloud groups seeded for case types.
- **GIVEN** an administrator on a case type's General tab
- **WHEN** they choose "Toezicht en handhaving" under Handling teams
- **THEN** the form SHALL hold `handling.teams` `["handhaving"]`
- **AND** the default group, handler, messages and intake screen of the block SHALL be unchanged
