# Proposal: VTH Workflow Templates

## Summary

VTH (Vergunningen, Toezicht & Handhaving) is the Dutch municipal cross-domain process family that covers permits, inspection, and enforcement under the Omgevingswet, the Algemene wet bestuursrecht (Awb), and the various sectoral environmental and building acts. Today, a tenant adopting Procest for VTH has the engine (`workflow-definition-model`, PR #347) and the model, but no out-of-the-box workflow definitions — they would have to draft each one by hand against the AWB / Omgevingswet procedural rules before they can run a single case. This change ships a catalog of six reference `workflowTemplate` definitions covering the dominant VTH lanes: `aanvraag-omgevingsvergunning`, `toezichtbezoek`, `handhavingstraject`, `bezwaar` (cross-link to the existing `bezwaar-lifecycle` spec), `klacht-toezicht`, and `spoedig-herstel`. Each is delivered as seed data via a repair step, exposed in an admin "Sjablonen-catalogus" UI, and selectively enable-able per tenant.

## Problem

`workflow-definition-model` only seeds workflows for `Bezwaar` and `Beroep` (REQ-WDM-9 in PR #347). VTH-specific lifecycles — which carry deadlines (`Awb 4:13` 8-week beslistermijn, `Awb 7:10` 6-week bezwaartermijn, Omgevingsverordening 4-week reguliere termijn), role escalations (toezichthouder → BOA → JZ), and cross-case links (handhaving spawned from toezichtbezoek) — are not in any catalog. Tenants either rebuild them from scratch (high cost, drift between municipalities) or copy from a colleague's tenant with no version anchor. There is no immutability-respecting "factory reset" path either: re-running the repair step today would create v2 drafts rather than re-importing the canonical v1.

## Affected Projects

- [ ] Project: `procest` — Adds a `lib/Settings/seed/vth-workflow-templates/*.json` catalog, a repair step that imports the catalog as published `workflowTemplate` v1 objects, an admin-only `POST /api/vth-workflow-templates/import` endpoint, a "Sjablonen-catalogus" tab + per-template detail/preview component, selective per-tenant enable, and a diff view against the shipped canonical version.

## Scope

### In Scope (V1)

- **Catalog of six VTH workflow templates** (REQ-VWT-1..2): JSON files under `lib/Settings/seed/vth-workflow-templates/`, version-pinned to the shipping app version.
- **Seed-data repair step** (REQ-VWT-3): imports the catalog as published `workflowTemplate` v1 objects on first install; idempotent on re-run.
- **Admin-import endpoint** (REQ-VWT-4): `POST /api/vth-workflow-templates/import` for ad-hoc (re-)imports.
- **Catalog UI** (REQ-VWT-5..6): a list view and per-template detail/preview drawer with steps, transitions, deadlines, and roles.
- **Selective enable per tenant** (REQ-VWT-7): a tenant administrator can install only the templates they need.
- **Diff against published version** (REQ-VWT-8): a read-only "what changed since shipped v1?" view that compares the tenant's active version with the canonical catalog version.

### Out of Scope

- **Authoring new templates** — covered by `workflow-definition-model` (PR #347) admin UI.
- **Guard evaluation engine** — owned by `status-transition-engine`.
- **Visual editor** — owned by `visual-workflow-editor` (V2).
- **Template marketplace / cross-tenant share** — Enterprise / V3.
- **Translation of UI labels into FR/EN** — only `nl` and `en` per company i18n policy; FR/DE deferred.

## Approach

1. Author six JSON template files under `lib/Settings/seed/vth-workflow-templates/`, each conforming to the `workflowTemplate` schema in `procest_register.json`.
2. Add a `lib/Migration/SeedVthWorkflowTemplates.php` repair step that loads the catalog, creates one published `workflowTemplate` per file (with `version: 1`, `lifecycleStatus: published`, `isActive: true`), and skips templates already present.
3. Add `VthWorkflowTemplateCatalogController` exposing `GET /api/vth-workflow-templates` (list canonical) and `POST /api/vth-workflow-templates/import` (re-run repair for selected templates).
4. Build `VthWorkflowTemplatesTab.vue` + `VthWorkflowTemplateDetailDialog.vue` for admin settings: catalog list, per-template detail/preview, install/uninstall toggle, diff drawer.

## Cross-Project Dependencies

- **`workflow-definition-model` (procest)**: PR #347 — defines the `workflowTemplate` entity, its lifecycle, and the `WorkflowDefinitionService::publish()` semantics this change relies on.
- **`bezwaar-lifecycle` (procest)**: provides the canonical bezwaar workflow this change cross-links rather than redefining.
- **`role-based-step-routing` (procest)**: consumes `steps[].assigneeRole` and `transitions[].allowedRoles` from the seeded templates.
- **`status-transition-engine` (procest)**: consumes the deadlines and guards encoded in each seeded template.
- **`case-types` (procest)**: VTH templates pin to caseTypes from the VTH seed register (separate `base-register-seed-data` change).
