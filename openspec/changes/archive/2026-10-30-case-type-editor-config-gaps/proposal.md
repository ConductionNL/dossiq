# The manifest case-type pages edit what the admin editor did

## Why

The admin-settings case-type editor is being retired; the manifest
CaseTypes and CaseTypeDetail pages are the editor. Part of what the old
editor did is closable with configuration: editing and deleting a status or
a field of the type, fields grouped by category, and general fields that
were reachable only through the whole-record Edit dialog.

## What changes

- New pages StatusTypeDetail (`/settings/status-types/:id`) and
  PropertyDefinitionDetail (`/settings/attributes/:id`), schema-bound detail
  pages with one data card.
- ResultTypeDetail, RoleTypeDetail, DocumentTypeDetail and DecisionTypeDetail
  pages, and a list of each on CaseTypeDetail: Results under Statuses, Roles
  under Rights, Document and Decision types under Phases and tasks.
- Every part list opens its page on a row click and offers Delete as a row
  action; the Fields list groups by category.
- isDraft is no longer editable on the Case type card: a published type gets
  a new version or is deprecated, never unpublished.
- The Case type card gains subject, handlerAction and publicationRequired;
  the Terms card gains maxExtensions and the queue thresholds.

Not in this change, because they need a decision: the status checklist and
field-rules editors, the field type vocabulary, the workflow editor, email template versioning
and the case-type delete guard.
