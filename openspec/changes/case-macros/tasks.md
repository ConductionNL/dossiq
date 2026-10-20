# Tasks: case-macros

### Task 1: The term can be suspended by a flow
- **spec_ref**: `openspec/changes/case-macros/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros`
- **files**: `lib/Service/Transitions/SuspendTermHandler.php`, `lib/Flow/DossiqTxSuspendTermNode.php`, `lib/Flow/DossiqFlowNodeListener.php`
- [x] Implement: handler and node, registered
- [x] Test: `tests/Unit/Service/Transitions/SuspendTermHandlerTest.php`, `tests/Unit/Flow/DossiqFlowNodeListenerTest.php`

### Task 2: The case schema declares the two macros
- **spec_ref**: `openspec/changes/case-macros/specs/case-dashboard-view/spec.md#requirement-the-case-schema-ships-the-boards-two-macros`
- **files**: `lib/Settings/dossiq_register.json`, `l10n/*`
- [x] Implement: actions and flows, Dutch names
- [x] Test: `tests/Unit/Flow/CaseMacroDeclarationTest.php`, `tests/Unit/Flow/CaseFlowDeclarationTest.php`
