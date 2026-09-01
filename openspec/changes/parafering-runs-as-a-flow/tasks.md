# Tasks

- [x] Give `parafeeractie` the `flowRun` and `flowNode` linkage fields
- [x] Add `dossiq.askParaaf`, raising a parafeeractie and awaiting its outcome
- [x] Refuse a step naming no actor, and a run with no resume slot
- [x] Raise exactly one paraaf however often the heartbeat wakes
- [x] Treat a resume without a decision as a nudge, not a sign-off
- [x] Emit `dossiq.askParaaf` from the route projection, carrying the route's own step number
- [ ] Dual-path BesluitvormingParafeerService: finish snapshot voorstellen, start new ones as runs
- [ ] Only then: enable the projections and retire parafeerroute, its page, and decidiq's ApprovalRoute
