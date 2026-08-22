# personal-settings-surface

## ADDED Requirements

### Requirement: Self-service substitution is a personal setting

A user's own substitution (vervanging) registration SHALL be presented as a
Nextcloud **personal** setting, registered via `<personal>` in
`appinfo/info.xml`, and SHALL NOT be a top-level app page or navigation entry.
The surface SHALL remain scoped to the authenticated user, with that scoping
enforced server-side.

#### Scenario: Substitution appears under personal settings

- **GIVEN** a user opens Settings → Personal
- **THEN** a procest substitution section is available
- **AND** it lists only substitutions where that user is the absentee

#### Scenario: The standalone substitution page is gone

- **GIVEN** the manifest
- **THEN** no `/substitution` page and no substitution menu entry exist

### Requirement: The coordinator substitution console stays an app page

The coordinator-facing substitution console — register-on-behalf, revoke,
inspect capacity-stamped actions, bulk reassignment — SHALL remain an app page,
because it operates on other users' records and is not a personal setting. Its
coordinator-role check SHALL remain enforced server-side.

#### Scenario: The admin console is unaffected

- **GIVEN** the manifest
- **THEN** the `/substitution-admin` page still exists
- **AND** a non-coordinator calling its endpoints is refused server-side
