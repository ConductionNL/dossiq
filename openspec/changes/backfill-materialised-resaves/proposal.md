---
kind: code
depends_on: []
---

# Proposal: backfill-materialised-resaves

## Why

On :8080 (23 Oct) `occ maintenance:repair` reported "caseTypeIdentifier backfill: 0 filled, 286 failed" and "statusRole backfill: 1 failed". nextcloud.log gives one reason for every row: "Cannot modify readOnly property: caseTypeIdentifier". The case schema declares both materialised fields `readOnly`, and OpenRegister refuses an update whose payload changes a readOnly value. The backfill patched exactly that value, so it could never succeed on a real store. Woo cases without `caseTypeIdentifier` lose every Woo action.

## What Changes

- `BackfillReferencedCaseValue` no longer supplies the value. It re-saves the case unchanged (an empty patch), so OpenRegister's save-time `CalculationOnSaveListener` computes the materialised value, the same way `occ openregister:rematerialise-calculations` does. It still writes only cases whose value is missing or stale, and still reads the answer back to count `filled` or `unconfirmed`.
- Covers both subclasses: `BackfillCaseTypeIdentifier` and `BackfillCaseStatusRole`.

## Impact

- `lib/Repair/BackfillReferencedCaseValue.php`, its two tests.
- A re-save bumps the case's `updated` and writes an audit entry, as any save does. No status changes, so no transition fires.
