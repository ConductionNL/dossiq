# Modular register fragments (ADR-037)

Drop `*.json` fragment files in this directory to extend the Dossiq
OpenRegister configuration **without editing the monolithic
`../dossiq_register.json`**.

## Why

When several builds touch the same app concurrently, they all edit the single
`dossiq_register.json` and conflict on merge. ADR-037 lets each build instead
add its registers/schemas as an isolated fragment file here, eliminating the
shared-file contention.

## How it works

`SettingsService::loadConfiguration()` reads `dossiq_register.json` (the base),
then deep-merges every `register.d/*.json` file on top, in **sorted filename
order**.

Adding, changing or removing a fragment forces OpenRegister's
`ConfigurationService` to re-import, because OpenRegister hashes the merged
configuration itself and skips on hash equality. The fragment hash the merger
returns is deliberately **not** folded into the import version any more:
`version_compare` treats `+…` as further version parts and compares them
lexically, so whether the gate fired depended on how two hashes happened to
sort. `SettingsService::readEffectiveConfiguration()` carries the full
reasoning.

## Merge semantics

- Associative objects (e.g. `components.schemas`, `paths`) are merged
  key-by-key, recursively — disjoint fragments union cleanly.
- List (sequential-array) values from a fragment are **concatenated** onto the
  base list.
- Scalar values from a fragment **overwrite** the base value.

## Conventions

- Name fragments with an ordering prefix when order matters, e.g.
  `10-leges.json`, `20-bezwaar.json`.
- Each fragment mirrors the top-level shape of `dossiq_register.json`
  (e.g. `{ "registers": { ... }, "schemas": { ... } }`).
- This `README.md` is ignored by the loader (only `*.json` is read).
