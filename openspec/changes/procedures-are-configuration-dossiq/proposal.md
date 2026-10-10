## Why

dossiq builds Dutch procedures as code: 229 procedure-named files and 109 standards-named files under `lib/` and `src/` on 10 October 2026. The same capability exists several times under procedure names: four deadline timers, four decision documents, one seeder per procedure. Ruben decided on 10 October 2026 (decisions 182, 184, 185) that a procedure is configuration and code is generic. This change adopts the fleet rule for dossiq.

The canonical change is hydra `openspec/changes/procedures-are-configuration/` with ADR-118. This change holds dossiq's inventory and the dossiq side of the lane plan.

## What changes

- `inventory.md`: every procedure or standard class under `lib/`, what it does, its kind, the capability it maps to and where it goes.
- dossiq ships each procedure as a case definition package under `lib/Settings/packages/<package-id>/`, installed by the extended `CaseDefinition` writer and recorded in the shipped-set ledger.
- Procedure unit tests are replaced by a Newman collection and a Playwright journey per package as each procedure moves.
- Refactor lanes R1 to R11 move the classes, in the order and with the file coordination in hydra's tasks.md.

## What does not change

- Open pull requests that are ready land as they are (decision 184).
- The ZGW provider surface stays in dossiq, allowlisted.
- No class moves in this change.

## Impact

About 230 classes over the programme. 179 of the 968 files under `tests/Unit/` are named after a procedure or a standard; the procedure ones go as their procedure moves. The eleven build lanes keep building, generic plus configuration (decision 182).
