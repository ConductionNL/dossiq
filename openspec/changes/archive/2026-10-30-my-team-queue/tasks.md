# Tasks: my-team-queue

### Task 1: The current user's primary team is read from the organisation roles
- **spec_ref**: `openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles`
- **files**: `lib/Service/Team/PrimaryTeamService.php`, `lib/Controller/MyTeamController.php`, `appinfo/routes.php`
- [x] Implement: PrimaryTeamService, GET /api/me/team
- [x] Test: `tests/Unit/Service/Team/PrimaryTeamServiceTest.php`

### Task 2: The team queue opens on the reader's team
- **spec_ref**: `openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-team-queue-opens-on-the-readers-team`
- **files**: `src/services/myTeamSource.js`, `src/main.js`, `src/manifest.json`
- [x] Implement: register the loader, the view filter
- [x] Test: `tests/vitest/myTeamQueue.spec.js`
