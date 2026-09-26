# Tasks: dependent-term-follows-predecessor

Tier: V1. Kind: code. Row Q3.21.

- [x] 1.1 `lib/Service/CaseRelationService.php`: `waitsOn` with inverse
  `blocks`; unit tests for both directions.
  - `@spec openspec/changes/dependent-term-follows-predecessor/specs/related-case-linking/spec.md`
- [x] 1.2 `lib/Listener/DependentTermListener.php` on the `deadlineEvent`
  create, deferred; bounded dependents query; one engine task per dependent
  (D-2). Registered by schema in `ObjectListenerRegistrar`.
  - unit: two dependents get two tasks; a case without dependents gets none
- [x] 1.3 The task action: accept calls `DeadlineExtensionService::extend()`
  with reason and source; decline completes the task.
  - unit: accepted, declined, refused at ceiling
- [x] 2.1 `src/manifest.json` `#CaseDetail` Related panel: Waits on and
  Blocks. No section of their own: the panel groups by the label OpenRegister
  answers per direction, so the declared pair is what makes both appear, and a
  hand-written section would list the same links twice. Recorded in the
  panel's note.
- [x] 3.1 `tests/e2e/dependent-term.spec.ts`; `openspec validate
  dependent-term-follows-predecessor --strict`.
- [ ] 4.1 [blocked: openregister `relation-types-with-inverses`] move the
  pair onto the relation primitive and drop it from `relatedCases` (D-4).
