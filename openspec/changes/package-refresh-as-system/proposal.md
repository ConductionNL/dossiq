# A package install reaches the rows it shipped

## Why

`occ dossiq:package:install jeugdwet --over-local-changes` left case e0b306d2 without the `legalBasis` and `initiatorDisplayName` that jeugdwet 1.12.0 ships. The command runs with no user, and the case schema has authorization rules: OpenRegister refused the read ("User 'Anonymous' does not have permission to 'read' objects in schema 'Case'"). The writer then did not hold the case, and the ledger refresh read it as not found and kept it. Config rows (case types, statuses) have no such rules and were refreshed, which hid the gap.

## What changes

- A package install reads and writes its own rows past the caller's access check: the writer's existence check and row write, and the ledger refresh, through `StarterStore::asSystem()`. Every caller of the installer is an administrator (admin settings, `occ`, repair steps).
- Nothing else changes: a plain install still keeps a row somebody edited here; `--over-local-changes` still decides whether the package's keys replace it; the fingerprint compares key-order-insensitively as before (`ksort`).
- `StarterStore::row()` / `save()` keep the caller's access check.
