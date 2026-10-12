---
kind: code
depends_on: [case-definition-packages]
---

# Proposal: vth-package

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The VTH case types, their flows and their inspection checklists were written by two repair steps and seven helper classes named after the procedure. They become one case definition package, and the seeders go.

## What changes

- The package `lib/Settings/packages/vth/` ships with `install: auto`. It holds six case types, 34 statuses, 20 roles, 27 fields, 17 document types, four published flows, six draft flows and three inspection checklists, all with fixed ids.
- A case type names itself by `identifier`, which equals the slug the seeder used. The term definitions and the publish step keep finding it under the same key.
- Handhavingszaak names its default flow (regulier) on the case type, because it has two routes.
- The installer adopts what the seeders wrote. The seeders gave a case type no identifier, so the installer finds it by title (dossiq#3778). A checklist is found by name inside its adopted case type.
- Deleted: `Repair/VthSeedDataRepairStep`, `Repair/SeedVthWorkflowTemplates`, everything under `Repair/Vth/`, `lib/Settings/vth_seed_data.json`, `lib/Settings/seed/vth-workflow-templates/` and their unit tests.
- The catalogue entries `klacht-toezicht` and `bezwaar` are not in the package. No shipped case type had those slugs, so the seeder skipped them on every install.
- Deleted too: `VTHTemplateService`, `VTHTemplateController` and the routes `/api/vth/templates` and `/api/vth/templates/{slug}/activate`. They repeat the generic `TemplateLibraryService` for files named `vth-*`, and no screen, test journey or collection calls them.
- The six flow drafts in `lib/Settings/vth-templates/` become draft flows of the package (decision 208), and the directory goes. A draft is stored with `lifecycleStatus: draft`. No case follows it until an administrator publishes it. Each draft names statuses by id, its checklist guards name the items they wait for, and its notifications use the `notify` action.
- The regulier draft guards on the field `inhoudelijkeToets`, so omgevingsvergunning bouwactiviteit gains that field.
- A draft sits on the route it describes. Sloopmelding, toezichtzaak bouw, toezichtzaak milieu and handhavingszaak get version 2 of their existing route. Omgevingsvergunning gets the routes `regulier` and `uitgebreid` beside `standaard`.
- `MigrateWorkflowDefinitions` no longer counts a draft as a flow the case type has. Sloopmelding and toezichtzaak milieu ship only a draft, and without this change a clean install would give them no basis flow.
- A new sweep, `PackageFlowStatusReferencesTest`, checks every package: a flow names only statuses of its own case type.
- A new sweep, `PackageDraftFlowsTest`, hands every package's flows to the real `WorkflowDefinitionService`: a draft is never among the flows a case can follow, never a case type's default, and its three lifecycle fields agree.
- `WorkflowGuardConformanceTest` walks every package flow, the drafts too.
- A Newman read-back collection and a Playwright journey prove the install.

## Impact

- A clean install gets the same VTH configuration from the package installer.
- An upgraded instance keeps its rows and ids. An adopted case type keeps an empty identifier.
- The catalogue's `spawnCase` action is stored as `createSubCase`, the form the seeder already wrote.
- The files `lib/Settings/templates/vth-*.json` stay. The generic template library still lists them.
- An administrator finds six draft flows in the flow editor. Before publishing one, read what it still needs:
  - The uitgebreide draft puts three steps (ontwerpbesluit, zienswijze, definitief besluit) on the one status Besluitvorming. Give them their own statuses, or the case shows the same status through all three.
  - A notification on a step is stored with the step and is not sent. Only a notification on a transition is sent. Move the ones you want.
  - Publishing a version 2 draft replaces the published flow on that route. Publishing the regulier or the uitgebreide draft adds a route to omgevingsvergunning, and the case type then needs a default route.
- A draft an administrator changed or published is kept on upgrade. The installer never overwrites a row the instance already holds.
- The LHS matrix seeders are not part of this change.
