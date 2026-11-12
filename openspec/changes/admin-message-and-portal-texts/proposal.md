# Message and portal texts in the admin settings

## Why

Ruben decided on 12 Nov (decision 391) to split matrix row 11.24. The texts of dossiq's messages
and of the portal are set in dossiq's admin settings. Which websites may call the portal (CORS)
is OpenRegister's and the portal's, not dossiq's. The code already has the texts
(`textsAndAccessFields` in `src/views/settings/adminFields.js`), but `admin-settings` has no
requirement that says so.

### Matrix rows (dossiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `11.24` | reworded to the message and portal texts; CORS moves to an OpenRegister provider row |

## What changes

- A requirement for the texts block in the admin settings: the sender of messages and the text
  above My cases in the portal, plus a pointer that says where the allowed websites are set.
- No code change: this specifies what ships.

## Impact

- Spec only. Board DqInstellingenAlgemeen already draws the texts.
