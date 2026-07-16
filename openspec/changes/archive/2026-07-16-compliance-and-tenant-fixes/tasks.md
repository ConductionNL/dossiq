# Tasks: compliance-and-tenant-fixes

## 1. False compliance self-attestation (procest#223 finding 1) — PRIORITY

- [x] 1.1 Wire `TenantAuditTrailService::emit()` to a durable sink — one hash-chained
      OpenRegister audit row via `AuditTrailMapper::createAuditTrailEntry`, anchored to
      the tenant ObjectEntity (ADR-022; same sink as `ParaferingAuditListener`).
- [x] 1.2 Return `persisted:true|false` from `emit()`; swallow audit-write failures so
      they never break the audited mutation, but never report them as success.
- [x] 1.3 Give every `hardeningChecklist()` entry an explicit `status`; probe the
      audit claim LIVE via `auditSinkAvailable()` and fail closed to `unverified`.
- [x] 1.4 Downgrade `isolation_pen_test` to `unverified` — no pen-test executes.
- [x] 1.5 Correct the `audit_logged_mutations` evidence string to name the real callers.
- [x] 1.6 Emit audit entries from `TenantSaasService::create()` and `::updateStatus()`.
- [x] 1.7 Tests: durable row written + anchored; fails closed with no sink; no tenantId
      does not claim persistence; checklist status honest both ways.
- [x] 1.8 Verify the guard is real — `testEmitWritesADurableAuditRow` FAILS against the
      pre-fix log-only `emit()`.

## 2. Tenant billing was EUR0 (procest#223 finding 2)

- [x] 2.1 Chain the orphaned pipeline into `TenantBillingService::runInvoicing()`:
      fetch month's unbilled events → aggregate → build payload → export → markExported.
- [x] 2.2 Register `ShillinqIntegrationService` with base URL + API key from app config
      (its string ctor args defaulted to `''` → "not configured" → nothing exported).
- [x] 2.3 Emit the tier subscription billing line at go-live (`TenantOnboardingService::activate`).
- [x] 2.4 Route + admin-guarded controller methods: `billingSummary`, `runBilling`.
- [x] 2.5 Tests: non-zero invoice amount for known usage; export marks events.

## 3. AWB 7:10 / 4:17 never computed (procest#223 finding 3)

- [x] 3.1 Verify the engine's real contract against OpenRegister origin/development
      (READ-ONLY): `CalculationAnnotationValidator` shape, `VALID_OPS`,
      `intervalFromAmountUnit` units. Confirmed the ARRAY string-DSL form was inert.
- [x] 3.2 Re-declare as a field-keyed map of AST expressions with `materialise` flags
      (`decisionDeadline` materialised; `dwangsom` virtual — it references `now()`).
- [x] 3.3 Fix the null-amount defect: coalesce `verdagingsperiode` / `opschorting` to 0,
      or `dateAdd` returns null and the whole statutory deadline nulls out.
- [x] 3.4 De-drift the test mirror — reproduce `intervalFromAmountUnit`'s null semantics
      instead of casting null to 0.
- [x] 3.5 Prove the AWB worked examples: deadline dates + tiered dwangsom amounts.

## 4. Subsidie REQ-SUB-007/008 (procest#229) — product decision

- [x] 4.1 Verify against HEAD: `isVoorschotReleasable`, `requiresStaatssteunGrondslag`,
      `verifyHash`, `assertMutable` each have exactly one definition and ZERO callers.
- [x] 4.2 DECISION (design.md §4): do NOT build blind — both requirements hinge on
      cross-app integration contracts (Docudesk PDF/A handover, TAM register /
      OpenConnector) that are product decisions.
- [x] 4.3 Downgrade `openspec/specs/subsidieverlening-keten` `done` → `partial` with a
      note naming each unreachable path. Tracked as feature work in procest#229.

## 5. Verification

- [x] 5.1 Full suite in a php:8.3-cli container: baseline (origin/development 277f3117d)
      1666 tests / 4 errors → branch 1681 tests / 4 errors. +15 new, all green.
- [x] 5.2 Prove the 4 errors pre-existing by diffing failing test NAMES against a
      pristine baseline worktree, same filter both sides — byte-identical
      (`ZipArchive` ext missing from the bare container; not a code regression).
- [x] 5.3 `openspec validate --specs --strict` passes for every spec touched here.
- [x] 5.4 Anchor all new `@spec` tags at canonical `openspec/specs/` paths, not a
      change dir that moves on archive.
