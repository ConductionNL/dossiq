# Tasks: data-subject-requests-drive-the-platform

Tier: MVP. Kind: feature. Size L. The consumer half of openregister
`data-subject-rights-across-the-instance` (#3759, and part 2 #3800), both
merged. Not a `competitor-parity-2026-09` row: that umbrella has no AVG or
data subject candidate.

## 1. The case type and its schema

- [x] 1.1 `lib/Settings/register.d/54-data-subject-request.json`: the
  `dataSubjectRequest` block on the `case` schema, holding the request kind,
  the subject, the platform's preview id and digest, the approval and the run
  outcome. Every nested property of `erasureProtected` carries a title and a
  description (gate-51).
  - `@spec openspec/changes/data-subject-requests-drive-the-platform/specs/avg-processing-surface/spec.md`
- [x] 1.2 The same fragment seeds the `data-subject-request` case type with
  `processingDeadline: P1M` per D-1, its six statuses and its workflow
  template.
  - `tests/Unit/Service/Gdpr/DataSubjectRequestTemplateTest.php`
- [x] 1.3 The three closing transitions are pinned to their request kind with
  `fieldEquals`, and the verwijdering one also declares `erasureComplete`
  per D-6.
- [x] 1.4 The approving transition declares `notPerformedBy` against the
  preparing act per D-5, so the preparer cannot approve their own erasure.

## 2. The platform door and the case side

- [x] 2.1 `lib/Service/Gdpr/PlatformDataSubjectRights.php`: the one door to
  OpenRegister's preview, approval, run and subject export, resolved by name
  per D-2. No erasure logic of its own. A refusal keeps the platform's rule
  per D-3.
  - `tests/Unit/Service/Gdpr/PlatformDataSubjectRightsTest.php`
- [x] 2.2 `lib/Service/Gdpr/DataSubjectRequestCase.php`: the case side.
  Writes the preview onto the case, refuses a run without the approving act,
  records the outcome and the timeline entry.
  - `tests/Unit/Service/Gdpr/DataSubjectRequestCaseTest.php`
- [x] 2.3 `lib/Service/Timeline/TimelineKinds.php` gains `avg-verzoek`,
  declared once like every other kind, and named by the kind inventory test.
  - `tests/Unit/Service/Timeline/CaseTimelineTest.php`
- [x] 2.4 `lib/Service/Transitions/TransitionPreconditions.php` gains the
  `erasureComplete` dependency kind, which names the withheld items rather
  than reporting a failed condition, per D-6.
  - `tests/Unit/Service/Transitions/ErasureCompletePreconditionTest.php`
- [x] 2.5 `lib/Service/TemplateLibraryService.php` creates the declared
  `workflowTemplate`, which no bundle in the library was doing, per D-5.

## 3. The endpoints and the case surface

- [x] 3.1 `lib/Controller/DataSubjectRequestController.php` and its four
  routes, each guarded per case.
  - `tests/Unit/Controller/DataSubjectRequestControllerContractTest.php`
- [x] 3.2 `src/services/dataSubjectRequestApi.js`: the four acts, the
  platform download url per D-7, and a refusal that keeps the server's rule.
  - `tests/vitest/dataSubjectRequestApi.spec.js`
- [x] 3.3 `src/views/cases/components/DataSubjectRequestTab.vue`, registered
  in `src/registry.js` and declared on the case page in `src/manifest.json`.
  Shows the counts, every protected item with its ground per D-4, and the
  export download. Reads the request kind itself per D-8.
- [x] 3.4 `l10n/en.json` and `l10n/nl.json`: the 18 new strings, with
  `AVG` listed in `tests/l10n/language-neutral-keys.json`.

## 4. Verification

- [x] 4.1 Unit tests for the platform door, the case side and the
  precondition, each mutation-checked.
- [x] 4.2 A contract test for the new controller, per gate 25.
- [x] 4.3 `tests/e2e/data-subject-requests-drive-the-platform.spec.ts`,
  written and tagged, not run locally (no Playwright here).
- [x] 4.4 `diff-check.sh --base origin/development`: php -l, phpstan,
  phpunit, eslint and stylelint green; gates green with a delta base.
- [x] 4.5 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`: run once, all
  six stages, ALL CHECKS PASSED.
- [x] 4.6 `openspec validate data-subject-requests-drive-the-platform --strict`.

## Acceptance Criteria

- A verwijdering case takes a preview from the platform and shows every
  protected record with its ground, its basis and its remedy.
- The person who prepared a preview cannot approve it, and the refusal names
  the earlier act and who to ask instead.
- A verwijdering case cannot close while the platform reports the erasure
  incomplete, and the withheld reason names the records it did not reach.
- An inzage case offers the export download only while the platform says the
  file is ready and unexpired.
- No erasure, pseudonymisation or export engine exists in dossiq.
