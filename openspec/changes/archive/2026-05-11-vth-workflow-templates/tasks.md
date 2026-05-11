# Tasks: vth-workflow-templates

## Deduplication Check

- [ ] **D01**: Confirm no `lib/Settings/seed/vth-workflow-templates/` directory or `VthWorkflowTemplateCatalogService` / `VthWorkflowTemplateCatalogController` already exists in `procest`. Confirm `workflow-definition-model` (PR #347) has merged its `WorkflowDefinitionService` (with `createDraft()` + `publish()` + `getActiveDefinitionFor()`) and that the `workflowTemplate` schema in `lib/Settings/procest_register.json` includes the `lifecycleStatus` enum field (T01 of WDM). Confirm the VTH caseTypes (`Omgevingsvergunning`, `Toezichtbezoek`, `Handhaving`, `Klacht`, `Bezwaar`) are seeded by `base-register-seed-data` ahead of this repair step.

---

## Catalog Files

- [ ] **T01**: Create `lib/Settings/seed/vth-workflow-templates/` and author six JSON catalog files, one per template, conforming to the `workflowTemplate` schema in `procest_register.json`:
  - `aanvraag-omgevingsvergunning.json` — 6 statuses, 8-week Awb 4:13 deadline on the `In behandeling → Besluit` transition, escalation role `afdelingsmanager`.
  - `toezichtbezoek.json` — 5 statuses, no statutory deadline, spawn-link to `handhavingstraject` via `automaticActions[].type = "spawnCase"`.
  - `handhavingstraject.json` — 7 statuses (voornemen, zienswijze ontvangen, besluit, bezwaarperiode, in werking, invordering, afgerond), Awb 5:24 deadline on the `Voornemen → Besluit` transition.
  - `bezwaar.json` — VTH-specific cross-link entry pointing at the `bezwaar-lifecycle` workflow with two extra `guards[]` for sectoral non-ontvankelijkheid grounds. Does NOT re-define the bezwaar workflow.
  - `klacht-toezicht.json` — 4 statuses (ontvangen, in behandeling, beoordeeld, afgehandeld), Awb 9:11 6-week deadline.
  - `spoedig-herstel.json` — 4 statuses (geconstateerd, herstel-uitgevoerd, besluit-achteraf, afgehandeld), no pre-deadline, escalation to BOA on `geconstateerd > 4u`.
  Each file uses ISO-8601 durations on `transitions[].deadline.duration` and the slug `caseType` (resolved to UUID at install time).

---

## Backend: Service

- [ ] **T02**: Create `lib/Migration/SeedVthWorkflowTemplates.php` repair step. For each JSON file under `lib/Settings/seed/vth-workflow-templates/`:
  - Resolve `caseType` slug → UUID via `CaseTypeService::findBySlug()`.
  - Resolve every `status` / `fromStatus` / `toStatus` slug to a `statusType` UUID belonging to the resolved caseType. On miss, log warning and SKIP this template (do not partially seed).
  - Generate UUID5s for `steps[].id` and `transitions[].id` from a namespace derived from the template slug, so re-runs are idempotent.
  - Call `WorkflowDefinitionService::createDraft()` then `publish()` to respect the WDM immutability invariant. Never write `workflowTemplate` rows directly.
  - Skip templates whose canonical UUID (UUID5 of slug + caseType) is already present in OpenRegister.
  - Set `caseType.workflowDefinition` to the new template UUID only when the caseType has no pinned definition.
  - Log failures via `$this->logger->error()`; never throw out of the repair step (idempotency requirement).

- [ ] **T03**: Create `lib/Service/VthWorkflowTemplateCatalogService.php` exposing:
  - `listCatalog(): array` — reads the six JSON files, returns canonical metadata (slug, title, caseType, version, statuses count, deadline, installed-yes-no).
  - `getCatalogEntry(string $slug): array` — returns one canonical entry plus its full `steps[]` / `transitions[]` for the detail dialog.
  - `importTemplates(array $slugs): array` — invokes the repair step's per-template path for a subset; returns `{installed: [...], skipped: [...], failed: [...]}`. Admin-only via `IGroupManager`. Logs failures; never returns raw exception messages.
  - `diffAgainstCanonical(string $caseTypeId): array` — returns `{added, removed, modified}` arrays describing differences between the tenant's active `workflowTemplate` and the canonical v1.
  Identity from `IUserSession`; logging via `$this->logger->error()`; static error strings only.

---

## Backend: Controller

- [ ] **T03b**: Create `lib/Controller/VthWorkflowTemplateCatalogController.php` exposing:
  - `GET    /api/vth-workflow-templates` — list canonical catalog with installed-yes-no per template.
  - `GET    /api/vth-workflow-templates/{slug}` — read canonical detail for one template.
  - `POST   /api/vth-workflow-templates/import` — body `{ slugs: [...] }`; admin-only; re-runs the repair step for the selected templates. Returns `{installed, skipped, failed}`.
  - `GET    /api/vth-workflow-templates/{slug}/diff` — diff canonical v1 vs tenant's active version.
  No raw exception messages — static error strings; `$this->logger->error()` for internals.

---

## Routes

- [ ] **T04**: Register the four endpoints from T03b in `appinfo/routes.php` with PHP controller name `vth_workflow_template_catalog` and kebab-case URL `/api/vth-workflow-templates[/...]`.

---

## Frontend: Store + API

- [ ] **T04b**: Register a `vth-workflow-template` entity type in `src/store/store.js` via `createObjectStore('vth-workflow-template')`. Register ONCE. Add `src/services/vthWorkflowTemplateCatalogApi.js` with calls `listCatalog`, `getCatalogEntry`, `importTemplates`, `diffAgainstCanonical`. Use `@nextcloud/axios` exclusively.

---

## Frontend: Catalog UI

- [ ] **T05**: Create `src/views/settings/components/VthWorkflowTemplatesTab.vue`:
  - Imports only from `@conduction/nextcloud-vue`.
  - Table of canonical catalog: title, caseType, status count, deadline, installed badge (`CnStatusBadge`), action column (`Install` | `Open detail`).
  - Empty state via `CnEmptyState` ("Geen sjablonen beschikbaar — controleer of base-register-seed-data is uitgevoerd").
  - All strings via `this.t(appName, '...')`; SPDX header on line 1; nl + en translations only.

- [ ] **T06**: Create `src/views/settings/components/VthWorkflowTemplateDetailDialog.vue` (read-only preview + diff):
  - Tabs: `Steps`, `Transitions`, `Deadlines`, `Diff vs canonical v1`.
  - `Steps` and `Transitions` tabs reuse the read-only mode of `WorkflowStepsEditor` / `WorkflowTransitionsEditor` from `workflow-definition-model`.
  - `Deadlines` tab: a table of `transitions[].deadline.{duration, source, escalationRole}` rendered as human-readable rows (e.g., "8 weken (Awb 4:13) → afdelingsmanager").
  - `Diff` tab: calls `diffAgainstCanonical`; renders `{added, removed, modified}` as three lists; read-only — no revert action.
  - Footer actions: `Installeren` (if not installed) or `Klonen voor wijziging` (if installed; defers to WDM admin UI). Confirmation via `CnDialog`, never `window.confirm`.
  - SPDX header; strings via `this.t(appName, '...')`.

- [ ] **T07**: Selective install: the catalog table supports multi-select rows + a `Installeer geselecteerde` toolbar action that calls `importTemplates({slugs: [...]})` and refreshes the catalog list. Per-row install action available too. Uninstall is OFFERED ONLY when the template's active version has zero open cases bound to it; otherwise the action shows `Deprecate` and links into the WDM admin tab.

---

## Verification

- [ ] **V01**: `grep -rL 'SPDX-License-Identifier' lib/Migration/SeedVthWorkflowTemplates.php lib/Service/VthWorkflowTemplateCatalogService.php lib/Controller/VthWorkflowTemplateCatalogController.php src/views/settings/components/VthWorkflowTemplatesTab.vue src/views/settings/components/VthWorkflowTemplateDetailDialog.vue src/services/vthWorkflowTemplateCatalogApi.js` → zero results.

- [ ] **V02**: `grep -rn 'findObject\|saveObject\|findObjects\|deleteObject' lib/Migration/SeedVthWorkflowTemplates.php lib/Service/VthWorkflowTemplateCatalogService.php` → every call uses the 3-positional-arg API; AND every mutation of a `workflowTemplate` goes through `WorkflowDefinitionService` (no direct ObjectService writes to `workflowTemplate`).

- [ ] **V03**: `grep -rn 'getMessage()' lib/Controller/VthWorkflowTemplateCatalogController.php` → zero results. No raw exception messages in responses.

- [ ] **V04**: Manual QA — Fresh install: repair step seeds all six templates as `published`+`isActive`. Re-running the repair step is a no-op (idempotent). Catalog tab lists all six with `installed`. Selecting `klacht-toezicht` only on a fresh install seeds just that one. Opening detail for `aanvraag-omgevingsvergunning` shows the 8-week Awb 4:13 deadline in the `Deadlines` tab. After admin clones v1 and publishes v2 via the WDM UI, the catalog `Diff` tab shows the structural changes between the two.
