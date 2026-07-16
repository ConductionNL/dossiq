# Proposal: compliance-and-tenant-fixes

kind: code — compliance + correctness fixes. Closes procest#223 (false compliance
self-attestation, orphaned tenant billing/audit wiring, inert AWB calculation) and
procest#229 (unwired subsidie capabilities).

## Why

Three defects shared one shape: **the code was present and the tests were green, but
the feature did not run** — and in each case the app told the operator otherwise.

### 1. False compliance self-attestation (the headline)

`TenantAuditTrailService::hardeningChecklist()` attested, as a flat unconditional
claim, that "All mandate, status, and provisioning mutations emit an audit entry",
citing `TenantAuditTrailService::emit` as its evidence.

`emit()` wrote a single `logger->info()` line and nothing else. It had no durable
sink at all — no audit row, nowhere to query, nothing tamper-evident. Worse, the
cited provisioning/status callers did not exist: `TenantSaasService::create()` and
`::updateStatus()` never called `emit()`. So the checklist asserted a control that
was doubly absent.

A false compliance attestation in a government system is the most serious defect
here: it does not merely fail to protect, it actively tells an auditor the control
is in place. `isolation_pen_test` had the same problem in milder form — listed as a
checklist item while its own evidence string admitted it was "deferred".

### 2. Tenant invoices were EUR0 and audit rows were never written

`emitEvent` / `exportInvoice` / `buildInvoicePayload` / `markExported` all existed
and were unit-tested, but nothing chained them: `exportInvoice` had zero callers, no
route ran invoicing, and `ShillinqIntegrationService` was never given its endpoint or
API key (its string ctor args defaulted to `''`, so it short-circuited to "not
configured"). Every tenant invoice was EUR0 — an orphaned-capability defect.

### 3. AWB 7:10 beslistermijn + AWB 4:17 dwangsom never computed

The bezwaar schema declared `x-openregister-calculations` as an ARRAY of objects
carrying a string DSL (`"addWeeks($.ontvangstdatum, 6)"`). OpenRegister's engine
only honours a field-keyed MAP of AST expressions, so the declaration was silently
inert — while `legalSource: "AWB Art. 7:10"` fields made it read as a live
compliance artifact. A statutory objection deadline and a statutory penalty payment
simply never computed.

### 4. Subsidie REQ-SUB-007/008 attested `done` while unreachable

`openspec/specs/subsidieverlening-keten` carried `status: done`, justified by
"capability code confirmed present on development" — the orphaned-capability
fallacy stated outright. See design.md for the product decision.

## What changes

- `emit()` writes a real hash-chained OpenRegister audit row via
  `AuditTrailMapper::createAuditTrailEntry`, the same sink `ParaferingAuditListener`
  uses (ADR-022). It reports `persisted:true|false` honestly.
- `hardeningChecklist()` entries carry an explicit `status`, and the audit claim is
  **probed live** and fails closed to `unverified`. No hardcoded pass.
- `TenantSaasService::create()/updateStatus()` actually emit their audit entries.
- Billing is chained end-to-end (`runInvoicing`) behind two routes, go-live emits the
  tier subscription line, and Shillinq is configured from app config.
- The bezwaar calculations are re-declared in the shape the engine actually runs,
  verified against OpenRegister origin/development, and made null-safe.
- `subsidieverlening-keten` is downgraded `done` → `partial` with an honest note.

## Impact

- Affected specs: `tenant-compliance`, `tenant-billing`, `bezwaar-lifecycle`,
  `subsidieverlening-keten` (status downgrade).
- Affected code: `TenantAuditTrailService`, `TenantSaasService`, `TenantBillingService`,
  `TenantOnboardingService`, `TenantSaasController`, `Application`, `routes.php`,
  `lib/Settings/procest_register.json`.
- Behaviour change: tenant mutations now write durable audit rows; a bezwaar's
  `decisionDeadline` is materialised on save. No API is removed.
