# dashboard Delta: my-teams-queue

## ADDED Requirements

### Requirement: REQ-DASH-029 Your team's queue is the unclaimed work of the reader's own teams

The `your-teams-queue` preset in the Dashboard's `config.userWidgets` SHALL
filter `assignedGroup` on `@myGroups` beside the Queue page's own conditions
(`assignee: "IS NULL"`, `isFinalStatus: false`, `statusHiddenInLists: false`,
`isDraft: false`, `isTemplate: false`). A team is a Nextcloud group, and
`@myGroups` resolves to the ids of the groups the reader is in. While those
load, and for a reader in no group, the list SHALL NOT fetch and SHALL show
its prompt, never every team's work.

This narrows REQ-DASH-024, whose second list was the shared queue because no
token could name the reader's groups.

#### Scenario: A handler sees only their own teams' unclaimed cases

- **GIVEN** a handler in the group `behandelaars` and not in `toezicht`
- **AND** an unclaimed open case with `assignedGroup: behandelaars` and one with `assignedGroup: toezicht`
- **WHEN** they add "Your team's queue" to their Dashboard
- **THEN** the list SHALL show the first case and SHALL NOT show the second

@e2e exclude The filter is asserted in tests/vitest/dashboardUserLayout.spec.js and its resolution in nextcloud-vue's tests/utils/resolveFilterTokensMyGroups.spec.js; a browser run needs the nextcloud-vue release that carries @myGroups, after which tests/e2e/a-dashboard-the-reader-arranges.spec.ts is the place to extend.

#### Scenario: A reader in no team sees a prompt, not every team

- **GIVEN** a reader in no Nextcloud group
- **WHEN** the preset renders
- **THEN** it SHALL show its prompt and SHALL send no request

@e2e exclude Library behaviour, covered by nextcloud-vue's tests/utils/resolveFilterTokensMyGroups.spec.js.
