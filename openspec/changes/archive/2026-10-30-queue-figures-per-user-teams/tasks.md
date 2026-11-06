# Tasks: queue-figures-per-user-teams

### Task 1: The team queue is the unassigned open cases of the reader's teams
- **spec_ref**: `openspec/changes/queue-figures-per-user-teams/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams`
- **files**: `lib/Service/Team/PrimaryTeamService.php`, `lib/Controller/MyTeamController.php`, `lib/Service/KpiAggregationService.php`, `src/services/myTeamSource.js`, `src/menu-layout.simple.json`
- [x] Implement: teams, the answer's lists, KPI scope, the source, the badge filter
- [x] Test: PrimaryTeamServiceTest, MyTeamControllerTest, KpiAggregationServiceTest, myTeamQueue/listViewOverrides/structureProfile specs
