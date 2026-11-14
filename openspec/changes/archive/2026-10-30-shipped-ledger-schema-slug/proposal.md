---
kind: code
---

# Proposal: shipped-ledger-schema-slug

## Why

r31 DqBeheerGeavanceerd "Wat dossiq meelevert" read "Het register bevat er 44. Geen daarvan kwam uit een startset" although :8080 has 12 packages installed and the provenance ledger holds their rows (13 case types, 88 status types, ...). The package installer stamps a row with the schema's id (`165`); the screen and the role seed use the slug (`caseType`), and the overview filtered on the slug only.

## What changes

- A ledger row is recorded under the schema's slug: a configured schema id is mapped back to its slug (SchemaSlugMap) when stamping.
- Reading (the overview, a row's state, adopt, refresh) looks under the slug and under the configured id, so the rows already recorded by id show without a reinstall; the next stamp of such a row rewrites it under the slug.
- `ShippedLedgerTargets` holds the mapping and the two-way read; `StarterStore::schemaIdOf()` names a config key's schema id.
- The state reads as the board's pill ("Ongewijzigd", "Door u gewijzigd").
