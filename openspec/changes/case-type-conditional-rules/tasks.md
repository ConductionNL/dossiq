# Tasks: case-type-conditional-rules

Proposal only. Not to be built before the coordinator and Ruben agree on the design and on the open questions in the
proposal (engine scoping per case type, the add-task route, idempotence).

### Task 1: The rule model on caseType
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-a-case-type-carries-conditional-rules`
- **files**: `lib/Settings/register.d/99-case-type-rules.json`, schema digests
- [ ] Implement
- [ ] Test

### Task 2: The projection onto the engines
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions`
- **files**: `lib/Service/CaseType/CaseTypeRuleProjector.php`, publish hook
- [ ] Implement
- [ ] Test (unit + integration)

### Task 3: The Rules tab reads and writes rules
- **spec_ref**: `openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules`
- **files**: `src/menu-layout.simple.json`, nc-vue condition editor
- [ ] Implement
- [ ] Test
