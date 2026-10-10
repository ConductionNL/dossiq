---
kind: code
---

# Proposal: dso-single-intake-path

## Summary

A DSO verzoek becomes a dossiq case on one path only: integriq receives and maps it, and `VergunningaanvraagCreatedListener` makes the case through `DsoCaseService`. The second receiver (`POST /api/vth/dso/intake`) is retired, and a KCC complaint lands on the complaint case type dossiq ships.

## Why

Measured after dossiq#3412, which made the listener path write a valid case:

- **Three paths could open a case for one verzoek.** The listener (deduplicated on `permitApplicationRef`), integriq's manual `verzoek-to-case` handoff (it answered 502 "caseType missing"), and dossiq's own public endpoint `POST /api/vth/dso/intake`.
- **`DsoIntakeService` cannot resolve a case type.** Its STAM payload carries activity names, not the IMOW reference integriq maps, so it wrote a case without `caseType`, which OpenRegister refuses. It also let every request through when no secret was set, and it had no deduplication against the listener. Its only caller is `appinfo/routes.php` (`dSOIntake#intake`). Nothing in dossiq's frontend or in integriq calls it.
- **`klacht_ex_artikel_9_1_awb` names no shipped case type.** `QuickActionService` filed every complaint under it, so "Klacht registreren" answered "No case type answers to ..." on a fresh install. The register already ships the Awb chapter 9 type as `klacht-behandeling` (P42D, initial status `klacht-ontvangen`).

## What changes

- Retire `DSOIntakeController`, `DsoIntakeService`, the `/api/vth/dso/intake` route and their tests.
- Map the complaint quick action, and its seeded `targetCaseType`, to `klacht-behandeling`.
- integriq retires its `verzoek-to-case` handoff in the paired change `retire-dso-case-handoff`.

## Impact

- An external caller of `/api/vth/dso/intake` gets 404. None is known. DSO traffic goes to integriq's STAM koppelvlak.
- The listener stays opt-in through `dso_vergunningaanvraag_schema`.
