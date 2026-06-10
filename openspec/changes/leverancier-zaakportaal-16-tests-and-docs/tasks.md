# Tasks — Member 16: Cross-Cutting Tests, A11y/Security Audit & Docs (code)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: full OpenAPI 3.0 doc `docs/openapi/leverancier-zaakportaal.yaml` covering eHerkenning auth + all 6 domain endpoint families (tenders, invoices, contracts, messaging, profile/master-data, KPIs) + dashboard summary, with bearer JWT security scheme and the cross-cutting 401/403/404/429 error map. Deployment guide `docs/leverancier-zaakportaal/deployment.md` covers prerequisites + repair-step bootstrap + app-config keys (jwt_signing_secret + eHerkenning broker + KvK API + Shillinq) + scheduled jobs table + security checklist. User guide `docs/leverancier-zaakportaal/user-guide.md` covers eHerkenning login + rol-tab matrix + dashboard + invoice/contract/IBAN/KPI/messages flows in Dutch. Backend test coverage = 100+ unit tests across the 14 backend services that ship in this chain (see chain-member-specific tasks). Marked [~] for the cross-app live-integration items (Playwright E2E, Axe/Lighthouse audit, security pen-test, release checklist) — these require a deployed instance + Decidesk + Shillinq stack and are tracked as a chain-12 + chain-16 follow-up fixture work.

Traces to giant tasks 5.1–5.6, 6.3, 6.4.

- [~] Write E2E: login via eHerkenning → dashboard → view tender → download report → logout — requires Playwright + a live OpenConnector eHerkenning broker stub; deferred to a follow-up E2E fixture change
- [~] Write E2E: admin invites member → member activates → views role-scoped tabs — deferred with the E2E fixture
- [~] Write E2E: view invoice → send message → receive response — deferred with the E2E fixture
- [~] Write E2E: contract renewal request workflow — deferred with the E2E fixture
- [~] Write E2E: update address (immediate) and IBAN change (creates Procest zaak) — deferred with the E2E fixture
- [~] Run cross-cutting integration tests asserting scope isolation (supplier B → 403 on supplier A) — `SupplierScopeServiceTest::testValidateSupplierAccessRejectsMismatch` proves the scope primitive; live HTTP scenario test deferred to the E2E fixture
- [~] Run rate-limit (429 on 101st) and audit-log (masked PII) integration assertions — `SupplierScopeServiceTest::testMaskIbanShowsLastFour` + `testMaskEmailKeepsDomain` + `testMaskPhoneKeepsLastThree` prove the masking primitives; live HTTP 429 scenario test deferred
- [~] Run automated accessibility audit (Axe/Lighthouse) + manual keyboard + screen-reader pass — needs the Vue components; deferred
- [~] Verify contrast ≥4.5:1 across all pages — deferred with the Vue components
- [~] Run security audit: XSS/CSRF/injection scan + manual review; attempt scope/CORS/rate-limit bypass — `composer audit` ships as a hydra gate (gate 4); injection scan covers the supplier portal services; manual review deferred
- [x] Write API docs (endpoints, request/response schemas, examples) — `docs/openapi/leverancier-zaakportaal.yaml`
- [x] Write deployment guide (env vars, dependency service URLs, repair-step setup) — `docs/leverancier-zaakportaal/deployment.md`
- [x] Write user guide + troubleshooting (eHerkenning, KvK, payment-date) — `docs/leverancier-zaakportaal/user-guide.md` (Dutch, covers all six domain flows + troubleshooting)
- [~] Create release checklist, staged rollout, rollback, and communication plan — operational artefact deferred to a separate communication-plan change
