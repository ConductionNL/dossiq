# Tasks

- [x] Give `parafeeractie` the `flowRun` and `flowNode` linkage fields
- [x] Add `dossiq.askParaaf`, recording who is asked and awaiting the paraaf
- [x] Correct it to create no parafeeractie: `action` is required and means a sign-off given
- [x] Refuse a step naming no actor, and a run with no resume slot
- [x] Record the ask once however often the heartbeat wakes
- [x] Treat a resume without a decision as a nudge, not a sign-off
- [x] Emit `dossiq.askParaaf` from the route projection, carrying the route's own step number
- [ ] Stamp `flowRun`/`flowNode` onto the paraaf the approver creates, and resume the run from it
- [x] `flowRunId` on the proposal schema
- [x] `ParaferingFlowGateway`: start a run only for an ENABLED projected flow
- [x] Dual-path activate(): record the run, or fall through to the route snapshot
- [ ] Only then: enable the projections and retire parafeerroute, its page, and decidiq's ApprovalRoute
