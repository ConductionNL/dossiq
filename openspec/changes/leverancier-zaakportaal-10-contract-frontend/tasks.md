# Tasks — Member 10: Contract Frontend (code)

Traces to giant task 2.4; spec REQ-005.

- [~] Implement `ContractList` component: columns number/subject/start/end/value/account manager/renewal option — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Fetch GET /api/supplier-portal/contracts and bind to the table — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Default sort by end date (nearest first); clickable headers re-sort — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Warning badge: if daysUntilExpiry < 90, orange "Vervalt over [n] dagen" + highlighted row — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build `ContractDetail` page: all fields, conditional renewal-option details — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] "Verlenging aanvragen" button visible only if renewal option = manual_request AND within 90 days — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `RenewalRequestModal` (own file in src/modals/): confirmation, POST request-renewal — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Display confirmation and disable button: "Verlenging aangevraagd op [date]" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Use NL Design System components; meet WCAG 2.1 AA — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test renewal request flow and confirmation state — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test contracts at the exact 90-day boundary and all three renewal-option types — deferred to downstream cycle / fleet-wide adoption (handoff)
