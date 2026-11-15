# my-team Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [my-team-queue](../../)

## Purpose

The team queue is the reader's team's queue.

## ADDED Requirements

### Requirement: The current user's primary team is read from the organisation roles

`GET /api/me/team` SHALL answer `{ groupId, name }` for the signed-in user only: the `ncGroupId` and `roleName` of the
organisation role, among those whose `ncGroupId` the user is a member of, that lies deepest in the `parentRoleId` tree,
the first by role name among equally deep ones. It SHALL answer `{ groupId: null, name: null }` when no role binds a
group of the user, and SHALL never answer another group of the user instead. It SHALL take no user or object id.

#### Scenario: Pieter

- **GIVEN** Pieter in `zuiddrecht-medewerkers` (Gemeente Zuiddrecht) and `zuiddrecht-woo-en-informatie` (Informatie en
  Woo, under Bedrijfsvoering)
- **THEN** his team is `zuiddrecht-woo-en-informatie`

### Requirement: The team queue opens on the reader's team

The team queue view SHALL filter on `assignedGroup: @myTeam`, which dossiq resolves through `GET /api/me/team`. The
filter SHALL show as a removable chip naming the team, and SHALL be absent for a reader in no team.

#### Scenario: DqTeamwachtrij

- **GIVEN** Pieter in Woo en informatie
- **WHEN** he opens Wachtrij
- **THEN** the list shows Woo en informatie's unassigned cases and the Active line reads "Team: Woo en informatie"
