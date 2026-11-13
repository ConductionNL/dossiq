## 1. Traceability

- [x] 1.1 Point the `@spec` tag on `textsAndAccessFields` in `src/views/settings/adminFields.js` at this requirement.
- [x] 1.2 An e2e test or a reason-bearing `@e2e exclude` per scenario.
  - CORS: `tests/e2e/admin-message-and-portal-texts.spec.ts` (written, run by the nightly lane). Portal text and sender: `@e2e exclude` with the unit tests that assert them, both added here.
