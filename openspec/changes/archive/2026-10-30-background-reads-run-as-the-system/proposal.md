---
kind: code
depends_on: []
---

# Proposal: background-reads-run-as-the-system

## Why

Rehearsal R2 (15 Oct): `occ openregister:example-set:install` logged 44 dossiq lines with "User 'Anonymous' does not have permission to 'read' objects": 20 from the case plan projection's case-type lookup, 12 from the statutory term's case-type slug lookup, and 12 timeline entries (contactmoment, portaalbericht) that were never written. An occ run, an import or cron has no user, and OpenRegister refuses an anonymous read. #3965 fixed the same for the document projection.

## What Changes

- `SearchesObjects::asSystemWithoutUser()` runs an OpenRegister operation as the system only when nobody is signed in. A signed-in caller keeps their own rights; nothing elevates a request that has a user.
- `CasePlanProjectionService::findCaseType`, `CaseTypeSlugResolver::readSlug` and `CaseTimeline::record` go through it, each with an optional `IUserSession`.

## Impact

- `lib/Service/Support/SearchesObjects.php`, `lib/Service/CasePlanProjectionService.php`, `lib/Service/CaseTypeSlugResolver.php`, `lib/Service/Timeline/CaseTimeline.php`.
- System context, no `_rbac` flags: the operations run through OpenRegister's `runAsSystem`.
