# Design: iv3-case-cost-reporting

## Cost data: existing fields vs new

**Decision: add a new, lightweight `kosten` field on `case`. Do not reuse
Subsidie's financial fields as the canonical source for v1; do not attempt a
Pipelinq integration.**

Checked at `origin/development` HEAD before deciding:

| Candidate | Verdict |
|---|---|
| `case.paymentIndication` / `case.lastPaymentDate` | Status-only enum + date, no amount field. Not usable. |
| `caseType.productsOrServices` → Pipelinq `product` | References a *fee catalogue* entry, not a transaction amount. Per ADR-003 (commit `80a76d66d`) the actual charge lives in Pipelinq as a financial transaction, fetched over an HTTP boundary procest does not currently have for this data. Building that boundary is real, separable work (mirrors `OpenCatalogiApiClient`) and is out of scope for this change (no new cross-app coupling, no new composer deps). |
| `subsidieAanvraag.aangevraagdBedrag`, `subsidieUitvoering.werkelijkeKostenTotaal`, `subsidieVaststelling.vastgesteldBedrag` | Real money, but on a 3-hop object chain (`subsidieAanvraag` → `subsidieUitvoering` → `subsidieVaststelling`) linked to a case only via `subsidieAanvraag.case`. Reusing this as *the* per-case cost source for a generic, all-case-types report would require `Iv3ReportService` to join four schemas and would tie IV3 reporting's correctness to the Subsidie domain's specific lifecycle (an aanvraag may have no uitvoering/vaststelling yet, may have several, etc.). |

The subsidie domain is exactly the kind of case where a controller *does*
already have a settled amount (`vastgesteldBedrag`) sitting on a linked
object. Ignoring it entirely would be wasteful, but folding a 3-hop join into
a "pure, unit-testable aggregation over OR objects (single read path)" (task
brief) is disproportionate for v1. **Fast-follow, not this change**: when a
`subsidieVaststelling` is finalized, `VaststellingService` (or a listener) can
write a `case.kosten` entry (`type: handling_cost`, `bedrag:
vastgeseldBedrag`, `datum: vaststellingsdatum`) so the case-level record stays
the single source `Iv3ReportService` reads, without `Iv3ReportService` itself
needing to know the Subsidie object graph. Filed as a task in `tasks.md`
follow-ups, not implemented here.

`case.kosten` follows the existing "JSON-encoded array in a string field"
convention already used by `case.statusHistory`, `case.activity`, and
`subsidieAanvraag.begroting`/`cofinancieringList` — not a native JSON-schema
`array` type. This keeps the new field consistent with how procest already
models append-only per-case logs, and sidesteps whatever historical
constraint (DB/index) drove that convention originally (not re-litigated
here).

```json
"kosten": {
  "type": "string",
  "description": "JSON-encoded array of cost entries: [{bedrag, type, datum}]. type is 'leges_income' or 'handling_cost'. IV3-reporting record only — not a general ledger; see Iv3ReportService.",
  "visible": false,
  "title": "Costs"
}
```

Each decoded entry: `{"bedrag": 1234.56, "type": "leges_income", "datum": "2026-04-14"}`.
`bedrag` is a plain EUR float (matches `subsidieAanvraag.aangevraagdBedrag`'s
convention — this codebase does not use integer cents for case-level money;
only the termijn/dwangsom domain uses `*Cents` fields).

## Taakveld list source

Ship a single versioned JSON, `lib/Settings/iv3_taakvelden.json`, containing
the BBV/IV3 functional classification: 9 main categories (`0`–`8`) and their
subcodes. Cross-checked against two independently fetched sources during
research (gemeente Leiden's published `jaarstukken` taakveld overview and
gemeente Eindhoven's `jaarverslag` taakveld explainer), which agree on the
code set below (the pre-2023-refinement social-domain granularity — see
"Known limitation" below).

```
0  Bestuur en ondersteuning        0.1 0.2 0.3 0.4 0.5 0.61 0.62 0.63 0.64 0.7 0.8 0.9 0.10 0.11
1  Veiligheid                      1.1 1.2
2  Verkeer, vervoer en waterstaat  2.1 2.2 2.3 2.4 2.5
3  Economie                        3.1 3.2 3.3 3.4
4  Onderwijs                       4.1 4.2 4.3
5  Sport, cultuur en recreatie     5.1 5.2 5.3 5.4 5.5 5.6 5.7
6  Sociaal domein                  6.1 6.2 6.3 6.4 6.5 6.6 6.71 6.72 6.81 6.82
7  Volksgezondheid en milieu       7.1 7.2 7.3 7.4 7.5
8  Volkshuisvesting, ruimtelijke ordening en stedelijke vernieuwing  8.1 8.2 8.3
```

`Iv3TaakveldList::allTaakvelden()` flattens this to a list of
`{code, label, categoryCode, categoryLabel}`; `isValidCode()`/`labelFor()`
are the single testable entry points other code (aggregation, CSV, frontend
API) must go through instead of re-encoding the list.

**Known limitation, documented not silently shipped**: CBS's 2023 refinement
survey split the Wmo/Jeugd subcodes under taakveld `6` into finer codes
(e.g. `6.71`/`6.72` → `6.711`/`6.712`/`6.713`/…). This change ships the
pre-refinement, still-widely-used code set (matching both cross-checked
sources) as `iv3_taakvelden.json`'s v1 content, with a `version` key
(`"iv3-bbv-v1"`) so a follow-up can add the refined `6.x` set as new entries
without a breaking rename — existing `caseType.iv3Taakveld` values keep
resolving. A municipality that has already adopted the finer 2023 codes will
need that follow-up before this feature is accurate for their taakveld `6`
reporting; this is called out in the PR description, not hidden.

## Aggregation semantics (`Iv3ReportService`)

`generateQuarterlyReport(int $year, int $quarter): array`

1. Resolve `[from, until]` date bounds for the quarter (same
   `(quarter-1)*3+1 .. +2` month arithmetic as
   `TermijnReportingService::resolveQuarter()`, reimplemented locally —
   small enough that extracting a shared trait for one three-line helper
   isn't worth the indirection).
2. Load all `caseType` objects once via `SearchesObjects::searchObjectsAsArrays()`
   and build an in-memory `caseTypeId => iv3Taakveld|null` map. This is a
   second `ObjectService` read, not raw SQL — consistent with "single read
   path via ObjectService", just two schemas instead of one, which is
   unavoidable for a `case` → `caseType` join done through OpenRegister's
   object API rather than a bespoke SQL join.
3. Load all `case` objects the same way. For each case:
   - Decode `kosten` (`json_decode`, empty array on decode failure —
     defensive, matches how `TermijnReportingService` treats missing/odd
     data as zero rather than throwing).
   - Keep only entries whose `datum` falls within `[from, until]`
     (string comparison on `YYYY-MM-DD`, same technique
     `TermijnReportingService::listInstances()` uses for date-range
     filtering).
   - Skip the case entirely if it has no qualifying entries this quarter —
     a case with no cost activity in the quarter contributes nothing to
     that quarter's report (it will appear in whichever quarter its cost
     entries actually fall in).
   - Resolve taakveld via the caseType map. `null`/unresolvable → bucket
     `uncategorized` instead of a taakveld code.
   - Sum qualifying entries into that bucket: `caseCount` (distinct cases,
     incremented once per case, not per entry), `totalCosts` (sum of
     `handling_cost` entries — "recorded costs" per the task brief),
     `totalLegesIncome` (sum of `leges_income` entries).
4. Reduce: `avgCostPerCase = totalCosts / caseCount` (0.0 when
   `caseCount === 0`, never a division-by-zero warning).
5. Return shape:
   ```
   {
     year, quarter, from, until,
     perTaakveld: { "<code>": { taakveldLabel, caseCount, totalCosts, totalLegesIncome, avgCostPerCase }, ... },
     uncategorized: { caseCount, totalCosts, totalLegesIncome, avgCostPerCase } | null,
     metadata: { generatedAt, taakveldListVersion }
   }
   ```
   `uncategorized` is `null` (omitted from CSV) when no case qualified, not
   an all-zero object — callers can tell "no uncategorized spend" apart from
   "field not computed". An empty quarter (no case anywhere has a qualifying
   entry) returns `perTaakveld: {}`, `uncategorized: null` — a defined,
   testable shape rather than an error.

`asCsv(array $report): string` — header row
`taakveld,label,caseCount,totalCosts,totalLegesIncome,avgCostPerCase` (plus
an `uncategorized` row with `taakveld=""`, `label="Uncategorized"` when
present), built via `fputcsv()` over a `php://temp` stream (the
`AiAuditExportController::buildCsv()` pattern — proper CSV quoting), not the
older `implode(',')` pattern in `TermijnReportingService::quarterlyReportAsCsv()`.

## Auth

`Iv3ReportController` follows `AiAuditExportController`'s gate exactly:
`IGroupManager::isInGroup()` against an allow-list
(`['controllers', 'beheerders', 'admin']` — "controllers" is the literal role
these figures are for; "beheerders"/"admin" mirror the existing sibling
exports), falling back to `isAdmin()`. `GET .../taakvelden` (the picker's
data source) is intentionally **not** gated the same way — any authenticated
user needs it to render the case-type settings picker and the report filter
dropdown, and the taakveld list itself is not sensitive (it is a public CBS
classification). Only the cost *figures* (`GET /api/reports/iv3`) are gated.

## Frontend

`Iv3ReportDashboard.vue` is a near-structural copy of
`TermijnDashboard.vue`'s report section (year/quarter controls, table,
CSV-download-via-blob button) — deliberately reusing that shape rather than
inventing a new one, per the task brief's "follow the existing CSV export
convention found in termijn quarterly reports". Registered exactly like
`TermijnDashboard`: `customComponents.js` import + `manifest.json` `type:
"custom"` page + `menu-layout.json` entry placing it in the existing
`AnalyticsGroup` ("Reports") section next to `Analytics`
(`Doorlooptijd`) and `TermijnDashboardMenu`.

`GeneralTab.vue` gains one more `NcSelect` field (`iv3Taakveld`), following
the `confidentiality`/`origin` picker pattern exactly
(`selected<Field>` computed + `<Field>Options` computed), except its options
come from `GET /api/reports/iv3/taakvelden` (fetched once on mount) instead
of a local hard-coded list — this is the "one testable place" the task
brief asks for: the taakveld list is owned by the backend
(`Iv3TaakveldList`), not duplicated as a second hand-maintained JS array that
could drift from it.
