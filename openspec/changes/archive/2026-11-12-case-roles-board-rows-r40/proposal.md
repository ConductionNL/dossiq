# Proposal: case-roles-board-rows-r40

## Why

Board DqZaakBetrokkenen "Rollen" after design-system #811: Sanne's row reads "Primaire partij · verzoeker · ..." with the pill "Geen geheimhouding"; J. van Wijk, also a person, has no secrecy pill; Bewonersvereniging Lindelaan ends with "Indicator: meerdere meldingen". Live read "Hoofdbetrokkene", drew no secrecy pill (the library did not read `secrecy` until nextcloud-vue fix/bdq1-parties-board-rows-r40), and the indicator as a bare grey pill.

## What changes

- `secrecy.primaryOnly: true`: only the primary person says "Geen geheimhouding" (amends case-roles-no-secrecy-line, whose spec named every person; the board draws it on the primary party alone).
- `labels.primary` "Primary party" (Dutch now "Primaire partij"), `labels.noSecrecy` "No secrecy" ("Geen geheimhouding"), `labels.indicatorPill` "Indicator: {label}".
- Needs nextcloud-vue fix/bdq1-parties-board-rows-r40; an older library ignores the new keys.
