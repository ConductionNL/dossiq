# Tasks — Member 16: Cross-Cutting Tests, A11y/Security Audit & Docs (code)

Traces to giant tasks 5.1–5.6, 6.3, 6.4.

- [ ] Write E2E: login via eHerkenning → dashboard → view tender → download report → logout
- [ ] Write E2E: admin invites member → member activates → views role-scoped tabs
- [ ] Write E2E: view invoice → send message → receive response
- [ ] Write E2E: contract renewal request workflow
- [ ] Write E2E: update address (immediate) and IBAN change (creates Procest zaak)
- [ ] Run cross-cutting integration tests asserting scope isolation (supplier B → 403 on supplier A)
- [ ] Run rate-limit (429 on 101st) and audit-log (masked PII) integration assertions
- [ ] Run automated accessibility audit (Axe/Lighthouse) + manual keyboard + screen-reader pass
- [ ] Verify contrast ≥4.5:1 across all pages
- [ ] Run security audit: XSS/CSRF/injection scan + manual review; attempt scope/CORS/rate-limit bypass
- [ ] Write API docs (endpoints, request/response schemas, examples)
- [ ] Write deployment guide (env vars, dependency service URLs, repair-step setup)
- [ ] Write user guide + troubleshooting (eHerkenning, KvK, payment-date)
- [ ] Create release checklist, staged rollout, rollback, and communication plan
