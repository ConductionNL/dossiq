# Tasks

- [x] Move the 31 grammar tests to openregister first (openregister#3191)
- [x] Point `DecisionTableController` at `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`
- [x] Point `EvaluateDecisionHandler` at the same evaluator
- [x] Point `DecisionTableService` at `UnaryTestEvaluator::VALID_TYPES`
- [x] Delete `DecisionEngine`, `ExpressionEvaluator`, `DecisionEvaluationException`
- [x] Add test stubs for the three OpenRegister classes, mirroring the real signatures
- [x] Rework `EvaluateDecisionHandlerTest` onto a mocked evaluator
- [x] Correct the schema copy: all five hit policies work
- [x] Run the three seeded tables through the real shared evaluator (all three are placeholder data and fail on their own entries, under either engine)
- [x] Carry `priority` on a rule, so REQ "PRIORITY returns the highest" is actually
      satisfiable (dossiq#1564). At the time this change merged it was not: the
      schema had no `priority` property and `validateRules()` stripped the field,
      so a PRIORITY table answered 200 with the wrong rule. The e2e found it; the
      unit tests, which mock the evaluator, could not.
