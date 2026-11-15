# Tasks: the-handler-publishes-as-the-system

- [x] 1.1 `HandlerActsAsSystem::run` checks the handler and the step, then runs the act as the system.
- [x] 1.2 Publish and withdraw go through it; refusals answer in the reader's language.
  - `tests/Unit/Controller/WOOAssessmentControllerAuthorizationTest.php` (non-handler refused 403 on both, control: the handler publishes and the publish runs as the system, handler refused 422 before the step)
