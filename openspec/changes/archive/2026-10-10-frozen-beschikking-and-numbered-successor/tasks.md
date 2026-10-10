## 1. Make the beschikking lifecycle reachable

- [x] 1.1 Add `beschikking`, `stateMachineLog`, `bezwaarTrigger` and `mandateArrangement` to
  `SchemaSlugMap::SLUG_TO_CONFIG_KEY`, mapping to `beschikking_schema`,
  `state_machine_log_schema`, `bezwaar_trigger_schema` and `mandaat_regeling_schema`. Verified
  by `SchemaKeyCoverageTest::testTheBeschikkingLifecycleSchemasAreMapped`.
- [x] 1.2 Extract the 200-entry appconfig allowlist from `SettingsService` into
  `Settings\ConfigKeys`, then add the same four keys. The service was on the phpmd
  `ExcessiveClassLength` ceiling and `development` was already red on that leg; verified by
  `composer phpmd` exiting 0 where it exited 2 before.
- [x] 1.3 Add `SchemaKeyCoverageTest`, asserting every `*_schema` key resolved in `lib/` is
  either mapped or recorded in a reason-bearing gap list. Mutation-checked: removing
  `beschikking` from the map reddens two assertions, one naming `beschikking_schema`.

## 2. Move the immutability rule off the controller path

- [x] 2.1 Add `StateMachineService::assertMutable(array $stored, array $changed)` and
  `assertDeletable(array $stored)`, beside the `IMMUTABLE_STATUSES` they read. Verified by
  `BeschikkingServiceTest::testImmutabilityAfterSigning` still passing.
- [x] 2.2 Rewrite `BeschikkingService::updateFields()` to call the state machine rather than
  inline the check, so the list has one home. Verified by `BeschikkingControllerContractTest`.

## 3. Guard the store itself

- [x] 3.1 Add `lib/Listener/BeschikkingImmutabilityListener.php` on `ObjectUpdatingEvent` and
  `ObjectDeletingEvent`: read the stored state, match the schema, call the guard, and on
  rejection `setErrors()` plus `stopPropagation()`.
- [x] 3.2 Register both events in `ImmutabilityListenerRegistrar::register()`.
- [x] 3.3 Add `BeschikkingImmutabilityListenerTest`, eight cases pairing every refusal with an
  acceptance. Mutation-checked: removing `stopPropagation()` reddens exactly the three reject
  assertions and leaves the five accept assertions green.

## 4. Verify the whole change

- [x] 4.1 Ran each `composer check:strict` leg separately and read each exit code: lint 0,
  phpcs 0, phpmd 0, psalm 0, phpstan 0, test:all 0 (3611 tests, 1 skipped).
- [x] 4.2 `openspec validate frozen-beschikking-and-numbered-successor --strict` passes.
- [x] 4.3 `git diff -- lib/Settings/` is empty. No schema property was added: the successor
  properties stay out until the numbering decision lands.

## 5. The numbered successor (REQ-BES-012, decision 167)

- [x] 5.1 Number every beschikking at compose, and issue a correction as a numbered successor.
  Decision 167 (Q-dossiq-L1-2) answered the numbering: one running number per organisation per
  year, `B-2026-000123`, written by `compose()`; a correction gets its own new number and points
  at the one it replaces. Built:
  - `lib/Service/Beschikking/BeschikkingNumberer.php`: reserves the number through OpenRegister's
    `SequenceService` with the case's organisation and the year in the scope key, so two
    organisations on one instance never share a row; refuses 503 `beschikking-number-unavailable`
    when no number can be reserved. `tests/Unit/Service/Beschikking/BeschikkingNumbererTest.php`
    (per organisation, per year, no organisation, long ids, no counter, a zero answer).
  - `BeschikkingService::compose()` writes `reference` and, for a successor, `supersedes`;
    `BeschikkingServiceTest::testComposeWritesTheNextRunningNumber` (mutation-checked: renaming the
    written key reddens it) and `testTheNumberedSuccessorFitsTheRealSchema` (both payloads validated
    against the merged register through `RealSchemaValidator`). That test found compose writing an
    empty `addressee`/`decision` as the JSON list `[]` against `type: object`; compose now leaves
    an empty one out.
  - `lib/Service/Beschikking/BeschikkingSuccession.php`: refuses an unknown kind (422), a draft
    (409 `successor-of-a-draft`) and a second successor (409 `already-superseded`, naming the
    successor's number); composes the successor first, then writes the original's `supersededBy`.
    `tests/Unit/Service/Beschikking/BeschikkingSuccessionTest.php`.
  - `StateMachineService`: `reference` and `supersedes` join `CONTENT_FIELDS`; new
    `WRITE_ONCE_FIELDS = ['supersededBy']`, which the listener also checks, so the pointer can be
    written once after signing and never re-pointed or cleared.
    `BeschikkingImmutabilityListenerTest` (three new cases; mutation-checked).
  - `lib/Controller/BeschikkingSuccessorController.php`, `POST /api/beschikkingen/{id}/successor`,
    guarded by per-case mutation access. `tests/Unit/Controller/BeschikkingSuccessorControllerTest.php`.
    `BeschikkingController::create()` answers the numbering refusal as 503, and `update()` never
    writes `reference`, `supersedes` or `supersededBy`.
  - Schema `beschikking` 1.1.0 gains `supersedes` and `supersededBy` (`register.d/30-beschikking.json`
    and the mock register); `info.xml` bumped; en/nl strings added.
- [x] 5.2 The in-force rule: a beschikking is in force while `supersededBy` is empty. A
  wijzigingsbeschikking does not withdraw its predecessor in law, but the chain records which
  text now applies; the successor's own bezwaartermijn starts when it is sent, through the
  ordinary `verzend()` path.
- [x] 5.3 The case-detail rendering of the chain moves to its own change,
  `beschikking-chain-on-the-case`: the drawn board `DqZaakBesluiten` shows one beschikking and no
  chain, so it waits for a board (decision 162).
