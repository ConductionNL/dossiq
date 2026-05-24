# Retrofit — workflow-definition-model

Describes observed behavior of 3 PHP files (~33 methods) — controller, service, migration repair — as 3 new REQs covering the workflow definition lifecycle implementation.

## Affected code units
- lib/Controller/WorkflowDefinitionController.php (6 methods) — publish/deprecate/clone + lookups
- lib/Service/WorkflowDefinitionService.php (20 methods) — full lifecycle service
- lib/Repair/MigrateWorkflowDefinitions.php (7 methods) — data-migration helper

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
