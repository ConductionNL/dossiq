# Proposal: my-team-queue

## Why

Board DqTeamwachtrij opens the team queue on the reader's own team: "7 van 11 open zaken zonder behandelaar · team Woo
en informatie", with "Team: Woo en informatie" on the Active line, removable. The queue showed every team's unassigned
cases. Which of a user's Nextcloud groups is their team is dossiq's knowledge: the organisation roles name their group in
`ncGroupId`.

## What changes

- `GET /api/me/team` answers the signed-in user's primary team: the deepest `organisatieRol` (by `parentRoleId`) whose
  `ncGroupId` the user is a member of, ties by role name; `null` when no role binds one of their groups.
- dossiq registers that loader with nc-vue (`registerMyTeamSource`), which resolves the `@myTeam` filter token from it.
- The team queue view filters on `assignedGroup: @myTeam`. A user in no team gets no team filter and no chip; their other
  groups are never used instead.

Needs nc-vue `feat/saved-view-tokens-my-team` (saved-view token resolution, `@myTeam`, schema 2.145.0). The chip's field
name "Team" comes from `filterLabels.assignedGroup` (DQ-BOARDSCFG filter-labels).
