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
- [x] Test (integration on a live instance)
      (live 25 Nov 2026, sandbox NC 34.0.6 + MariaDB + OpenRegister main 987938d4 with openregister#11215: a new
      Omgevingsvergunning version carrying the rule "bouwkosten > 100000 → add task Welstandsadvies vragen" published
      with no rule warnings and projected one flow, indexed as `object.updated dossiq/case`; a case with bouwkosten
      250000 got the task "Welstandsadvies vragen" on its first save; a case with bouwkosten 40000 ran the flow and its
      filter passed 0 items, so no task. Without openregister#11215 the flow saves but its trigger is never indexed on
      NC 34, so nothing fires)

### Task 3: The Rules tab reads and writes rules
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules`
- **files**: `src/menu-layout.simple.json`, nc-vue condition editor
- [x] Implement the rows: `GET /api/case-types/{id}/rules` (`CaseTypeRuleSentences`, `CaseTypeRulesController`) writes
      each rule as "If ... / then ..." with its moment and an On or Off pill; the `case-type-rule-rows` record list
      shows them on the Rules tab above the case schema's OpenRegister rules
- [x] Test the rows (unit: every condition kind, operator, action kind and moment in words, a status by its name,
      the read check before the answer)
- [x] Implement "Regel toevoegen" and the row menu (Edit, Turn off, Delete): the form over this type's own fields,
      tasks, variants, document types and templates, on the library's `CnConditionListEditor`
      (`src/modals/CaseTypeRuleDialog.vue`, opened from `src/views/settings/tabs/RulesTab.vue` on the Rules tab;
      conversion and choices in `src/services/caseTypeRules.js`; a published version offers no add and no row menu)
- [x] Test the form (vitest: the form writes the model)
      (`tests/vitest/caseTypeConditionalRules.spec.js`: adds a rule through the form and stores it on the case type,
      sends a message to a role, freezes a published version; `tests/vitest/caseTypeRulesConversion.spec.js`)

### Task 0: OpenRegister, a scoped rule inventory (built first, in OpenRegister)
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rule-inventory-can-be-asked-for-one-case-types-rules`
- **files**: OpenRegister `lib/Service/Rules/RuleInventoryService.php`, `lib/Controller/RulesController.php`, rule descriptors
- [x] Implement (on openregister main: `GET /api/schemas/{schema}/rules` reads a `scope` parameter into
      `RuleInventoryOptions::$scope`, which keeps the rules with that scope plus those without one; dossiq's
      `RulesTab.vue` asks with `{ caseType: <id> }`)
- [x] Test (openregister `tests/Unit/Controller/RulesControllerScopeTest.php` and
      `tests/Unit/Service/Rules/RuleInventoryServiceTest.php`)

### Task 4: Idempotent effects
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-a-rules-effect-happens-once-per-rule-case-and-trigger-version`
- **files**: `CreateTaskHandler` `onceKey`, `EngineTaskGateway::keyTaken`; OpenRegister `TaskService::findByKey`
- [x] Implement (a task added by a rule carries `dossiq:once:rule:<case type>:<rule>:<case>` as its engine key; a taken
      key adds nothing, an unanswerable lookup fails the step rather than risk a second task)
- [x] Test (unit)
- [x] Test (integration: saved twice, one task)
      (live 25 Nov 2026, same instance: three saves of the matching case gave three flow runs; the first added task
      `dossiq:once:rule:<case type>:welstandsadvies:<case>`, the second and third answered `skipped: already added`;
      the case holds exactly one task)
