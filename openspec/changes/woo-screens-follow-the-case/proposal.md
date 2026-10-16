---
kind: code
depends_on: []
---

# Proposal: woo-screens-follow-the-case

## Why

Rehearsal R3 of the Zuiddrecht demo (16 Oct, dossiq 0.4.54-unstable.20261016050320) walked a Woo request from the portal to publication. Next to the blocker (the-woo-publication-carries-its-documents) it found screens that did not follow the case:

- The "Aangeleverde sets" table showed `2026-10-16T08:44:21+00:00` and `frozen`.
- The assessment dialog kept listing a saved document under "Nog geen oordeel" until the page was reloaded.
- "Deels openbaar" and a ground's name could split inside a word in the assessment dialog.
- The Woo-besluit dialog said "(optioneel)" where every form says "(niet verplicht)".
- At Publicatie the what-now card read "Wat nu? Behandelen ... Daarna: het besluit": Publicatie carried the role of the handling steps.
- The example set install logged twelve times "TermInstanceStore persist failed": it runs from occ with no user, and OpenRegister refuses a create by "Anonymous", so its cases got no term.

## What Changes

- The delivered-sets list formats its dates in the reader's locale (`format: date-time`) and names its states in words ("Wordt aangeleverd", "Aangeleverd en vastgelegd").
- The assessment dialog drops a document the server answered as saved at once, also when the next read of the outstanding list does not see the save yet. The save button says it is saving.
- A label in the assessment dialog breaks between words, and a long word at a hyphenation point of the page language.
- The Dutch catalogue marks an optional field "(niet verplicht)", the library's word, everywhere.
- The Woo status Publicatie carries the role `review`, the decision's role, so the what-now card no longer sends the case back to handling. The role vocabulary has no stage of its own for publishing; the card reads the decision stage and "Daarna: de zaak afsluiten". The existing statusRole backfill moves cases already at Publicatie.
- A term written with nobody signed in is written as the system (`asSystemWithoutUser`); a signed-in user's write is never elevated.

## Impact

- `src/manifest.json`, `src/dialogs/DocumentAssessDialog.vue`, `l10n/*`, `lib/Settings/register.d/81-woo-verzoek.json`, `lib/Service/Termijn/TermInstanceStore.php`.
- The Team queue badge that did not drop after a claim is fixed in the library: ConductionNL/nextcloud-vue#1674 (filtered menu counts follow `cn:objects-changed`). dossiq picks it up with its next nextcloud-vue bump; no dossiq change is needed, it already announces the claim.
