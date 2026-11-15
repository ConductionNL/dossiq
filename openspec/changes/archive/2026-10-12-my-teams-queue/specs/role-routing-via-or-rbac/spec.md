# role-routing-via-or-rbac Delta: my-teams-queue

## ADDED Requirements

### Requirement: The Team column shows the team's name

The Team column on the Cases index SHALL use nextcloud-vue's group cell
(`widget: group`), so it shows the Nextcloud group's display name, and the
group id while the name loads or when the group cannot be found.

#### Scenario: A case in Toezicht reads Toezicht

- **GIVEN** a case with `assignedGroup: toezicht` and a group `toezicht` whose display name is "Toezicht en handhaving"
- **WHEN** a handler opens the Cases index
- **THEN** the Team cell SHALL read "Toezicht en handhaving"

@e2e exclude The column declaration is asserted in tests/vitest/casePartiesWidget.spec.js and the cell in nextcloud-vue's tests/components/CnCellRendererGroup.spec.js; a browser run needs the nextcloud-vue release that carries the group cell.
