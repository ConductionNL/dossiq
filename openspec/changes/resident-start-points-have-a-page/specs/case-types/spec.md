## ADDED Requirements

### Requirement: The shipped Woo request case type names a handling team (REQ-RSP-002)

The `woo-verzoek` case type dossiq seeds SHALL declare `handling.defaultGroup: behandelaars`, a
group `ProvisionAssignedGroups` creates. Through `CaseTypeHandling::teams()` and the handling team
rules (REQ-ACC-10 as amended by `case-access-by-handling-team`), members of `behandelaars` SHALL read
and update every Woo request case, assigned or not, and SHALL NOT delete one.

#### Scenario: An unassigned Woo request is read by its handling team
@e2e exclude An access rule OpenRegister evaluates from the schema; asserted on the merged register in tests/Unit/Settings/WooCaseTypeHandlingTeamTest.php. A live check needs a non-admin account in behandelaars on an upgraded instance.
- **GIVEN** a Woo request case with no assignee
- **WHEN** the handling team rules are computed from the shipped case types
- **THEN** the case schema's `read` and `update` SHALL hold `{group: behandelaars, match: {caseType: {$in: [<woo-verzoek id>]}}}`
- **AND** `delete` SHALL NOT hold it
