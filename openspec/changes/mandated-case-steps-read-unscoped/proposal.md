---
kind: code
depends_on: []
---

# Proposal: mandated-case-steps-read-unscoped

## Why

Rehearsal R3, 16 October: Linda opened her father's case 2026-0004 under a mandate. The history and the read-only banner showed, but "Waar staat uw aanvraag?" said there were no steps yet. The steps provider reads the case inside `runAsSystem`, and OpenRegister lifts RBAC there only for a request without a Nextcloud user. Linda is signed in as herself, RBAC does not know her as the case's party, so the case read answered nothing and the steps came back empty. The history reads the case with `_rbac: false, _multitenancy: false` and was not affected. The documents provider reads each document the same way the steps did, so it had the same gap.

## What changes

1. `PortalCaseSteps` reads the case through `UnscopedObjectReader`, as the system and unscoped.
2. `PortalCaseDocuments` reads each document through `UnscopedObjectReader`.

Both are reads of one case portaliq already proved the reader may see (their own, or one they hold a mandate for). Nothing is written.

## Rollback

Revert the commit.
