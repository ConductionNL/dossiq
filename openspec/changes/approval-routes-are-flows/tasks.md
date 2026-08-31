# Tasks

- [x] Project each route onto a flow, chained in `order`
- [x] Use `dossiq.askPerson` so each step reaches its approver's work queue
- [x] End the chain at `dossiq.requestDecision`
- [x] Refuse a route whose step names no actor, rather than projecting it partly
- [x] Arrive DISABLED, like the workflow-definition projection
- [x] Run the whole migration as the named user, so flows get a real owner
- [x] Extract the shared projection machinery into `ProjectsOntoFlows`
- [ ] Phase 2: verify against real routes, enable, retire the route and its page
- [ ] Phase 2: retire decidiq's `ApprovalRoute` schema (decidiq#1028)
