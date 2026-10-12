---
kind: code
depends_on: [case-definition-packages]
---

# Proposal: vth-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The VTH case types, their flows and their inspection checklists were written by two repair steps and seven helper classes named after the procedure. They become one case definition package, and the seeders go.

## What changes

- The package `lib/Settings/packages/vth/` ships with `install: auto`. It holds six case types, 34 statuses, 20 roles, 26 fields, 17 document types, four published flows and three inspection checklists, all with fixed ids.
- A case type names itself by `identifier`, which equals the slug the seeder used. The term definitions and the publish step keep finding it under the same key.
- Handhavingszaak names its default flow (regulier) on the case type, because it has two routes.
- The installer adopts what the seeders wrote. The seeders gave a case type no identifier, so the installer finds it by title (dossiq#3778). A checklist is found by name inside its adopted case type.
- Deleted: `Repair/VthSeedDataRepairStep`, `Repair/SeedVthWorkflowTemplates`, everything under `Repair/Vth/`, `lib/Settings/vth_seed_data.json`, `lib/Settings/seed/vth-workflow-templates/` and their unit tests.
- The catalogue entries `klacht-toezicht` and `bezwaar` are not in the package. No shipped case type had those slugs, so the seeder skipped them on every install.
- Deleted too: `VTHTemplateService`, `VTHTemplateController` and the routes `/api/vth/templates` and `/api/vth/templates/{slug}/activate`. They repeat the generic `TemplateLibraryService` for files named `vth-*`, and no screen, test journey or collection calls them.
- A new sweep, `PackageFlowStatusReferencesTest`, checks every package: a flow names only statuses of its own case type.
- A Newman read-back collection and a Playwright journey prove the install.

## Impact

- A clean install gets the same VTH configuration from the package installer.
- An upgraded instance keeps its rows and ids. An adopted case type keeps an empty identifier.
- The catalogue's `spawnCase` action is stored as `createSubCase`, the form the seeder already wrote.
- The files `lib/Settings/templates/vth-*.json` stay. The generic template library still lists them.
- The six files in `lib/Settings/vth-templates/` stay. No code reads them. Whether they become draft flows in the package is an open content question.
- The LHS matrix seeders are not part of this change.
