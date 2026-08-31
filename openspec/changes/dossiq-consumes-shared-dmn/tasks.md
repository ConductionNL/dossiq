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
