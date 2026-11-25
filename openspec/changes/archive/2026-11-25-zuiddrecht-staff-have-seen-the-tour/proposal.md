# Proposal: the Zuiddrecht staff have seen the tour

## Why

On the Zuiddrecht demo every staff account opened dossiq with the getting-started tour over the work it was meant to show (#3896). The tour's state is the per-user value `dossiq` / `pref_walkthrough_completed_version`, and until now OpenRegister's example-set loader could not write per-user values.

## What changes

- dossiq's `zuiddrecht.json` fragment declares `userPreferences` for the nine staff members (cast keys daan, esra, thomas, marleen, ilse, bram, petra, samira, noor), with the manifest version the tour compares against.
- A test keeps the key on `pref_` plus the manifest's `completionConfigKey`, the value on the manifest's `version`, and the list on every staff member.

## Depends on

OpenRegister REQ-EXS-024 (`userPreferences` in an example-set fragment). Until a release carries it, the loader ignores the block.
