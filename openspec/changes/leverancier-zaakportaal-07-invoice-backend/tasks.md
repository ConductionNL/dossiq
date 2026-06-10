# Tasks — Member 07: Invoice Payment Forecast Backend (code)

> **Build status (hydra audit).** Greenfield. No supplier/leverancier schemas, services, or UI exist on dev (the in-tree zaakportaal is the citizen-side mijngemeente portal — separate concern, lives in lib/Service/Zaakportaal + src/views/portaal + lib/Settings/register.d/50-zaakportaal.json). The 16-member chain implements the supplier portal from scratch (Supplier* schemas, eHerkenning auth, RBAC, tender/invoice/contract/messaging surfaces, KPI dashboard, e2e tests). Tasks remain [ ] as genuine forward work.

Traces to giant tasks 3.3 and 4.4; spec REQ-004.

- [ ] Implement `InvoicePaymentForecastService.calculateExpectedPaymentDate(invoiceRef)` — join Decidesk routing + payment terms
- [ ] Implement the forecast formula: invoiceDate + mandateRoutingDays + paymentTermsDays
- [ ] Implement Decidesk-unavailable fallback: default 5-day routing delay
- [ ] Implement `InvoicePaymentForecastService.getAgeAnalysis(supplierRef)` — buckets with counts/totals/percentages
- [ ] Implement nightly job: flag 90+ day overdue invoices, send alert emails
- [ ] Create `InvoiceController`: GET /invoices, GET /invoices/{id}, GET /invoices/age-analysis, POST /invoices/{id}/dispute
- [ ] Apply member 04 scope validation; enforce financial re-auth on invoice viewing
- [ ] Audit-log dispute writes
- [ ] Test payment-date calculation across routing scenarios
- [ ] Test age buckets at exact 30/60/90-day boundaries
- [ ] Test 90+ overdue alert email
- [ ] Verify Decidesk mandate-routing integration and fallback
