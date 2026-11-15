---
kind: code
depends_on: []
---

# Proposal: a-portal-filed-case-opens-like-a-staff-created-one

## Why

Probe on :8080, 14 October (case 2026-0104): a Woo request filed through the portal got no acknowledgement of receipt, no handling group, no confidentiality and no moment it entered its first status.

Two causes, both generic:

1. `SystemCaseStore::write()` saves inside OpenRegister's `runAsSystem()`. OpenRegister withholds `ObjectCreatedEvent` for every write in a system operation (`MagicMapper::suppressLifecycleEvents()`), so seed imports do not wake every app. A portal case is not seed data, yet every listener that answers a new case missed it: `AcknowledgementOnCreateListener` (no `AcknowledgementDispatchJob`, so no receipt and no `acknowledgementDuty`), the term, the chain of custody, the case number backfill and `WooStartFromListener`. The pre-save `ObjectCreatingEvent` is not withheld.
2. A staff create gets its opening fields from the create form (the `caseType` prefill and the form's own fields). A system write has no form.

## What changes

1. `CaseOpeningDefaults` fills a new case's empty opening fields from its case type: first status, handler (`handling.defaultHandler`, else `defaultAssignee`), handling group (`handling.defaultGroup`), confidentiality, and `currentStatusEnteredAt`. A value the caller wrote is kept.
2. `CaseCreationAnnouncer` dispatches the withheld `ObjectCreatedEvent` once after the system write, inside a system operation so listeners can read and write the new case although the portal request is anonymous.
3. `SystemCaseStore::write()` applies both. It announces only when the write ran through `runAsSystem()`; without it the save announced itself.

Team access needs no write: it is projected onto the case schema per case type (`CaseTeamAccessProjector`, matching `caseType`). On :8080 the Woo case type's `handling` is empty because dossiq #3865 was never upgraded there; that is a deploy step, not this change.

## Not changed

- OpenRegister's suppression stays as it is: it is right for imports.
- Listeners' own follow-up writes run in the system operation and are quiet, as every system write is.

## Rollback

Revert the commit. Portal cases are then written unannounced again.
