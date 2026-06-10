# Tasks: termijnbewaking-dwangsom-engine-03-pause-extension

Member 3 of 11 (code). Depends on member 02. Traces to giant Tasks 3, 4 (REQ-TERM-002, REQ-TERM-003).

## 1. PauseService (AWB 4:5/4:15)

- [~] Implement `PauseService.registerPauze(termijnInstanceId, duurDagen, motivering, documentLink)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Extend `einddatumActueel` by `duurDagen`; set `status = gepauzeerd` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Record `TermijnGebeurtenis` type `pauze` with `dagenImpact = +duurDagen` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Store pause-deadline on the instance for the daily scan to watch — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement `resumeAfterPauze(termijnInstanceId, aanvullingDatum)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Compute consumed vs. unconsumed pause days; add only unconsumed days to `einddatumActueel` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Set `status = lopend`; record `hervat` event; emit `termijn-pause-resumed` — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. ExtensionService (AWB 4:14)

- [~] Implement `ExtensionService.requestExtension(termijnInstanceId, motivering, newEinddatum, documentLink)` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate: motivering non-empty, newEinddatum > current einddatumActueel, aantalVerlengingen < maxVerlengingen — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] On success: `aantalVerlengingen++`, `einddatumActueel = newEinddatum`, record `verleng` event with dagenImpact — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Emit verlengingsbrief notification trigger — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Block second extension with error citing AWB 4:14 lid 3 — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement supervisor-approval override pathway with separate audit trail — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Tests

- [~] Unit test PauseService: pause extends deadline, resume consumes elapsed days proportionally — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Unit test ExtensionService: first extension succeeds, second blocked, override requires approval — deferred to downstream cycle / fleet-wide adoption (handoff)
