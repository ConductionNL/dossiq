# Design: migrate-archival-to-or

## Target architecture

```
case closed (procest domain event)
   └─ case schema carries x-openregister-archival retention config
        └─ OR RetentionService / Archival/RetentionEvaluator computes archiefactiedatum
             ├─ OR DestructionCheckJob → destruction lists for archivist review (V-lijst)
             └─ OR Edepot/EdepotTransferService (B-categorie / overbrenging)
                  ├─ TmloService + MdtoXmlGenerator (metadata, from procest's declared mapping)
                  ├─ SipPackageBuilder (OAIS SIP; BagIt output = OR-AD-1)
                  └─ Transport/TransportInterface (Sftp | RestApi | OpenConnector)
procest bezwaar/beroep lifecycle
   └─ places/releases legal hold via OR Archival/LegalHoldService
```

Procest keeps only: (a) the declarative retention/selectielijst + TMLO/MDTO mapping config on its
schemas, (b) the domain listeners that translate Awb events (bezwaar/beroep opened/closed, case
closed) into calls on OR abstractions, (c) `EmailArchivalService` (zaakdossier registry logic).

## Retention config shape

OR's dialect is `x-openregister-archival` (validated by
`OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator`; keys observed: `retention`,
`rules`, `condition`, `default`, `reason`). The exact per-zaaktype shape is finalised during apply
against the validator on the deployed OR version; the content is the current VNG seed set:

| zaaktypeKey | bewaartermijn | selectielijst |
|---|---|---|
| omgevingsvergunning | 5 years | (categorie from current `bewaarTermijnRegel` seed) |
| wmo-melding | 10 years | idem |
| subsidie-verlening | permanent (overbrenging) | idem |

Source of truth for migration: `lib/Service/ArchiefEdepotSeedDataService.php` seed values + any
municipality-edited `bewaarTermijnRegel` objects in the live register (migrated per-instance by
the repair step, not just the defaults).

## Legal hold instead of trigger-suspension

Old: `ArchivalTriggerService` set `overdrachtTrigger.status = opgeschort-juridische-procedure`.
New: on bezwaar/beroep registration against a case, procest calls OR `LegalHoldService` to hold the
case object; on final Awb outcome it releases the hold. OR's retention evaluator and destruction
jobs already respect holds — procest stops re-implementing "don't archive while litigation runs".

## Repair-step migration (one-shot, idempotent)

`lib/Repair/MigrateArchivalToOpenRegister.php` (new):

1. Guard: skip unless OR exposes the archival abstractions (class_exists / version check) —
   fail-closed, migration never half-runs.
2. Translate `bewaarTermijnRegel` objects → schema archival config / OR selectielijst entries.
3. Re-nominate open `overdrachtTrigger` cases through OR (status mapping:
   `gereed-voor-overdracht` → OR transfer nomination; `geblokkeerd-geen-regel` → unconfigured →
   surfaces in OR archivist view; `opgeschort-juridische-procedure` → legal hold).
4. Export completed `archiefBewijs` + `overdrachtAuditLog` entries as immutable zaakdossier
   documents (proof preservation), then mark the six schemas deprecated; actual schema removal is a
   follow-up release after V-tasks pass.

## Removal order (to keep every intermediate state shippable)

1. Land config + repair step + legal-hold listener (old chain still present, now dormant).
2. Flip the archival feature to OR path; old services stop being scheduled.
3. Remove services + Application.php bindings + adapter seams.
4. Remove/shrink `62-archief-edepot.json` (schema retirement release).

## Interaction with adjacent changes

- `external-integrations-test-environments` owns wiring OR's `TransportInterface` to a real e-Depot
  test facility; this change must leave exactly one pluggable seam (OR's) for it to target.
- `align-claims-and-licence` downgrades the "Archivering naar e-Depot" overlay entry to `beta`
  (submission adapter is mock/log today); when this migration + a real transport land, promotion
  back to `stable` follows the promotion criteria defined there.
- The bezwaar/beroep detection used for holds is the same signal `bezwaar-beroep-cards-collapse`
  renders; no duplication — this change only adds the hold side-effect.
