# Tasks — leaf-integrations

## 1. Create-from-email templates (REQ-LEAF-101)

- [x] 1.1 `lib/Settings/dossiq_register.json`: `configuration.mailObjectTemplate` on `case` and `complaint`, scalars only, no identifying field. `communicationChannel` is NOT a key: the property is `format: uri` and `email` is not a URI. Register version 0.19.2 → 0.20.0, or the import repair step skips the whole thing.
- [x] 1.2 `complaint` also gains `linkedTypes: ["mail"]`. A template without the sentinel is a button that never appears: the Mail sidebar builds its schema list by filtering on `linkedTypes.includes('mail')` and draws the create button per schema in THAT list.
- [x] 1.3 `tests/Unit/LeafIntegrationDeclarationsTest.php`: exactly two schemas carry a template, every key is a real property, every value is a scalar, no identity is prefilled, and both are actually reachable.

## 2. Talk (REQ-LEAF-102)

- [x] 2.1 `"talk"` added to `case.configuration.linkedTypes`.
- [x] 2.2 NO `TalkLeafTab` and NO Talk widget. `live-conversation-on-the-case` shipped a Talk surface after this change was written: `case-conversations-pane` starts a room through `OCP\Talk\IBroker`, records the conversation on the case and declares the case major. A leaf tab beside it would list rooms from OpenRegister's link table while the pane lists rooms from `case.conversations`, so the same question would have two answers that never agree.

## 3. Forms citizen intake (REQ-LEAF-103)

- [x] 3.1 `caseType.intakeFormRef`, optional, additive.
- [x] 3.2 `lib/Service/FormsIntakeService.php`: resolves the bound case type by form hash, RE-CHECKS the hash on every row the store returns, writes the case with the initial status, `intakeChannel: "forms"` and `startDate` = the submission date.
- [x] 3.3 `lib/Listener/FormSubmittedListener.php`, registered in `CrossAppListenerRegistrar` behind `class_exists`. The event name is an FQN string on the listener, never an import: `forms` is optional and a type hint on an absent class is a fatal at container build time. A wrong name registers nothing and creates nothing, which for an intake path is the right way to fail.
- [x] 3.4 `tests/Unit/Service/FormsIntakeServiceTest.php`: the unbound form is asserted first and the bound one is its control; a store that ignores the filter is shown not to get to choose the case type.

## 4. Maps on the VTH surfaces (REQ-LEAF-104)

- [x] 4.1 `register.d/40-mobiel-inspectie-offline.json`: `fieldInspection` gains a `configuration` object it did not have, with `linkedTypes: ["maps"]`.
- [x] 4.2 `inspectionChecklistRun.configuration.linkedTypes` extended to `["forms", "photos", "maps"]`.
- [x] 4.3 NO manifest change. Neither schema has a dossiq detail page to put a tab on, so the leaf surfaces on OpenRegister's own object page. Giving `fieldInspection` a page is a new surface and belongs to `no-schema-without-a-surface`.

## 5. Deck (REQ-LEAF-105)

- [x] 5.1 `"deck"` added to `case.configuration.linkedTypes`.
- [x] 5.2 `src/manifest.json`: a `case-deck` integration widget as a SECTION of the Work panel, beside the tasks. Not a body widget with a layout entry, which the design named: the case body is a tab strip and its grid is full.
- [x] 5.3 Asserted that no code path links `task` to Deck, searching `lib/` for the shapes a real Deck call takes and asserting the searched-file count. The first form of that test matched the word `deck` anywhere and reddened on a docblock reading "Nextcloud Deck does it in one call": a sentence about Deck is not a call to Deck, and a gate that cannot tell them apart gets suppressed rather than fixed.

## 6. Specs and verification

- [x] 6.1 The delta spec rewritten to what shipped, with `@e2e` on every scenario. `openspec validate leaf-integrations --strict` clean.
- [x] 6.2 `tests/e2e/leaf-integrations.spec.ts` reads every declaration back from `/apps/openregister/api/schemas` rather than from the files, because `ImportHandler` skips an import whose version did not move, `case` is union-merged with the DSO fragment, and an unknown configuration key is dropped in silence.
- [x] 6.3 `php -l` on the new and changed PHP; the two new PHPUnit files pass; `node tests/validate-manifest.js` clean; `python3 -m json.tool` on both registers.
- [ ] 6.4 Live verification is the sweep's, not this lane's: re-run the register import repair step, read the five schemas back from OpenRegister, confirm `LogDanglingLinkedTypes` reports no dangling Dossiq value, and confirm the Mail sidebar shows both create buttons. **`FormSubmittedListener::EVENT` is the one thing this lane could not verify**: no Forms app is installed in this checkout, so the event class name comes from the app's published API. Confirm it on an instance with `forms` enabled.
