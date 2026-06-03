# Tasks: termijnbewaking-dwangsom-engine-03-pause-extension

Member 3 of 11 (code). Depends on member 02. Traces to giant Tasks 3, 4 (REQ-TERM-002, REQ-TERM-003).

## 1. PauseService (AWB 4:5/4:15)

- [ ] Implement `PauseService.registerPauze(termijnInstanceId, duurDagen, motivering, documentLink)`
- [ ] Extend `einddatumActueel` by `duurDagen`; set `status = gepauzeerd`
- [ ] Record `TermijnGebeurtenis` type `pauze` with `dagenImpact = +duurDagen`
- [ ] Store pause-deadline on the instance for the daily scan to watch
- [ ] Implement `resumeAfterPauze(termijnInstanceId, aanvullingDatum)`
- [ ] Compute consumed vs. unconsumed pause days; add only unconsumed days to `einddatumActueel`
- [ ] Set `status = lopend`; record `hervat` event; emit `termijn-pause-resumed`

## 2. ExtensionService (AWB 4:14)

- [ ] Implement `ExtensionService.requestExtension(termijnInstanceId, motivering, newEinddatum, documentLink)`
- [ ] Validate: motivering non-empty, newEinddatum > current einddatumActueel, aantalVerlengingen < maxVerlengingen
- [ ] On success: `aantalVerlengingen++`, `einddatumActueel = newEinddatum`, record `verleng` event with dagenImpact
- [ ] Emit verlengingsbrief notification trigger
- [ ] Block second extension with error citing AWB 4:14 lid 3
- [ ] Implement supervisor-approval override pathway with separate audit trail

## 3. Tests

- [ ] Unit test PauseService: pause extends deadline, resume consumes elapsed days proportionally
- [ ] Unit test ExtensionService: first extension succeeds, second blocked, override requires approval
