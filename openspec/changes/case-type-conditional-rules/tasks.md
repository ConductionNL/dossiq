# Tasks: case-type-conditional-rules

Approved by Ruben (24 Oct) and being built. The add-task route uses dossiq's existing `dossiq.createTask` flow step
(OpenRegister tasks are engine tasks, not register objects, so `ObjectWriteNode` cannot create one), with a once-key
the engine task carries as its `key`.

### Task 1: The rule model on caseType
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-a-case-type-carries-conditional-rules`
- **files**: `lib/Settings/register.d/99-case-type-rules.json`, schema digests
- [x] Implement (caseType 1.38.0 `rules`)
- [x] Test (schema digest recorded)

### Task 2: The projection onto the engines
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions`
- **files**: `lib/Service/CaseType/CaseTypeRuleProjector.php`, `CaseTypeRuleFlowDocument.php`, `RuleConditionLogic.php`, publish hook
- [x] Implement addTask: a guarded flow per action (object trigger scoped to the case type, filter with the case type
      guard and the conditions, `dossiq.createTask`), replaced on every publish
- [x] Implement setField on a case field and chooseVariant (case 1.50.0 `variant`): a guarded flow ending in an
      `openregister.object-write` update, guarded on the field not holding the value yet
- [x] Implement setField on a property definition (`dossiq.setCaseAnswer`, merges into `properties[]`), sendMessage
      (`dossiq.sendMessage`, to a role on the case, once per case or per status entry through the send-once ledger) and
      requireDocument on entering a status (`caseType.entryRequirements`, read by the `statusEntryRequirements` guard)
- [x] requireDocument with conditions besides the status: the conditions travel with the requirement as JSONLogic and
      the guard evaluates them with OpenRegister's flow expressions (unevaluable reads as required)
- [x] Test (unit: plan, flow shape, the filter evaluated with OpenRegister's JSONLogic, replace-on-publish)
- [ ] Test (integration on a live instance)

### Task 3: The Rules tab reads and writes rules
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules`
- **files**: `src/menu-layout.simple.json`, nc-vue condition editor
- [ ] Implement
- [ ] Test

### Task 0: OpenRegister, a scoped rule inventory (built first, in OpenRegister)
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rule-inventory-can-be-asked-for-one-case-types-rules`
- **files**: OpenRegister `lib/Service/Rules/RuleInventoryService.php`, `lib/Controller/RulesController.php`, rule descriptors
- [ ] Implement
- [ ] Test

### Task 4: Idempotent effects
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-a-rules-effect-happens-once-per-rule-case-and-trigger-version`
- **files**: `CreateTaskHandler` `onceKey`, `EngineTaskGateway::keyTaken`; OpenRegister `TaskService::findByKey`
- [x] Implement (a task added by a rule carries `dossiq:once:rule:<case type>:<rule>:<case>` as its engine key; a taken
      key adds nothing, an unanswerable lookup fails the step rather than risk a second task)
- [x] Test (unit)
- [ ] Test (integration: saved twice, one task)
