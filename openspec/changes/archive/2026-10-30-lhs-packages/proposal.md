---
kind: code
depends_on: [case-definition-packages]
---

# Proposal: lhs-packages

## Why

Hydra ADR-118 (decisions 182, 184, 185): a procedure is configuration. The default matrix of the Landelijke Handhavingsstrategie and its 16 grid cells were written by two repair steps named after the procedure. Decision 204: they ship as two case definition packages, and the seeders go.

## What changes

- The package `lib/Settings/packages/lhs-matrix/` ships with `install: auto`. It holds the default matrix (one row, 48 cells) and the 16 cells of the behaviour by consequence grid, all with fixed ids.
- The matrix is recognised by being active, not by its name. An instance that already has an active matrix keeps it and gets no second one. This is what `SeedLhsMatrix` did, and the installer can now say it (REQ-CT-56).
- A grid cell is recognised by its row and its column together. A cell the instance already holds is kept as it is, with the intervention an administrator may have changed.
- The grid cells name their schema by app, register and slug (`dossiq`, register `dossiq` or `procest`, `lhsMatrixCell`), because that schema has no settings key.
- The package `lib/Settings/packages/lhs-intervention/` ships with `install: auto`. It writes one row into OpenRegister's `decision-tables` register: the table `dossiq.lhs-intervention`, recognised by its key.
- Deleted: `Repair/SeedLhsMatrix`, `Repair/SeedVthMatrixCells`, their `info.xml` steps, `lib/Settings/seed/lhs-matrix-2024.json` and `lib/Settings/lhs_matrix_seed.json`.
- `Repair/RealignLhsActorTypeVocabulary` stays. It repairs rows on an instance, it ships nothing.
- A new sweep, `ShippedPackagesAreValidTest`, reads every shipped package with the installer's own validator.
- Each package has a Newman read-back collection and a Playwright journey.

## Impact

- A clean install gets the same matrix and the same 16 cells from the package installer.
- An upgraded instance keeps its matrix and its cells, ids included.
- `SeedVthMatrixCells` wrote nothing when the instance held any cell at all. The package writes each cell the instance lacks, once. An instance where an administrator deleted a cell gets that cell back on the first upgrade.
- `lhs-intervention` needs an OpenRegister that ships the `decision-tables` register (openregister#4572). On an older OpenRegister the package is refused, the refusal names `openregister:decision-table`, nothing is written, and the first dossiq upgrade after OpenRegister's installs it. `lhs-matrix` does not wait for it.
- The 16 rules of the intervention table are the 16 shipped grid cells (decision 213): the same intervention and the same explanation for each row and column. A lookup that reads the table first (dossiq#3774) answers what the grid answered. `LhsInterventionTableMatchesGridTest` holds the two files together.
- The fallback table inside `LhsLookupService` differs from the grid in all 16 positions. This change leaves it alone. It answers only when the instance holds neither the table nor the cell.
