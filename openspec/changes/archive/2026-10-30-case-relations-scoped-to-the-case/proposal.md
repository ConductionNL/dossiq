# Proposal: case-relations-scoped-to-the-case

## Why

DqZaakGerelateerd r36: the "Wacht op" group was never drawn. Case 2026-0110 holds subjectCases and blockingCases, and OpenRegister's /uses answers both typed rows, yet GET /api/cases/{id}/relations answered only the subject, as a legacy row. In a web request OpenRegister's object service is shared and keeps the register and schema an earlier call set; getObjectUses() and getObjectUsedBy() then look the case up in that schema's table, log "Object not found in magic table" and answer nothing. Only the legacy relatedCases entry was left.

## What changes

CaseRelationStore sets the configured register, then the case schema, before it asks for a case's uses or used-by, as OpenRegister's own controllers do. Every reader of typed relations goes through it (the related cases card, normalise and cleanup, dependent term offers).
