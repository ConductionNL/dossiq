# Tasks: case-actions-menu

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. Copy a case

- [x] 1.1 Add `lib/Service/CaseCopyService.php` with `copy(string $caseId,
  array $options): array` per design D1: fixed field list, initial status,
  `relatedCases` to the source, documents linked by reference when asked.
  - SPDX header; `@spec openspec/specs/case-management/spec.md`
  - unit test `tests/Unit/Service/CaseCopyServiceTest.php` asserting the
    fields that are never copied stay empty
- [x] 1.2 Add `lib/Controller/CaseActionsController.php` with `copy`,
  `startableFlows` and `plan`; register `POST /api/case/{caseId}/copy`,
  `GET /api/case/{caseId}/startable-flows` and `POST /api/case/{caseId}/plan`
  in `appinfo/routes.php`.
  - `#[NoAdminRequired]` plus a per-case guard on every method, 403 on refusal
  - unit test `tests/Unit/Controller/CaseActionsControllerTest.php` covering
    the refused path first
- [x] 1.3 `src/manifest.json` page `CaseDetail`: header action `copy-case`
  (type `handler`); bind the handler in `src/customComponents.js` to open
  `CnCopyDialog` and navigate to the new case.
- [ ] 1.4 [blocked: nextcloud-vue a `copy` header action type over
  `CnCopyDialog` taking a field list and an endpoint] Replace the handler with
  the declaration; 1.3 is the interim.

## 2. Start a flow

- [x] 2.1 `lib/Settings/dossiq_register.json`: property `startableFlows` on
  `caseType` (array of `$ref` to `flow`); seed the shipped sub-case and letter
  flows on the demo types.
  - DONE as an array of plain strings, NOT `$ref`. A `$ref` addresses a schema
    in a register; a flow is a row in `oc_openregister_flows` read through
    `FlowService`, and there is no `flow` schema for a `$ref` to name.
  - The case gained `hasStartableFlows`, a materialised boolean over
    `@ref.caseType.startableFlows`, because the Start entry has to be HIDDEN on
    a type that lists none and an action's local `visibleWhen` can only see the
    case record. Both schema versions were bumped: OpenRegister fast-skips a
    schema whose version did not move.
  - NOT DONE, and it cannot be: dossiq ships no sub-case flow and no letter
    flow. It ships exactly two flows, `Case behandeling` on `case` and one on
    `bacAdviceRequest`, and `dossiq.createSubCase` is a flow NODE, not a flow.
    A flow uuid is minted per install, so a seed file cannot name one either.
    An administrator or the e2e fixture sets `startableFlows` on a case type;
    resolving dossiq's own shipped flows onto the demo types at install time is
    a repair step, and a repair step runs at `occ upgrade` and nowhere else, so
    it is not shipped unverified here.
- [x] 2.2 Add `src/dialogs/CaseStartFlowDialog.vue`: lists the flows
  from `startable-flows`, posts the chosen one to OpenRegister's run endpoint
  with the case as subject, closes on success. Header action `start-flow`
  (type `open-modal`) on `CaseDetail`.
  - The dialog lives in `src/dialogs/` and not `src/components/case/`, because
    the modal-isolation rule puts an NcDialog-based component there.
  - `open-modal`, not `handler`: a handler action resolves against
    `effectiveManifest.actions`, a JSON map that cannot hold a function.
  - The entry is hidden by the case's `hasStartableFlows`; the dialog still
    says so when the list is empty, because the gate is a save-time value and
    a case type edited since the last case save is not recomputed yet.
- [ ] 2.3 [blocked: openregister a `userStartable` flag on the `flow` schema]
  Read the flag instead of `caseType.startableFlows`; 2.1 is the interim.

## 3. Plan a follow-up

- [x] 3.1 Add `src/dialogs/CasePlanFollowUpDialog.vue` (case type,
  date, title) and the `plan` controller method writing one flow with a
  `TriggerScheduleNode` (`runAs` set, once) and a `DossiqTxCreateSubCaseNode`.
  - unit test asserts `runAs` is set and the trigger is single-shot
  - THE ENGINE HAS NO ONE-SHOT TRIGGER, and five cron fields cannot say
    "once". The cron pins minute, hour, day and month, which names one minute
    of one day of one month, and that minute comes round again next year. So
    single-shot is kept by `PlannedFollowUpSweepJob`, which switches the flow
    off once it has fired, and the unit test asserts the pinning rather than a
    promise the expression cannot make.
  - The flow is written disabled, published, then enabled: a run is refused
    unless a published, sound version exists, so a flow enabled before it is
    published would be armed and unrunnable.
  - The earliest date is tomorrow. A follow-up planned for today would fire in
    a few hours or not until next year depending on the clock.
- [x] 3.2 Add `src/components/case/CasePlannedWidget.vue` as a widget under
  the Related cases tab, listing unfired planned flows for this case; header
  action `plan-follow-up` on `CaseDetail`.
  - NOT a `custom` widget, and not a separate `case-planned` tab child. A
    `type: "custom"` widget resolves through the page's `widget-<id>` slot,
    which CnDetailPage renders per LAYOUT grid item only; a tab child has no
    grid item, and CnTabsWidget resolves a child through
    `cnRegistry[widget.type]` and renders NOTHING, silently, when no key
    answers. And a tab entry names ONE widgetId, so "under the same tab" is
    not something two widgets can be.
  - So the existing `case-related` widget is RETYPED to
    `case-related-planned` and rendered by CasePlannedWidget, which wraps the
    library's own CnRelatedObjectsWidget and hands it the planned rows as an
    `extraSections` group. The id, the title, the icon and the tab entry are
    unchanged, and the built-in related content is untouched.
- [ ] 3.3 [blocked: openregister the `related` widget listing scheduled flows
  by subject] Delete `CasePlannedWidget.vue`; 3.2 is the interim.

## 4. Verification

- [x] 4.1 Add `tests/e2e/case-actions-menu.spec.ts` covering every scenario
  of the two delta specs that names it (copy with and without documents, the
  Start list, a run appearing in `case-flow-runs`, a planned row on Related
  cases).
  - Two scenarios were re-annotated `@e2e exclude` rather than written as
    tests that cannot fail. "A reader cannot copy": Playwright signs in as
    admin and cannot take a lesser role, and PHPUnit asserts the guard runs
    before the service. "A run appears on the case": running a flow needs an
    ADOPTED, published and enabled flow, and a fresh install deliberately has
    none, so arranging one would make it a test of the adoption path.
- [x] 4.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
  - The composer legs were run INDIVIDUALLY: `check:strict` as one command
    exceeds the 300s budget, and phpmd was swept per directory because
    printing nothing is its OOM signature, not a pass.
  - Exit codes: php lint 0, phpcs 0, phpmd 0 (21 directories swept, both
    rulesets, 0 findings), psalm 0, phpstan 0, phpunit 0 (3220 tests, 56
    skipped), lint 0, vitest 0 (705 tests), check:manifest 0, test:l10n 0,
    check:l10n-js 0, check:schema-l10n 0, format 0, hydra gates 0 (82 of 82
    applicable).
  - PHPStan caught a real one: `CARRIED` and `NEVER_COPIED` are provably
    disjoint, so the runtime strip between them was dead code. The ban is now
    a rule about the constants, asserted by the unit test both against the
    written payload and as a disjointness check.
