# Tasks — Member 14: KPI Frontend (code)

Traces to giant task 2.7; spec REQ-008-A/B/C.

- [ ] Implement `KPICard` component: title, current value, benchmark comparison, embedded trend
- [ ] Fetch GET /api/supplier-portal/kpis (snapshot) and /kpis/trends (12-month)
- [ ] Create `TrendChart`: line for payment days + on-time %, bar for dispute rate
- [ ] X-axis month labels; metric-specific Y-axis; hover tooltip ("May 2026: 28 days")
- [ ] Skip insufficient-data months from the chart with an "Onvoldoende gegevens" label
- [ ] Implement benchmark comparison indicators (own vs municipal average)
- [ ] Implement CSV export button: GET /kpis/export, trigger download
- [ ] Use NL Design System components; meet WCAG 2.1 AA
- [ ] Test with 12 full months of data
- [ ] Test sparse-data months
- [ ] Verify benchmark comparison rendering
