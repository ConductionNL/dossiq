# Design: compliance-and-tenant-fixes

## Decision 1 — an attestation must be derived, never asserted

`hardeningChecklist()` was a hardcoded list of claims. The failure mode is
structural: a static string cannot notice that the thing it describes stopped
working (or never started). `emit()` could be gutted to a no-op and the checklist
would still report compliance.

**Decision:** every checklist entry carries an explicit `status` of `pass` or
`unverified`, and any claim that *can* be probed *is* probed:

```php
$auditStatus = 'unverified';
if ($this->auditSinkAvailable() === true) {
    $auditStatus = 'pass';
}
```

`unverified` is deliberately not `fail` — the control may well be fine; we simply
cannot prove it from here, and a compliance artifact must not launder "unknown" into
"compliant". This is the fail-closed direction: absence of evidence downgrades the
claim. `isolation_pen_test` is now `unverified` for the same reason — no pen-test
executes today, and its evidence string says so plainly instead of implying a
verified control.

Rejected: computing a single boolean "compliant" flag. It would collapse exactly the
distinction that matters to an auditor (proven vs assumed).

## Decision 2 — audit rows go to OpenRegister, not a parallel store

The tenant trail needed a durable sink. Two options:

1. Add a `tenantAuditEntry` schema to the register and write objects to it.
2. Write to OpenRegister's existing hash-chained audit trail.

**Chose (2).** Procest already made this decision for the parafering trail
(`migrate-parafering-to-or-audit`, ADR-022): OR's audit trail is hash-chained and
natively immutable — it rejects PUT/DELETE — whereas a `tenantAuditEntry` object
store would be an ordinary mutable register with no chain and no tamper evidence,
i.e. a *worse* audit log wearing the same name. `ParaferingAuditListener` already
proves the call shape. Option (1) would also have re-introduced the parallel
write path that migration explicitly removed.

Consequence: an audit row anchors to an ObjectEntity, so `emit()` resolves the
tenant entity by `tenantId`. Where it cannot (no `tenantId`, OR absent), it returns
`persisted:false` rather than throwing — an audit failure must never break the
mutation being audited — and the checklist degrades to `unverified` accordingly.

## Decision 3 — verify the calculation dialect against the engine, not the docs

The WIP rewrote the bezwaar calculations into an AST. That AST was *invented*, and
the fleet has repeatedly shipped annotations in a dialect the engine silently
ignores (lifecycle `initialState` vs `initial`; fragments without `slug`). So the
declaration was checked against OpenRegister `origin/development` (2d50c8b0c),
read-only:

- `CalculationAnnotationValidator` — confirmed the accepted shape is a field-keyed
  map of `{type, expression, materialise?}`. The WIP's shape is correct; the ARRAY
  form it replaced was indeed inert.
- `CalculationEvaluator::VALID_OPS` — confirmed every operator used
  (`dateAdd`, `dateDiff`, `now`, `coalesce`, `min`, `max`, `prop`, `+ - *`) is real.
- `intervalFromAmountUnit()` — confirmed units `days|weeks|months|years`.

That last read surfaced a live defect: `intervalFromAmountUnit()` returns `null` for
a **non-numeric** amount, and `dateAdd` returns `null` for a null interval. The
declaration passed `{"prop": "verdagingsperiode"}` straight in as `amount`. So any
bezwaar saved without that optional field — the *ordinary* case — would have had its
entire AWB 7:10 deadline computed as `null`. The schema default of `0` does not save
it: OR's `saveObject` is PUT-semantic and omits absent properties.

**Decision:** coalesce optional numeric props to `0` at the declaration
(`{"coalesce": [{"prop": "verdagingsperiode"}, 0]}`) rather than rely on defaults.

`decisionDeadline` is `materialise: true` (not time-dependent → server-side
filterable); `dwangsom` is `materialise: false` (references `now()` → must be
computed at read time or it goes stale and misstates a penalty).

### The test mirror was drifting

`BezwaarCalculationRegistryTest` evaluates the AST with a local mirror of the
evaluator. The mirror cast `dateAdd`'s amount with `(int)`, turning `null` into `0`
— strictly *more lenient* than the engine. The suite would have stayed green while
production nulled the deadline: a textbook test-fake drift. The mirror now
reproduces `intervalFromAmountUnit`'s null semantics exactly, and
`testDecisionDeadlineSurvivesAbsentOptionalFields` fails without the coalesce.

A mirror is still a fake. It is retained because the real evaluator is not
autoloadable from procest's unit-test container, and the test at least reads the
ACTUAL AST from the register file. Its operator allowlist is pinned to the engine's
`VALID_OPS` so an unsupported operator fails the build.

## Decision 4 — subsidie REQ-SUB-007/008: downgrade the spec, do not build blind

The brief offered: wire a minimal real path if the intent is clear and small, or
downgrade the spec status honestly and name it as tracked feature work.

**Chose to downgrade.** These are not small, and the intent is not clear enough to
implement without a product decision. The spec's own scenarios require:

- REQ-SUB-007: a nightly archief-trigger, bundling bewijsstukken with metadata,
  **PDF/A conversion via Docudesk**, transfer to a Docudesk archief-handover with a
  retention code, plus SHA-256 verification on every read and document locking.
- REQ-SUB-008: a de-minimis gate at assessment, **AGVV classification against the
  TAM register**, emitting `AgvvMeldingReadyEvent` to OpenConnector, and
  cofinanciering sum validation.

Both hinge on cross-app integration contracts (Docudesk archival handover, TAM
register / OpenConnector) that are product decisions, not implementation details.
Guessing them would produce exactly the defect this engagement exists to remove: a
capability that looks implemented and does not run.

`isVoorschotReleasable()` is likewise unwired and `approveReport()` only sets a
status — there is no release engine behind it, so an approved report releases no
money. Wiring `isVoorschotReleasable()` into `approveReport()` was considered and
rejected: releasing a voorschot is a *financial disbursement*, and inferring its
trigger conditions from an unwired predicate is not a safe guess.

The code is left in place (it is correct and unit-tested) and
`openspec/specs/subsidieverlening-keten/spec.md` goes `done` → `partial` with a note
naming each unreachable path. Tracked as feature work in **procest#229**. The spec
must not return to `done` until those paths execute.
