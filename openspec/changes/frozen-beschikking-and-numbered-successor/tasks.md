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

## 5. Blocked on a product decision, not built here

- [ ] 5.1 BLOCKED: the successor write path for `REQ-BES-012`. Needs the numbering scheme, the
  in-force rule for a corrected beschikking, and the case-detail rendering. See `design.md`
  under Open Questions. Do not add `supersedes` or `supersededBy` to the schema until this is
  answered, because a property nothing writes reads as a shipped feature.
