---
kind: code
---

# Proposal: dso-intake-on-by-default

## Summary

DSO intake is on by default when integriq is installed: a repair step points `dso_vergunningaanvraag_schema` at integriq's `dso_verzoek` schema when the key was never set, and a setup check warns while the key is empty.

## Why

Since `dso-single-intake-path` the listener on integriq's `dso_verzoek` is the only path from a DSO verzoek to a case, and it only runs when `dso_vergunningaanvraag_schema` is set. The key had no default, so an instance that never set it made no DSO cases and said nothing about it.

## What changes

- Repair step `DefaultDsoIntakeSchema`, in `<install>` and `<post-migration>`: when the key is absent and integriq is installed, it finds integriq's register by slug (`integriq`, then the old `openconnector`), finds `dso_verzoek` among that register's schemas, and writes the schema's id, which is what OpenRegister stores in an object's `@self.schema` and what the listener compares.
- "Never set" is an absent key. A value an admin set is kept, an empty one included (that is how an admin turns DSO intake off). Deleting the key hands it back to the step.
- Setup check `DsoIntakeCheck`: a warning while the key is empty, naming why (integriq missing, or the key emptied).

## Impact

- A fresh install or upgrade with integriq makes a case of every mapped DSO verzoek without admin action.
- An instance where an admin set the key, to a schema or to empty, is unchanged.
