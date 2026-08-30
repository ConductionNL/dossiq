# Proposal: zaaktype-copy

kind: code -- adds a "duplicate case type" action so admins can clone an
existing zaaktype definition into a new draft instead of re-entering every
field, status, and sub-object by hand.

## Why

Copy/duplicate of a case type is one of the most requested functional-admin
capabilities across zaaksysteem-family issue trackers: open-zaak#693 asks
for a "kopieer zaaktype" action, and open-zaak#517 asks for the ability to
delete a zaaktype that is still a draft. Procest's case-type editor
(`CaseTypeDetail.vue`, ten tabs: general, statuses, results, roles,
properties, documents, decisions, sub-cases, workflow, email) makes building
a new zaaktype from scratch expensive whenever it is a small variation on an
existing one (e.g. "Omgevingsvergunning regulier" -> "Omgevingsvergunning
uitgebreid"). Admins currently have no way to start from an existing
definition; they either use the (JSON template) template library or type
everything again.

Deleting a draft case type already works today, but with no status guard:
`CaseTypeList.vue::confirmDelete()` deletes both draft AND published case
types through the generic OpenRegister object API, blocked only by an
active-case check. This proposal also tightens that: a case type MUST be a
draft to be deleted; a published one requires the admin to unpublish first
(409 Conflict), matching the "safe to delete a draft, not a live type"
expectation from open-zaak#517.

## What Changes

- **Backend copy** -- `POST /api/case-definitions/{id}/copy` deep-copies a
  case type: new UUID, title prefixed `Copy of `, `isDraft` forced `true`,
  `publicationRequired`/`publicationText` cleared, `workflowDefinition`
  unset (no inherited pinned workflow version), `relatedCaseTypes` /
  `subCaseTypes` cleared (a duplicate does not inherit the source's sibling
  links), and every owned sub-object (`statusType`, `resultType`,
  `roleType`, `propertyDefinition`, `documentType`, `decisionType`) copied
  and re-pointed at the new case type's id. Reuses the same
  `SettingsService`-resolved register/schema + `ObjectService::findAll`/
  `saveObject` pattern already used by `TemplateLibraryService` and
  `BesluitvormingTemplateService` -- one write path per object, no new
  storage mechanism.
- **Backend guarded delete** -- `DELETE /api/case-definitions/{id}` deletes
  a case type only when `isDraft === true`; returns 404 when the id does
  not resolve and 409 when the case type is published. `CaseTypeList.vue`'s
  cascade-delete flow (status types first, then the case type) is rewired
  to call this endpoint for the final case-type removal instead of the raw
  generic `objectStore.deleteObject()`.
- **Frontend** -- a "Duplicate" action next to "Delete" in
  `CaseTypeList.vue` row actions, and a "Duplicate" button in
  `CaseTypeDetail.vue`'s header actions (existing case types only). Both
  navigate to the newly created draft on success, reusing the existing
  `@select` / `@saved`-style navigation already wired through
  `CaseTypeAdmin.vue`.
- **Tests** -- PHPUnit unit coverage for the copy service (deep copy shape,
  sub-object re-parenting, 404 on missing source) and the guarded delete
  (404, 409, happy path), plus controller tests for both routes.

## Capabilities

### New Capabilities

- `zaaktype-copy`: deep-copy an existing case type definition (and its
  owned sub-objects) into a new draft; guard case-type deletion to
  draft-status definitions only.

## Impact

- **Backend**: `lib/Service/CaseTypeCopyService.php` (new),
  `lib/Controller/CaseDefinitionController.php` (modified: `copy()`,
  `delete()`), `appinfo/routes.php` (2 new routes),
  `tests/Unit/Service/CaseTypeCopyServiceTest.php` (new),
  `tests/Unit/Controller/CaseDefinitionControllerTest.php` (new).
- **Frontend**: `src/views/settings/CaseTypeList.vue` (Duplicate action,
  delete rewired to the guarded endpoint), `src/views/settings/
  CaseTypeDetail.vue` (Duplicate action), `src/views/settings/
  CaseTypeAdmin.vue` (navigate to the new draft after duplicate).
- **Out of scope**: copying `emailTemplate` records, workflow definition
  versions, and sub-case-type / related-case-type links -- these carry
  cross-object or versioned-lifecycle semantics that a straight duplicate
  should not blindly inherit (documented as explicit non-goals, not gaps).
