# Design: vth-workflow-templates

## Architecture Overview

Six VTH workflow templates ship as JSON files under `lib/Settings/seed/vth-workflow-templates/`. A repair step imports them as published `workflowTemplate` v1 objects on first install. An admin "Sjablonen-catalogus" tab lists the canonical catalog, surfaces a per-template preview, lets the tenant administrator selectively enable templates, and shows a diff between the tenant's active version and the canonical shipped version.

```
AdminSettings.vue
└── VthWorkflowTemplatesTab.vue (catalog list)
    └── VthWorkflowTemplateDetailDialog.vue
        ├── StepsPreview (read-only WorkflowStepsEditor)
        ├── TransitionsPreview (read-only WorkflowTransitionsEditor)
        ├── DeadlinesPreview (table of statutory deadlines)
        └── DiffView (canonical vs active)

Backend
├── lib/Settings/seed/vth-workflow-templates/
│   ├── aanvraag-omgevingsvergunning.json
│   ├── toezichtbezoek.json
│   ├── handhavingstraject.json
│   ├── bezwaar.json   (cross-link stub → bezwaar-lifecycle)
│   ├── klacht-toezicht.json
│   └── spoedig-herstel.json
├── VthWorkflowTemplateCatalogService.php (catalog read + selective import)
├── VthWorkflowTemplateCatalogController.php (REST)
└── Migration/SeedVthWorkflowTemplates.php (repair step)
```

## Template Catalog

| Slug | Title (nl) | CaseType | Statuses (count) | Statutory Deadline | Primary Role | Notes |
|------|-----------|---------|------------------|--------------------|--------------|-------|
| `aanvraag-omgevingsvergunning` | Aanvraag omgevingsvergunning | Omgevingsvergunning (reguliere procedure) | 6 | Awb 4:13 — 8 weken (verlengbaar 6) | Vergunningverlener | Lex silencio positivo bij reguliere procedure |
| `toezichtbezoek` | Toezichtbezoek | Toezichtbezoek | 5 | — (geen wettelijke beslistermijn) | Toezichthouder | Spawnt `handhavingstraject` bij overtreding |
| `handhavingstraject` | Handhavingstraject | Handhaving | 7 | Awb 5:24 — redelijke termijn last onder dwangsom | Handhavingsjurist | Voornemen → zienswijze → besluit → invordering |
| `bezwaar` | Bezwaar (VTH-context) | Bezwaar | 8 | Awb 7:10 — 6 weken (verlengbaar 6) | Behandelaar bezwaar | Cross-link to `bezwaar-lifecycle` — this template only adds VTH-specific guards |
| `klacht-toezicht` | Klacht over toezichthouder | Klacht | 4 | Awb 9:11 — 6 weken | Klachtbehandelaar | Internal complaint about a toezichthouder's conduct |
| `spoedig-herstel` | Spoedig herstel / spoedeisende bestuursdwang | Handhaving (spoed) | 4 | — (onmiddellijk) | BOA / Toezichthouder | Awb 5:31 — bestuursdwang zonder voorafgaande last; achteraf besluit |

## Per-Template Structure

Each JSON file produces one `workflowTemplate` object conforming to the schema in `procest_register.json`. The structure of every template:

```jsonc
{
  "title": "Aanvraag omgevingsvergunning",
  "description": "Reguliere procedure conform Omgevingswet ...",
  "caseType": "<UUID — resolved against the VTH base-register seed at install>",
  "version": 1,
  "lifecycleStatus": "published",
  "isActive": true,
  "isDraft": false,
  "steps": [
    {
      "id": "<uuid>",
      "title": "Volledigheidstoets",
      "status": "<statusType-uuid>",
      "order": 1,
      "assigneeRole": "vergunningverlener",
      "isRequired": true,
      "checklist": [
        { "label": "Aanvraagformulier compleet", "required": true },
        { "label": "Bouwtekening conform NEN 2580", "required": true }
      ],
      "automaticActions": []
    }
  ],
  "transitions": [
    {
      "id": "<uuid>",
      "fromStatus": "ontvangen",
      "toStatus": "in-behandeling",
      "label": "Start beoordeling",
      "guards": [
        { "type": "checklist", "checklistId": "volledigheidstoets" },
        { "type": "roleGuard", "role": "vergunningverlener" }
      ],
      "allowedRoles": ["vergunningverlener"],
      "automaticActions": [],
      "deadline": { "duration": "P56D", "source": "Awb 4:13", "escalationRole": "afdelingsmanager" }
    }
  ]
}
```

Per-template deadlines map to a `transitions[].deadline` block with `duration` (ISO-8601), `source` (Awb / Omgevingswet article), and `escalationRole` for when the deadline elapses. Escalations are surfaced by `status-transition-engine` at runtime but encoded in the template here.

## Seed-Data Delivery via Repair Step

`lib/Migration/SeedVthWorkflowTemplates.php` runs on app upgrade. For each JSON file in `lib/Settings/seed/vth-workflow-templates/`:

1. Resolve `caseType` slug → UUID via the VTH base register (must already exist from `base-register-seed-data`).
2. Resolve `statusType` slugs in `steps[].status`, `transitions[].fromStatus`, and `transitions[].toStatus` against the caseType's defined statuses.
3. Generate stable UUIDs for `steps[].id` and `transitions[].id` using a UUID5 namespace derived from the template slug — this keeps re-runs idempotent.
4. Call `WorkflowDefinitionService::createDraft()` then `publish()` so the immutability invariant from `workflow-definition-model` REQ-WDM-4 is honoured. Skips templates whose canonical UUID (UUID5 of the slug + caseType) already exists.
5. Set `caseType.workflowDefinition` to the new template only if the caseType has no pinned definition yet.

## Immutability + Versioning Contract

The catalog ships exactly one canonical version per template — `version: 1` — published as `lifecycleStatus: published`. Because `workflow-definition-model` makes published versions immutable, tenant administrators who want to deviate MUST `clone()` the seeded v1 into a new draft (per WDM lifecycle), customise it, and `publish()` it as v2. The seeded v1 remains as a deprecated-but-readable reference. The catalog therefore acts as a "reset to factory" anchor: the tenant can always diff their active version against the immutable v1.

Re-running the repair step is safe: it re-imports only templates whose canonical UUID is not present. It NEVER overwrites a tenant's existing `workflowTemplate` — that would violate the immutability invariant of `workflow-definition-model`.

## Selective Enable per Tenant

The catalog list view shows `Installed` / `Not installed` per template. A tenant administrator may install only the templates relevant to their service portfolio (e.g., a water authority installs `toezichtbezoek` + `handhavingstraject` but not `aanvraag-omgevingsvergunning`). Uninstall is offered only when the template has no open cases bound to it; otherwise the action is replaced with a `Deprecate` button that defers to the WDM lifecycle.

## Diff Against Published Version

For each installed template, the detail dialog computes a structural diff between the tenant's currently active version (which may be v1, v2, v3 …) and the catalog's canonical v1. Changes are listed by type (`steps.added`, `steps.removed`, `transitions.modified`, `deadline.changed`) with a short human-readable summary. The diff is read-only — no "revert" action; the tenant must clone the canonical v1 into a new draft via the WDM admin UI if they want to restore.

## Integration with Other Specs

- `workflow-definition-model`: this change calls only its public service API (`createDraft`, `publish`, `getActiveDefinitionFor`) — never mutates `workflowTemplate` rows directly.
- `bezwaar-lifecycle`: the `bezwaar` catalog entry references the bezwaar workflow already shipped by `workflow-definition-model` REQ-WDM-9; we add only VTH-specific guards (sectoral grounds for non-ontvankelijkheid).
- `status-transition-engine`: consumes the new `transitions[].deadline` block to drive deadline countdowns and escalations.
- `role-based-step-routing`: consumes `assigneeRole` and `allowedRoles` on the seeded templates without modification.
