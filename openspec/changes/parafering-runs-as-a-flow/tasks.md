# Tasks

- [x] Give `parafeeractie` the `flowRun` and `flowNode` linkage fields
- [x] Add `dossiq.askParaaf`, recording who is asked and awaiting the paraaf
- [x] Correct it to create no parafeeractie: `action` is required and means a sign-off given
- [x] Refuse a step naming no actor, and a run with no resume slot
- [x] Record the ask once however often the heartbeat wakes
- [x] Treat a resume without a decision as a nudge, not a sign-off
- [x] Emit `dossiq.askParaaf` from the route projection, carrying the route's own step number
- [x] Stamp `flowRun`/`flowNode` onto the paraaf the approver creates, and resume the run from it
- [x] Perform the assignee check in the listener: `signal()` bypasses the HTTP guard
- [x] `flowRunId` on the proposal schema
- [x] `ParaferingFlowGateway`: start a run only for an ENABLED projected flow
- [x] Dual-path activate(): record the run, or fall through to the route snapshot
- [x] `dossiq.setVoorstelStatus`, refusing a status the proposal schema does not declare
- [x] Branch out of the chain on a returned paraaf, so a rejection does not walk on
- [x] Close the chain: `geaccordeerd` after the decision, `teruggestuurd` on a return
- [x] `handleParaafAction()` stands aside for a voorstel carrying a flow run
- [x] Enable the approval-route projection
- [ ] Retire parafeerroute, its settings page, and decidiq's ApprovalRoute
- [ ] The workflow-template projection stays DISABLED: StatusTransitionService,
      WorkflowEngineService and BezwaarLifecycleListener still drive those
      transitions, so enabling it would run each one twice
