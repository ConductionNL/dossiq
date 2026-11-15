# Tasks: case-access-by-handling-team

## 1. Register

- [x] 1.1 `lib/Settings/register.d/91-register-access.json`: register level
  authorization for `dossiq-medewerkers` and the background service group.
- [x] 1.2 `lib/Settings/register.d/66-notification-routing.json`: the case
  schema's own read, create, update and delete beside its `roles`.
- [x] 1.3 `lib/Settings/register.d/90-background-service-account.json` and
  `84-woo-refusal-grounds.json`: `authenticated` and `public` become
  `dossiq-medewerkers`.
  - `tests/Unit/Settings/RegisterAccessDeclaredTest.php`

## 2. Projection

- [x] 2.1 `lib/Service/Access/CaseTeamAccessRules.php` (pure) and
  `lib/Service/Access/CaseTeamAccessProjector.php`.
  - `tests/Unit/Service/Access/CaseTeamAccessRulesTest.php`
  - `tests/Unit/Service/Access/CaseTeamAccessProjectorTest.php`
- [x] 2.2 Wire it: `InitializeSettings` after the import, and
  `CaseTeamAccessListener` after every case type save (a publish saves the case
  type row, so it passes through the same listener).
  - `tests/Unit/Listener/CaseTeamAccessListenerTest.php`

## 3. The staff group

- [x] 3.1 `ProvisionAssignedGroups` provisions `dossiq-medewerkers`.
- [x] 3.2 `lib/Repair/SeedStaffGroup.php`, in `install` and `post-migration`.
  - `tests/Unit/Repair/SeedStaffGroupTest.php`

## 4. Specs

- [x] 4.1 Amend REQ-ACC-10 in `case-access-control` and the
  `case-type-handling-teams` design.
- [x] 4.2 `openspec validate case-access-by-handling-team --strict`.
