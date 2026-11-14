---
kind: code
depends_on: []
---

# Proposal: decision-tables-live-in-openregister

## Why

ADR-118 C15 and decision 186. Dossiq kept its own decision tables (schema `decisionTable`, `Dmn/DecisionTableService`, a CRUD and evaluate API at `/api/decisions`, and a settings tab), copied the table inline into every flow step it translated, and looked the LHS table up by a marker in its description. openregister#4572 gives the fleet one `decision-tables` register, a `DecisionTableStore` that evaluates a table by key, and a `tableRef` on the decision-table flow step. A table should live once, where OpenRegister evaluates it.

## What changes

- New generic `Dmn\StoredDecisionTables`: the stored key of a dossiq table (`dossiq.` plus the key in OpenRegister's pattern), evaluation and lookup through OpenRegister's `DecisionTableStore` (resolved lazily; absent means null), and writes into the register owned by `dossiq`.
- New repair step `MoveDecisionTablesToOpenRegister` (post-migration): copies every enabled `decisionTable` row into the register under its stored key. A table already under its key is kept, a disabled table stays in dossiq (OpenRegister has no switch), a refused one stays and is logged. The old rows are not deleted.
- `Flow\RetiredNodeTranslator` writes `tableRef` on the `openregister.decision-table` step instead of the table itself.
- LHS: `Vth\LhsDecisionTableLookup` evaluates `dossiq.lhs-matrix-<version>` through the store (it looked the table up by a marker); `Vth\LhsMatrixDecisionTableMigrator` projects into the register under that key; `LhsLookupService` asks `dossiq.lhs-intervention` first and keeps its matrix-cell and embedded fallbacks until the LHS package seeds that table.
- Retired: `Dmn/DecisionTableService`, `DecisionTableController` and its five `/api/decisions` routes, `DecisionTablesTab.vue`, `decisionTableApi.js`, `decisionTableHelpers.js` and their tests. Tables are authored in OpenRegister.

## Depends on

openregister#4572 (the register, the store and `tableRef`). Without it the repair moves nothing and says so, the translator refuses a decision step, and the LHS lookups fall back as before.

## Known gaps

- The `decisionTable` schema stays in dossiq's register for the rows that did not move; retiring it is a later step.
- `LhsController` is untouched (dossiq#3297 is open on it).
- The LHS seed (`dossiq.lhs-intervention`, 16 rules) belongs in the LHS package of lane R1.
