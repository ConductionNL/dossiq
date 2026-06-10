# Tasks — Member 07: Invoice Payment Forecast Backend (code)

Traces to giant tasks 3.3 and 4.4; spec REQ-004.

- [~] Implement `InvoicePaymentForecastService.calculateExpectedPaymentDate(invoiceRef)` — join Decidesk routing + payment terms — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement the forecast formula: invoiceDate + mandateRoutingDays + paymentTermsDays — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement Decidesk-unavailable fallback: default 5-day routing delay — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `InvoicePaymentForecastService.getAgeAnalysis(supplierRef)` — buckets with counts/totals/percentages — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement nightly job: flag 90+ day overdue invoices, send alert emails — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Create `InvoiceController`: GET /invoices, GET /invoices/{id}, GET /invoices/age-analysis, POST /invoices/{id}/dispute — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Apply member 04 scope validation; enforce financial re-auth on invoice viewing — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Audit-log dispute writes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test payment-date calculation across routing scenarios — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test age buckets at exact 30/60/90-day boundaries — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test 90+ overdue alert email — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Verify Decidesk mandate-routing integration and fallback — deferred to downstream cycle / fleet-wide adoption (handoff)
