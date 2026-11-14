# Proposal: party-contact-moments-board-lines

## Why

Boards DqContact and DqOrganisatie draw each row of Laatste contactmomenten with a meta line
"4 okt, 10:52 · e-mail · inkomend · zaak 2026-0070" and put the count, "3 in totaal", at the right of the card
title. The pages show only the moment ("4 okt, 10:52") and the count as a subtitle under the title.

## What changes

The `contact-latest-moments` card on ContactDetail and OrganisationDetail:

- meta from parts: the moment, the channel, the direction and "zaak {case.identifier}" (the case extended);
  an empty part drops with its separator, so a moment without a case reads without "zaak";
- channel and direction through `valueLabels` in the board's lower-case words;
- the count as `headerNote` (right of the title) instead of `headerSubtitle`.

## Scope

Config only (`src/manifest.json`, l10n). No nc-vue change: `meta.parts`, `valueLabels`, `extend` and
`headerNote` are in the released library.
