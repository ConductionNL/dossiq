# Proposal: queue-figures-per-user-teams

## Why

On :8080 (24 Oct) the team queue's tile read "Zonder behandelaar 144", its menu badge 144 and its list "1 van 1". The
tile counted every unassigned case the reader can read (Pieter is in the admin group), the badge counted with no team
filter at all, and the list filtered on the primary team only. Coordinator decision (24 Oct): the tile, the list and the
badge agree, on one definition: the cases without a handler in the teams the reader belongs to; the admin group is no
team.

## What changes

- PrimaryTeamService::teams/teamsOf: a reader's teams are the roles that bind one of their groups and are no ancestor
  of another such role (the municipality above a team is not a team of its own), primary first; the admin group never.
- GET /api/me/team also answers `groupIds` and `names` (every team); `groupId`/`name` stay the primary team.
- The `@myTeam` source answers every team when there are several (nextcloud-vue feat/my-team-source-may-answer-several-teams),
  so the list filters on all of them; the count line names them all.
- The KPI queue figures count only cases in those teams (a reader in no team: every case, as the list drops its filter).
- The simple profile's Queue badge counts on `assignedGroup: @myTeam` as the list does.

Ships with the nextcloud-vue release that carries the several-teams source and the menu count that waits for the team.
