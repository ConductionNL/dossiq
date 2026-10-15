---
kind: code
depends_on: []
---

# Proposal: the-support-note-can-be-switched-off

## Why

The Zuiddrecht demo shows dossiq to municipalities. In the go-live rehearsal (15 October) the "Support Dossiq" note, with its donation link, opened for the demo's staff. A demo, or an organisation that does not want it in front of its staff, has no way to turn it off: dossiq offered no setting, and the library's own switch is a manifest flag a deployment cannot reach.

## What changes

An app config switch, `show_support_note`. Unset, the note behaves as it always did. Set to `false` (or `0`, `no`, `off`), dossiq tells the frontend through the `support_note_shown` initial state, and the frontend passes `supportDialog: false` to CnAppRoot, which then never opens the note. Documented in `docs/admin/support-note.md`.

## Not changed

- The default: the note still opens once per user after the tour.
- The tour.

## Rollback

Revert the commit. A stored `show_support_note` is then ignored.
