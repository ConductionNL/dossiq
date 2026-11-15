# Proposal: system-settings-read-as-the-board

## Why

The Systeem tab of the admin settings (board DqBeheerinstellingen) describes its cards in developer words ("Shared
functional mailbox ingest (IMAP) and transport", "Adviesaanvragen: advisory body registry, mandatory-gate config, n8n
webhook contracts") and shows every field at once, where the board writes one plain line per card and draws the few
fields an administrator sets.

## What changes

- Each card's line under its title and its field labels follow the board (Menustructuur, Serviceaccount, Zaak-e-mail,
  AI-ondersteunde verwerking, Adviseringsbeheer, Zoekindex). The menu choice is labelled "Menu".
- The service account's group sentence moves from a paragraph to the hint under the user name.
- What the board leaves out stays, behind the card's Geavanceerd disclosure: the mailbox explanation, the AI feature and
  privacy switches, the consultation notice and the bottleneck threshold. The DPIA warning stays in view.
- The external response address checks for https:// instead of saying so in a hint; the mailbox and advice cards save
  with a plain "Opslaan".

## Scope

AdminRoot.vue, adminFields.js, l10n. No setting is added or removed.
