# Retire the admin-settings case type editor

## Why

dossiq had two case type editors: the manifest Case types and CaseTypeDetail
pages, and a legacy editor in the Nextcloud admin settings (CaseTypeAdmin,
CaseTypeList, CaseTypeDetail and its tabs). Ruben decided the manifest pages
are the editor.

## What changes

- Rehosted on CaseTypeDetail as widgets, because no declared widget can
  express them yet: the workflow canvas (Phases and tasks), the statuses
  editor with checklist and field rules (Statuses), the fields editor against
  the property vocabulary and the case schema rules (Fields), and the
  messages editor with variables, preview and versioning (Phases and tasks).
  The rules are a candidate to move to OpenRegister's schema page.
- Removed: CaseTypeAdmin, CaseTypeList, the settings CaseTypeDetail, the
  General, Results, Roles, Document types, Decision types and Sub-case types
  tabs, DurationPicker, caseTypeColumns, caseTypeValidation,
  nextcloudGroupsApi, their translations and tests; the admin section now
  links to the Case types page.
- Removed endpoints only the legacy editor called: DELETE
  /api/case-definitions/{id} (CaseTypeDeleteGuard now guards every delete)
  and POST /api/casetypes/{id}/email-templates/seed-defaults (no caller).
- Dropped the default case type star and the `default_case_type` config key.
- WorkflowDefinitionDetail: Publish, Deprecate and Clone call
  /api/workflow-definitions/{id}/publish|deprecate|clone.
- first_run_readiness points at the Case types page.

No stored data changes: a stored `default_case_type` value stays and is no
longer read.
