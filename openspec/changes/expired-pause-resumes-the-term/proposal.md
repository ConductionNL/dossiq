# An expired pause resumes the term by itself

## Why

Ruben decided on 12 Nov (decision 391) that when a pause runs out, the term resumes by itself
and the handler is told. Today only the handler resumes a paused term (REQ-TERM-002), so a
case whose applicant never answers stays paused and its deadline never runs again.

### Matrix rows (dossiq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `8.10` | the term resumes on its own when the pause ends, and the handler gets a notification |

## What changes

- When the pause's days have passed and no answer arrived, the term goes back to running with
  the full pause added, and a `hervat` event records that it resumed by itself.
- The case's handler gets a Nextcloud notification that names the case and the new end date.
- A handler can still resume earlier, as REQ-TERM-002 describes.

## Impact

- `lib/Listener/TermijnTimerFiredListener.php`, `lib/Service/TermijnTimerService.php`, the
  notification rules. Not built yet: the matrix row moves to `specified`.
