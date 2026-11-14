---
kind: code
depends_on: []
---

# Proposal: bezwaar-audit-onto-openregister-trail

Gate 23 prerequisite, built before the Woo changes (Ruben, 2026-10-08).

## Summary

Every Awb and AVG tagged entry of the bezwaar procedure (awb-art-7:2 to 7:13, avg-art-6) is written to OpenRegister's hash-chained audit trail of the record it describes, with its tag, actor, time and payload, instead of into an editable `auditTrail` array on that record; the entries already in those arrays are copied across once and the arrays are left as written.

- Gate 23 (`or-abstraction-anti-patterns`): clears rule 2 `consume-or-audit-trail-fleet-wide` on `lib/Service/Bezwaar/BezwaarAuditTrail.php`. The gate counts a `*AuditTrail*.php` file as compliant when its code calls `AuditTrailMapper` or `createAuditTrailEntry`, which this change makes true.
- Statutory: Awb art. 7:2, 7:3, 7:4, 7:6, 7:7 and 7:13 (hoorzitting, afzien, inzage, verslag, adviescommissie) and AVG art. 6 (consent for an audio recording). The entries are the record that each step happened.
- Dependencies: none. OpenRegister's `AuditTrailMapper::createAuditTrailEntry()` is on `development` and already called by three dossiq classes. Independent of `dossiq/tenancy-onto-openregister-organisation` (https://github.com/ConductionNL/dossiq/issues/3466).
- Decision: Ruben 2026-10-08 (gate 23 work is built before the Woo changes).
- Build rules: openspec/woo-build-rules.md

## Why

Read on `development` at 0f95836e9.

`BezwaarAuditTrail::append()` takes the array already on the object, adds
`{event, tag?, actor, at, payload}`, and hands the array back for the caller to save onto the
object's `auditTrail` property. Two schemas carry that property: `hearingSession` and
`bacAdviceRequest`. So the record that a hearing was scheduled, a waiver given or an advice signed
is a JSON array on the same object, writable by anyone who may write the object, and not part of
OpenRegister's hash chain. Gate 23 rule 2 names the file for exactly that.

OpenRegister has a public write path a leaf app may use, and dossiq already uses it:
`AuditTrailMapper::createAuditTrailEntry(ObjectEntity $object, string $action, array $context = [], ?string $actorId = null, ?string $actorName = null, ?string $ipAddress = null): AuditTrail`
on `ConductionNL/openregister` branch `development` (`lib/Db/AuditTrailMapper.php`). It writes one
row on the object's trail, sealed into the hash chain, with the session user as actor when
`$actorId` is null and `system` when there is no session. `TenantAuditTrailService`,
`CaseRecycleService` and `CaseDestructionService` call it today. Reading back:
`AuditTrailMapper::findAll()` filters on `object_uuid` and on an action prefix such as
`dossiq.bezwaar.*`.

### The 17 call sites

| # | File and line | What it does today |
| --- | --- | --- |
| 1 | `HearingService.php:106` | injects `BezwaarAuditTrail` |
| 2 | `HearingService.php:201` | `append` `hearing-scheduled`, awb-art-7:2, in `schedule()` |
| 3 | `HearingService.php:281` | `append` `hearing-waived`, awb-art-7:3, in `waive()` |
| 4 | `HearingService.php:389` | `recordAttendance()` delegates a late correction to `HearingMinutesRecorder` |
| 5 | `HearingService.php:476` | `addMinutes()` delegates the audio consent guard to `HearingMinutesRecorder` |
| 6 | `HearingService.php:492` | `append` `verslag-recorded`, awb-art-7:7, in `addMinutes()` |
| 7 | `HearingMinutesRecorder.php:60` | injects `BezwaarAuditTrail` |
| 8 | `HearingMinutesRecorder.php:105` | `append` `audio-upload-denied` |
| 9 | `HearingMinutesRecorder.php:109` | tags it `TAG_RECORDING_CONSENT`, avg-art-6 |
| 10 | `HearingMinutesRecorder.php:195` | `append` `attendance-late-correction` |
| 11 | `HearingMinutesRecorder.php:203` | tags it `TAG_VERSLAG`, awb-art-7:7 |
| 12 | `AdvisoryCommitteeService.php:110` | injects `BezwaarAuditTrail` |
| 13 | `AdvisoryCommitteeService.php:203` | `append` `panel-member-added`, in `assignToCommittee()` |
| 14 | `AdvisoryCommitteeService.php:298` | `resolveActor()` for the signing chair |
| 15 | `AdvisoryCommitteeService.php:404` | `append` `council-deviation-recorded`, in `recordCouncilDeviation()` |
| 16 | `AdvisoryCommitteeService.php:489` | `append` `independence-check-failed` |
| 17 | `AdvisoryCommitteeService.php:608` | `append` `advice-signed-by-chair`, in `buildTransitionUpdate()` |

`HearingService.php:84` to `91` re-export the eight tag constants; they stay. The five reads of
`$current['auditTrail']` (`HearingService.php:385`, `473`, `AdvisoryCommitteeService.php:405`, `490`,
`609`) only thread the array into the next `append` and go with it.

### Who reaches them

- `schedule()` through `seedDefaultHearing()` from `BezwaarHearingScheduledListener`.
- `recordAttendance()` from `BezwaarHearingController`.
- `assignToCommittee()` through `autoAssignDefaultCommittee()` from `BezwaarAdviceRequestedListener`.
- `recordCouncilDeviation()` from `DecisionConcludedListener`.
- `waive()`, `addMinutes()` and `transitionAdviceStatus()` have **no caller in `lib/`** today. Their
  tests here drive the public method. Giving them a route is not part of this change.

### Where the history is read

No screen reads the `auditTrail` property: `src/manifest.json` never names it, and no service in
`lib/` reads it except to append. `BezwaarAdviceRequestDetail` already has a History sidebar tab
(`type: audit`), which reads OpenRegister's trail for the object, so the advice request entries
appear there once they are written there. `hearingSession` has no detail page today, so its entries
have no screen before or after this change.

## Existing entries: copied across, and the arrays left as written

The entries already in `hearingSession.auditTrail` and `bacAdviceRequest.auditTrail` are copied
onto OpenRegister's trail once, by a repair step. The arrays are not changed and not deleted.

Why copy rather than only keep them readable: the History tab is the one place the bezwaar screens
show history, and it reads only OpenRegister's trail. An entry left only in the array would be
missing from the screen, and a hoorzitting record that looks incomplete is a statutory problem in
itself. Why leave the arrays: nothing statutory may be lost, and the array is the original.

One limit, stated rather than hidden: `createAuditTrailEntry()` stamps the row with the time it is
written and takes no time argument. A copied row therefore carries the copy's time as its own, and
the original time, actor, tag and payload in its context under the same keys a live entry uses, plus
`migratedFrom: auditTrail` and `migratedIndex`. Its actor is `system`, because the system wrote it;
the original actor is in the context.

## What changes

- `BezwaarAuditTrail` becomes a writer onto `AuditTrailMapper::createAuditTrailEntry()`. `append()`
  goes; `record()` writes one row; the tag constants and `resolveActor()` stay.
- The three services' call sites record after or before their write as REQ-BAT-003 says, and no
  code writes the `auditTrail` property again.
- A repair step copies the existing entries.
- Both schemas keep `auditTrail`, described as the frozen pre-change record.

## What does not change

- The tag vocabulary and the event names.
- `TenantAuditTrailService` and the case custody chain in `CaseTransferService`, which are other
  trails.
- OpenRegister.
