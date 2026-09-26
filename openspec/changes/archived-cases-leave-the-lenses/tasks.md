# Tasks: archived-cases-leave-the-lenses

Tier: V1. Kind: config. Row Q2.33. Built on openregister
`object-archive-state` (#3772, on openregister `development`).

- [x] 1.1 `src/manifest.json` `#CaseDetail`: Archive and Restore, offered on
  a final status and on the archive marker (D-2, D-5).
  - Archive already lived in the Lifecycle menu (`/acts`, refused on a case
    that has not ended). Restore takes its place there once `/acts` answers
    `archived: true`, read off `@self.archived`; never both
    (`src/utils/caseActsMenu.js`, `POST /api/case/{caseId}/unarchive`).
  - The manifest spells the condition `visibleWhen`, not `visibleIf`: an
    action is a closed object in the manifest schema and `visibleIf` is the
    menu's word.
  - `tests/vitest/caseActionsMenu.spec.js`
  - `@spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md`
- [x] 1.2 `lib/Settings/dossiq_register.json`: archiving writes
  `case.archiveStatus` beside the platform marker, and reading asks the
  marker (D-1). The ZGW delete guard is untouched.
  - The case schema declares `x-openregister-archive: {enabled: true}`, and
    the key joins `SchemaSlugMap::SCHEMA_ANNOTATION_KEYS`.
  - `CaseArchiveState` writes and clears the marker through openregister's
    `ArchiveHandler`; `CaseEndingActs::archive()` writes the field first and
    the marker last, `unarchive()` the other way round.
  - The stored value is `archived`, not `gearchiveerd`: the enum already
    holds `archived`, and `LoadDefaultZgwMappings` publishes it as
    `gearchiveerd` over ZGW. Restore writes `nog_te_archiveren`.
  - `tests/Unit/Service/CaseArchiveStateTest.php`,
    `tests/Unit/Service/CaseEndingActsTest.php`
- [x] 2.1 `#Cases` and `#Queue` lenses exclude archived cases; an Archived
  lens shows them; `#MyWorkHome` tiles and the case search take the same
  default (D-3).
  - One chip, `Archived`, carrying `_archived: true`. No working lens spells
    the exclusion: the platform's list, aggregation and search default does.
  - `tests/vitest/caseListLenses.spec.js`
- [x] 2.2 An archived case renders without edit affordances and shows the
  platform's refusal when a write is attempted (D-4).
  - Six write header actions are gated on `@self.archived`; the
    `case-archived` strip names the archive, who and when.
  - Open: the generic Edit button stays, because `showEditAction` is a
    page-level boolean. Saving is refused by the platform with its sentence.
    The per-record form belongs in nextcloud-vue.
- [x] 3.1 `tests/e2e/archived-cases-leave-the-lenses.spec.ts`;
  `openspec validate archived-cases-leave-the-lenses --strict`.
