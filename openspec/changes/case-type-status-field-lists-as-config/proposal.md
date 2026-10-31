# Proposal: statuses and fields of a case type as manifest config

## Why

The case type page shows its statuses and its fields through two widgets rehosted from the retired admin editor (`CaseTypeStatusesEditorWidget`, `CaseTypeFieldsEditorWidget`), which wrap the legacy `StatusesTab` and `PropertiesTab`. Every other part of the page is manifest config over library widgets. nextcloud-vue can now edit such lists from config, so the two editors and the legacy tabs they wrap can go.

## What changes

- **Statuses:** `case-type-statuses-editor` becomes an object list of this case type's statuses.
  - It reorders by `order`, writing only that field on the rows that changed.
  - Edit uses the same form as Add, and saves only the keys it changed, so `capacity`, `phaseTermDays` and `phaseTermShare` survive as they did after 07560b78b.
  - `order` is unique among the type's statuses.
  - The checklist, field rules and derived conditions are list editors inside the form, with an optional "Only in some cases" condition.
  - Colours show their swatch, and the public label shows the name as its placeholder.
- **Fields:** `case-type-fields-editor` becomes an object list of the type's own fields.
  - The type comes from OpenRegister's property vocabulary, grouped by category.
  - The format follows the type, and is left out when the type has none.
  - Constraints show only for the types they apply to.
  - A "Choice list" switch that is not stored shows the choices, one per line. The choices are required unless a concept scheme is set, and a note says the scheme wins when both are.
  - `requiredAtStatus` offers this type's statuses.
- **Removed:**
  - the two widgets and their registry entries and slots;
  - the simple profile's type patches for them;
  - `StatusesTab`, `PropertiesTab`, `StatusTypeForm` and `utils/statusTypeForm.js`, with their specs.
- The server keeps the guard that a case type keeps one final status. The list and the dialog show its refusal.

## Depends on

nextcloud-vue: `feat/object-list-edit-in-place` (bb897a16bb) and the branches it holds:
- `form-field-options-from`
- `forms-save-edited-keys`
- `forms-dotted-path-fields`
- `sub-objects-editor`
- `list-reorder-order-field`
- `form-small-features`

Until a library release carries them, the lists render with the keys the installed library knows.

## Not carried over

- **`items.type` for an array field:** the schema's `items` has no properties to bind a field to.
- **Unknown stored type:** it is no longer shown read-only.
- **The e2e specs that drove the legacy tabs**, which need their selectors moved to the list after the release:
  - `case-type-status-authoring`
  - `code-lists-from-concepts`
  - `attribute-catalogue-folders`
