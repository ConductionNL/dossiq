## 1. Backend — fail closed on missing secret

- [ ] 1.1 In `lib/Controller/DwangsomPaymentCallbackController::validateSignature()`, remove the
      `$secret === ''` early-return-`true` branch; return `false` (and log at `warning`, not
      `info`) when the secret is unconfigured
- [ ] 1.2 Confirm `callback()` still returns HTTP 401 with the existing
      `{'message' => 'Invalid or missing signature'}` body on the now-closed path (no new response
      shape needed — the 401 branch already exists at line 94)
- [ ] 1.3 Add/extend a PHPUnit test in the existing test file for this controller: assert a request
      with no configured secret is rejected (401), and a request with a configured secret + correct
      HMAC is accepted

## 2. Admin settings — make the secret actually configurable

- [ ] 2.1 Add a `dwangsom_callback_secret` field to the procest admin settings Vue page (password/
      secret-style input, not plain text display) that writes via the existing settings POST
      endpoint into `IAppConfig`
- [ ] 2.2 Add a "generate random secret" convenience action (client or server-side) so admins are
      not required to hand-craft an HMAC key
- [ ] 2.3 Document the required `X-Procest-Signature` HMAC-SHA256 scheme for the external
      ERP/openconnector integrator in the admin settings help text (points at
      `openspec/specs/financial-integration/spec.md`)

## 3. Setup/health surfacing (ADR-042)

- [ ] 3.1 Extend `SetupController::status()` (or the equivalent health-check surface) to report a
      `dwangsom_callback_secret_configured: bool` flag when the financial-integration capability
      is enabled
- [ ] 3.2 Surface a visible warning on the setup wizard `summary` step / admin settings page when
      the flag is false and the dwangsom module is active, so the gap is caught before go-live

## 4. Spec + traceability

- [ ] 4.1 Add the MODIFIED requirement in
      `openspec/changes/enforce-dwangsom-callback-signature/specs/financial-integration/spec.md`
      (this change) and run `openspec validate enforce-dwangsom-callback-signature --strict`
- [ ] 4.2 Add `@spec openspec/changes/enforce-dwangsom-callback-signature/specs/financial-integration/spec.md`
      to the touched controller/service methods
- [ ] 4.3 Fix any pre-existing PHPCS/PHPStan/PHPMD warnings encountered in the touched files while
      implementing this change (project convention — do not defer)

## 5. Verification

- [ ] 5.1 Live-verify: with no secret configured, POST to
      `/api/dwangsom/payment-callback` returns 401 (previously would have returned 200/201)
- [ ] 5.2 Live-verify: with a secret configured via the new admin settings field and a correctly
      signed request, the callback still processes and flips the uitbetaling to `betaald`
