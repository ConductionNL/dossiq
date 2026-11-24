# Proposal: inspection-conflict-card

## Why

DqInspectie draws, in the sync frame under the outbox, the conflict the replay met on "Molenweg 4, bevinding": "Conflict: welke tekst houdt u?", the text on the phone, the text at the office, and two buttons, "Deze houden" and "Die van kantoor". The page shows the outbox row with its red Conflict pill, but nothing shows the two texts or lets the inspector choose. The spec still describes a three-button merge dialog that the board does not draw, and the resolve service it named was removed with the old sync backend (062d9dede).

## What changes

- The sync frame places nextcloud-vue's `conflict-resolve` widget (CnConflictResolveCard, nextcloud-vue change `conflict-resolve-card`) over the open conflict records, with the board's texts in Dutch and English.
- The conflictRecord schema (1.1.0) declares what the card and the device need: `targetRegister`, `targetSchema`, `targetId`, `conflictFields` and `detectedAt`. nextcloud-vue's `recordConflict` already writes the target and `detectedAt`; without the declarations they were not part of the schema.
- The Zuiddrecht example set carries the board's conflict, linked to the outbox row `outbox-molenweg-bevinding`.
- A resolve endpoint (`POST /apps/dossiq/api/conflict-records/{id}/resolve`) writes the chosen value and marks the record resolved. It lives in dossiq `lib/` outside Settings, so SIG builds it from the contract in `design.md`. Until dossiq announces the capability `conflictResolution`, the card shows both texts with its buttons disabled and says why.
- The requirement "Conflict Detection and Resolution for Concurrent Edits" follows the board: two choices, no manual merge.

## Out of scope

- A manual merge (an edited third text).
- Filing conflicts from the device into the register: dossiq's `offlineConfig` names no `conflictSchema`, so conflicts stay on the device today. Turning that on is a separate decision (see design, open decisions).
