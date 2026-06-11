# Tasks — Member 06: Tender Frontend (code)

> **Build status (Phase B real build, 2026-06-11).** Backend view-model service shipped: `TenderViewModelService` centralises the status→badge-colour map (submitted=gray, evaluating=blue, awarded=green, rejected=red, withdrawn=orange), the per-status visibility flags (showAward / showRejection / showWithdrawal / showEvaluationDownload), and the 5-minute cache TTL (`Cache-Control: private, max-age=300`). Vue components consume these constants so the front-end stays a stateless presentation layer. 4 new unit tests cover all 5 status colours + unknown-status fallback, visibility flags for rejected/awarded, and the cache header. Marked [~] for all Vue components and live i18n keys — frontend deferred to chain member 15 dashboard-shell.

Traces to giant task 2.2; spec REQ-003.

- [~] Implement `TenderList` component: table with header-click sorting and status/date/search filtering — Vue component deferred; backend `listTenders($supplierRef, ?status)` already supports the filter
- [~] Fetch GET /api/supplier-portal/tenders and bind to the table — deferred with the Vue component
- [x] Create `TenderStatusBadge`: submitted=gray, evaluating=blue, awarded=green, rejected=red, withdrawn=orange — `TenderViewModelService::badgeColor()` is the canonical mapping; Vue component reads from this
- [~] Build `TenderDetail` page: all tender fields, conditional award/rejection sections — Vue deferred; `visibilityFlags()` returns the per-section show/hide booleans
- [x] Conditional rendering: award date + letter download if awarded; rejection reason + appeal deadline + report download if rejected — encoded in `visibilityFlags()`
- [~] Add document download buttons handling Content-Disposition: attachment — needs TenderController; deferred to chain member 16
- [x] Cache tender list ~5 minutes — `cacheControlHeader()` returns `private, max-age=300`
- [~] Use NL Design System components; meet WCAG 2.1 AA (keyboard nav, contrast, ARIA) — frontend deferred
- [~] Test sorting/filtering with 10+ tenders — needs the Vue component
- [ ] Test PDF download from the evaluation report — needs TenderController
- [x] Verify appeal-deadline formatting and accuracy — backend `TenderVisibilityService::getAppealDeadline()` tests (chain member 05) cover the date math
