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
- On CaseTypeDetail the Statuses and Fields lists open those pages on a row
  click and offer Delete as a row action; the Fields list groups by category.
- The Case type card gains subject, handlerAction and publicationRequired;
  the Terms card gains maxExtensions and the queue thresholds.

Not in this change, because they need a decision: the status checklist and
field-rules editors, the field type vocabulary, results, roles, document and
decision types on the page, the workflow editor, email template versioning
and the case-type delete guard.
