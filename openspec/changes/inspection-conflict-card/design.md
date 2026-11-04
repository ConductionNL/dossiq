# Design: inspection-conflict-card

## What the board draws

The third phone of DqInspectie, "Synchroniseren", under the outbox cards:

- A white card, 1px line, 12px radius, 12px 14px padding, 8px between its parts.
- Title, bold 15px: "Conflict: welke tekst houdt u?"
- Two stacked lines, 14px: `Op dit toestel: "Bijgebouw 4 bij 6 meter, in de groenstrook."` and `Op kantoor: "Bijgebouw circa 24 m², zonder vergunning."`
- Two outline buttons, 34px high, 8px apart: "Deze houden" and "Die van kantoor".
- No author, no time and no field name. The outbox card above it says what the conflict is about ("Molenweg 4, bevinding", "Tekst gewijzigd, ook op kantoor gewijzigd").

## Data

A conflictRecord holds `clientVersion` (the device's snapshot), `serverVersion` (the office's), `conflictFields` (dot paths of what both changed), the target (`targetRegister`, `targetSchema`, `targetId`), `syncQueueRef` and, once settled, `resolution`, `resolvedBy`, `resolvedAt`.

The card reads `conflictRecord` with `resolution[isnull]=true`, three at most, and draws one card per record with a differing field. With one differing field it draws the board exactly; with several it puts the field path (or a configured label) above each pair.

## The resolve contract (for SIG)

`POST /apps/dossiq/api/conflict-records/{id}/resolve`, `#[NoAdminRequired]`, CSRF token required (the default; the card sends it through `@nextcloud/axios`).

Body: `{ "resolution": "client_wins" | "server_wins" }`. Anything else: 400.

Order, every check before the first write:

1. Read the conflict record through ObjectService as the session user (RBAC on). Not readable or not found: 404.
2. `resolution` already set: 409 `{ "message": "Dit conflict is al opgelost." }`.
3. `client_wins` only:
   1. No `targetId`: 422 `{ "message": "Dit conflict noemt geen object om de tekst op te schrijven." }`.
   2. Read the target as the session user. Not readable: 404. Not updatable by the user (OpenRegister's RBAC for update on the target schema): 403, nothing written.
   3. Compare the target's current values at `conflictFields` with `serverVersion`. Different: store the current values as `serverVersion` on the record, leave it open, 409 `{ "message": "Op kantoor is de tekst intussen opnieuw gewijzigd. Kies opnieuw." }`.
   4. Write the device's values at `conflictFields` onto the target as the session user (a partial save, so OpenRegister's audit trail names the inspector).
4. Mark the record: `resolution`, `resolvedBy` = the session uid (never from the body), `resolvedAt` = now.
5. Mark the syncQueue row named by `syncQueueRef` `synced` and clear `lastError`, when it exists.
6. 200 `{ "id", "resolution", "resolvedBy", "resolvedAt" }`.

Capability: dossiq announces `{ "dossiq": { "conflictResolution": true } }` through an `ICapability` registered in `Application::register()`. The card reads it with `getCapabilities()` and keeps its buttons disabled until it is there, so the card can ship first.

The full contract, with the error table, is in the screens lane note `R20/DQ-CONFLICT/FOR-SIG.md`.

## Open decisions

1. Three-way conflicts (the office changed the text again after the record was filed): recommended as in step 3.3, refuse and refresh `serverVersion`, so the card shows the newest office text and the inspector chooses again. The alternative, last write wins, loses the colleague's second edit silently.
2. Several fields in one conflict: recommended one choice for the whole record (the board has one pair of buttons), not a choice per field. Per-field choices are a manual merge, which the board does not draw.
3. Who sees a conflict: today the card shows every open record the reader may read. Recommended: SIG gives conflictRecord RBAC so an inspector reads the records filed from their own devices and the case's handlers read those on their cases; the card then needs no filter.
4. Filing conflicts into the register: dossiq's `offlineConfig` names no `conflictSchema`, so the replay keeps conflicts on the device and the card only ever shows seeded records. Recommended: set `register: 'dossiq'` and `conflictSchema: 'conflictRecord'` in `src/main.js` once the endpoint lands, and have nextcloud-vue's `recordConflict` also write `syncQueueRef` (it writes `queueOperationId`, and `syncQueueRef` is required here) and `conflictFields` from `diffVersions`.
5. Who and when: the board draws neither. Recommended: leave them out now; `serverVersion['@self'].updated` and its author could become a muted line later, with a board amend first.
