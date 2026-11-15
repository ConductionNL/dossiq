# Proposal: reachable-via-one-paragraph

## Why

r32 strict verdict on DqContact "Bereikbaar via": the board writes one muted paragraph ("Laatst contact op … via Mijn Zuiddrecht. Telefoon en e-mail staan niet in de basisregistratie."). Live drew the last contact as body text and the note as a separate line.

## What changes

The last-contact entry is a `line`, and the registration note joins it (`joinPrevious`, separated by ". "). This needs nextcloud-vue feat/facts-line-joins-previous. Without a contact moment, the note stands on its own.

## Scope

Config only (`src/manifest.json`).
