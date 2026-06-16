# Design: termijnbewaking-dwangsom-engine-03-pause-extension

## Scope of this member

`PauseService` (AWB 4:5/4:15) and `ExtensionService` (AWB 4:14). Consumes the member-02 `TermijnService` and the member-01 schemas. Pause-expiry *detection* is implemented in the daily scan (member 04); this member exposes the registration/resume/extend operations and records events.

## Approach

- **Data access (ADR-001)**: mutations go through `TermijnService` / OpenRegister `ObjectService`. Each operation appends an immutable `TermijnGebeurtenis`.

### PauseService
- `registerPauze(termijnInstanceId, duurDagen, motivering, documentLink)` — set `status = gepauzeerd`, extend `einddatumActueel` by `duurDagen`, record `pauze` event with `dagenImpact = +duurDagen`, store the pause-deadline for the scan to watch.
- `resumeAfterPauze(termijnInstanceId, aanvullingDatum)` — compute consumed vs. unconsumed pause days; only the *unconsumed* days extend the final `einddatumActueel`; set `status = lopend`; record `hervat` event; emit `termijn-pause-resumed`.

### ExtensionService
- `requestExtension(termijnInstanceId, motivering, newEinddatum, documentLink)` — validate `motivering` non-empty, `newEinddatum > einddatumActueel`, `aantalVerlengingen < maxVerlengingen` (1). On success: `aantalVerlengingen++`, `einddatumActueel = newEinddatum`, record `verleng` event with `dagenImpact`, emit a verlengingsbrief notification trigger (rendered in member 08).
- Second-extension block cites AWB 4:14 lid 3. Override pathway = separate supervisor-approval flow with its own audit trail.

## Security (ADR-005)

Both services operate on a specific `termijnInstanceId`; the controllers that expose them (member 10) enforce per-case authorization (ADR-023 action-level auth). The override flow requires a distinct supervisor role check. No fail-open.

## Tests

Unit: pause extends deadline; resume consumes elapsed days proportionally; first extension succeeds; second extension blocked; override requires approval.
