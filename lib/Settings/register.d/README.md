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
order**. A short hash of the applied fragment set is folded into the import
version (`<version>+frag.<hash>`) so that adding, changing, or removing a
fragment forces OpenRegister's `ConfigurationService` to re-import.

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
