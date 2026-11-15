# Package adoption fills the case-type fields the stored row lacks

## Why

When a package finds a case type the instance already holds (a seeder wrote it under another id), it adopts that row and writes nothing to it (decision 190). So the case-type fields the package adds, such as `recordImports` on Wmo-melding, never reach the instance. The `complaints-are-a-case-type` build had to move its case type into the register for exactly this reason.

Decision 273 (Ruben, 30 Oct 2026): adoption fills only the fields the stored row lacks and never overwrites.

## What changes

- `PackageWriter` reports, for each adopted case type, the shipped fields the stored row lacks (`AdoptedCaseTypeFill::lackedBy()`: no such key, or null). An empty string or an empty list is a value somebody set and stays.
- The fill points at this instance's ids, like every other adopted reference.
- `PackageInstaller` writes the fills after the install is recorded, so a failed install leaves an adopted row as it was. The ledger stamps the adopted row as it reads once filled.
- Only case types are filled. Statuses, roles and other adopted rows stay as they are.
- `wmo-melding` moves to 1.1.1 so instances that installed 1.1.0 by adoption re-run it on upgrade and get its `recordImports`.
