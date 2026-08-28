## 1. Schema

- [ ] 1.1 Add `flowRun` and `flowNode` to the `task` schema in `lib/Settings/dossiq_register.json`; verify a task round-trips both through the object store and that existing tasks (both null) still save
- [ ] 1.2 Add the six status types and the `omgevingsvergunning-kleinbouw` case type to the seed data; verify the seed imports and the status types resolve from the case type

## 2. The flow declaration

- [ ] 2.1 Declare the `case-behandeling` flow as `x-openregister-flows` on the `case` schema: trigger on create, status steps, completeness switch, applicant-task loop, two decisions, employee task, commission decision, document, close; verify it imports into the flow store
- [ ] 2.2 Verify the shipped flow arrives DISABLED and ownerless, and that re-importing the register updates it rather than creating a second copy
- [ ] 2.3 Verify the completeness loop's three exits (complete / under cap / at cap) and that the at-cap exit moves the case to `gestrand` and ends the run without hitting the engine's transition ceiling

## 3. Human steps

- [ ] 3.1 Make the applicant-ask step create a `task` stamped with the run and node, then suspend; verify the task names the case and what is missing, and the run's status is suspended
- [ ] 3.2 Implement task completion → `FlowRunService::signal()` in a `TaskCompletionService`; verify the run resumes at the node that asked and the outcome reaches the following steps
- [ ] 3.3 🔴 Verify the AUTHORIZATION refusal: a user who is neither the assignee nor in the assigned group cannot complete the task, and therefore cannot resume the run — this test must fail if the check is removed, since the direct service call bypasses `refuseUnlessAssignee()`
- [ ] 3.4 Verify the three degenerate completions: a task with no run resumes nothing and raises no error, completing twice resumes once, and a task whose run no longer exists still completes

## 4. Decisions via decidiq

- [ ] 4.1 Add `DossiqRequestDecisionNode` raising the decision through `AdviceDelegationService`, storing the `decisionRef` and suspending; verify the run suspends and the ref is on the case
- [ ] 4.2 Resume the waiting run from `DecisionConcludedListener`, matched on `decisionRef`; verify the concluded decision resumes it and an UNRELATED decision leaves it suspended
- [ ] 4.3 Verify the fail-closed path: with decidiq unavailable the step fails and the run does not advance past the decision

## 5. Outcome

- [ ] 5.1 Generate the decision document from the template and attach it to the case after final approval; verify it is attached before the case reaches its final status
- [ ] 5.2 Verify a failed generation leaves the case OPEN with the failure recorded, and that a rejection closes the case with a rejection document

## 6. Frontend

- [ ] 6.1 Show both halves of the waiting relationship — on a task, that a case is waiting on it (linking to the case); on the case detail, its flow run and current stage; verify a non-flow task and a case with no run both render unchanged and without error

## 7. End to end

- [ ] 7.1 Playwright journey over the incomplete case: intake → applicant task → complete it → the case advances and its status changes; verify against the status the applicant sees, not against internal state
- [ ] 7.2 Playwright journey over the happy path: complete case → two decisions → employee task → commission approval → document attached → case closed
- [ ] 7.3 Verify the traceability read: after the journey, the run reports the objects it touched grouped by node, and the case's history names the node that moved each status

## 8. Quality

- [ ] 8.1 Run `composer check:strict` and `npm run lint`, fixing pre-existing findings in the files touched
- [ ] 8.2 Mutation-check the authorization guard from 3.3: remove it, confirm the suite goes red, restore, confirm the source is byte-identical

**Acceptance criteria**

- A case created with a missing field produces a task for the applicant and a suspended run; completing the task advances the case.
- No decision is ever inferred: decidiq unavailable means the run stops, not that it proceeds.
- A case never reaches its final status without its decision document attached.
- Only the assignee (or assigned group) can complete a task and thereby resume a run.
- Existing tasks, which carry no run, behave exactly as before.
- i18n: new user-facing strings go through `t()`; Dutch keys present for the applicant-facing text.
