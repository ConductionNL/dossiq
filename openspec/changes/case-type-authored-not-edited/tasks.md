# Tasks: case-type-authored-not-edited

Tier: MVP and V1. Kind: code.

## 1. A status is authored, not hand-edited

- [x] 1.1 Add `src/utils/statusTypeForm.js`: the role list, an empty form, the
  checklist item shape, `pruneChecklist`, and the `statusTypeToForm` /
  `formToStatusType` pair.
  - a stored row opens with unset properties unset, never guessed
  - the payload carries only properties the schema declares
  - unit test `tests/vitest/statusTypeForm.spec.js`
- [x] 1.2 Add `src/views/settings/components/StatusTypeForm.vue`: name, order,
  description, colour, role, final, hidden in lists, checklist.
  - `inputLabel` on every `NcSelect` (ADR-004)
  - one form component, used by both the add and the edit path
- [x] 1.3 `StatusesTab.vue` uses it, shows the colour swatch, the role, the
  hidden badge and the checklist count on each row, and drops the
  `notifyInitiator` and `notificationText` controls no schema declares.
- [x] 1.4 The reorder path writes through the same mapping, so dragging a
  status does not drop its colour, role or checklist.

## 2. A case type has versions

- [x] 2.1 `lib/Settings/dossiq_register.json`: `caseType` 1.6.0 to 1.7.0 with
  `version`, `previousVersion` and `supersededBy`; register 0.15.0 to 0.16.0.
- [x] 2.2 `CaseTypeCopyService::newVersion()`: same title and identifier, one
  version on, linked back, draft, children copied.
  - unit tests including "the succeeded version is not written to"
- [x] 2.3 `CaseTypeCopyService`: repoint `initialStatus` at the new type's own
  copy of that status, on both the copy and the version path.
- [x] 2.4 `CaseTypePublishService::publish()`: write `supersededBy` onto the
  previous version, stamp version 1 on a type that has none.
  - a chain pointing at itself closes nothing
  - an unreadable previous version does not fail the publish
- [x] 2.5 `POST /api/case-definitions/{id}/new-version` on
  `CaseDefinitionController`, admin-guarded like `copy`.
- [x] 2.6 `CaseTypeDetail.vue`: a version label, a New version action on a
  published current version, the superseded notice, and the running-cases
  warning that replaces the count that could only ever read 0 or 1.

## 3. One rule for which version is offered

- [x] 3.1 `isCurrentCaseTypeVersion` in `src/utils/caseValidation.js`, folded
  into `isCaseTypeUsable` and `getCaseTypeUnusableReason`.
  - unit test `tests/vitest/caseTypeVersion.spec.js`
- [x] 3.2 `StartCaseWidget` filters through `isCaseTypeUsable` instead of
  carrying its own half of the rule.
- [x] 3.3 `CaseTypeDetail` publishes through `CaseTypePublishDialog` and the
  server endpoint; the browser-side `validateForPublish` is removed rather than
  left unused, because it gives a different answer on a case type that inherits
  its lifecycle from a parent.

## 4. Checks

- [x] 4.1 `npm run lint`, `check:vue3-compile`, `test:l10n`, `check:l10n-js`,
  `check:schema-l10n`, `check:manifest` green by exit code.
- [x] 4.2 Dutch translations for every new string in `l10n/nl.json`.
- [x] 4.3 `composer check:strict` and the affected PHPUnit suites green by exit
  code.
- [x] 4.4 Every new guard mutation-checked: the assertion was watched to fail
  with the behaviour removed, then restored.
