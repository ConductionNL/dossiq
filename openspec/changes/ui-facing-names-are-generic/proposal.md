# ui-facing-names-are-generic

## Why

Code is generic and named by what it does, never by a procedure (Ruben, decision 182). The audit of the dossiq UI
(dq-abstract, lane L7) found procedure words in what the UI calls and names: `/api/vth/checklists`,
`/api/termijn/reports/kwartaal`, `/api/woo/reports/throughput`, widget ids such as `dt-woo` and `td-quarterly`, the
`WooReports` page, report keys such as `binnenTermijnPct`, analytics series keys starting `procest-sla-`, and settings
files named Termijn, Mandaat and Inspection. Each of those names is also stored or called by someone else, so a rename
must keep old users working.

The backend rename (the woo, bezwaar and termijn routes and lib files the UI does not call) is a separate programme
(Ruben, 20 Oct 2026). The NC Dashboard widget ids `procest_*` stay frozen.

## What changes

- Generic URLs for the checklist templates and checklist results, the deadline reports, definitions, calendar and
  instance verbs, the payment callback and the document review throughput report. Every old URL stays routed as an alias
  to the same behaviour; the payment callback's old URL is permanent, because it is configured at the payment provider.
- The deadline reports carry generic keys beside the old ones for one release.
- The processing-time series are published under `dossiq-sla-*` and, for one release, under `procest-sla-*`.
- Widget, page and menu ids are renamed; a repair step rewrites them in stored buildiq arrangements.
- The document review report settings move to generic keys, read with a fallback to the old keys and carried over by a
  repair step.
- The `WooReports` page becomes `DocumentReviewReports` at `/document-review-reports`; `/woo-reports` redirects to it.
- Settings components are renamed (DeadlineDefinitionsTab, DeadlineDefinitionEditor, DelegationMatrixTab,
  DelegationImportPanel, DelegationMatrixTable, RoleAssignmentsTable, RoleTreeManager, ChecklistTemplatesTab). The role
  tree is nextcloud-vue's `CnTreeView`; the checklist template editor is `CnFormDialog` with the items as sub-objects.
