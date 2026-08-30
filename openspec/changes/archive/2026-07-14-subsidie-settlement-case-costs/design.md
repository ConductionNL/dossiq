# Design: subsidie-settlement-case-costs

## 1. The link chain, verified at HEAD

There is no direct vaststelling→case reference. The only path (per
`lib/Settings/register.d/50-subsidie.json`):

```
subsidieVaststelling.subsidieuitvoering  ($ref subsidieUitvoering, CASCADE)
subsidieUitvoering.subsidieaanvraag      ($ref subsidieAanvraag,  CASCADE)
subsidieAanvraag.case                    ($ref case,              SET_NULL)  ← optional
```

`finalize()` already reads the vaststelling (for the clawback's `subsidieuitvoering` id), so the
append reuses that id and walks two more `ObjectService::find()` hops. The last hop is optional by
schema design (`SET_NULL`, no `required`) — a subsidie chain without a procest case is a
legitimate state, which is why REQ-SSC-004 makes the whole append fail-soft rather than an error.

## 2. `type` naming: a NEW `subsidy_disbursement` value, not `handling_cost`

Checked at HEAD before deciding: `case.kosten` is a JSON **string** field whose value contract
lives in its `description` and in `Iv3ReportService`'s private constants — there is no JSON-schema
`enum` keyword to extend and therefore no register-level validation to update (the "extend enum +
register version bump if needed" contingency reduces to: update the description, bump the schema
version so the documented contract propagates on re-import).

Reusing `handling_cost` was rejected: a disbursed grant is not an internal handling cost, and
collapsing them would make the two indistinguishable in the IV3 CSV. The new value follows the
existing English snake_case discriminator convention (`leges_income`, `handling_cost` →
`subsidy_disbursement`; i18n keys/discriminators are English per project convention).
`Iv3ReportService::applyEntries()` counts it toward `totalCosts` — same column as
`handling_cost` — because the IV3 report's `totalCosts` is "municipal expenditure on this case",
which a paid-out subsidie plainly is. Entries with unknown types remain ignored (pre-existing
behaviour, unchanged), so this change is forward-compatible with older report code reading newer
data: old code simply skips `subsidy_disbursement` entries rather than misclassifying them.

## 3. Idempotency: marker fields on the entry, not a read-back of the vaststelling status

Re-finalizing an already-`vastgesteld` vaststelling is possible today (`finalize()` has no status
guard — pre-existing, out of scope to change). The idempotency guard therefore lives in the data:
each appended entry carries `source: "subsidie_vaststelling"` + `vaststellingId: <id>`;
`appendKostenToLinkedCase()` decodes the case's existing kosten and skips the append when an entry
with both markers already exists. This survives concurrent/manual kosten edits (only the marked
entry is inspected) and needs no extra persisted state.

Two deliberate skip-guards besides the idempotency check:
- `bedrag <= 0.0` — a zero settlement (werkelijkeKosten 0, or fully capped away) appends nothing;
  a zero-value cost entry is noise in the IV3 report.
- `uitvoeringId === ''` — no execution linked, nothing to walk.

## 4. Write path

The append is one `ObjectService::saveObject(object: ['kosten' => json_encode(...)], register,
schema: case_schema, uuid: caseId)` — the exact same partial-patch convention `finalize()` itself
already uses for the vaststelling object two lines earlier, resolved through the same
`SettingsService::getObjectService()` bridge (ADR-022). No new service, no controller change, no
event listener: the trigger point IS the settlement finalisation, which already lives in exactly
one method.

## 5. Testing boundary

`VaststellingServiceTest` gains an in-memory `VaststellingFakeObjectService`
(`find`/`saveObject` over a schema-keyed store — same fake pattern as `Iv3FakeObjectService`;
kept faithful to the real receiver's named-argument call shape per the test-fake-drift lesson):

- happy path: entry appended with amount + type + source + vaststellingId + datum;
- no linked case (`subsidieAanvraag.case` empty): finalize succeeds, nothing appended;
- idempotency: second `finalize()` of the same vaststelling appends nothing;
- no execution id on the vaststelling: append skipped, finalize succeeds;
- zero settled amount: append skipped.

`Iv3ReportServiceTest` gains: a `subsidy_disbursement` entry counts toward `totalCosts` and never
toward `totalLegesIncome`.
