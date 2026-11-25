# Proposal: case-menu-tabs-carry-icons

## Why

Board DqZaakMeer draws the case tabs that sit in the "Acties" menu with an icon
in front of each (Notities, Betrokkenen, E-mail, Besluiten, Gerelateerd,
Bewaring, Archivering, Kennis). The case tabs declared no icons, so the menu
showed a column of blank icon space.

## What changes

Every overflow tab of the case page declares an icon that dossiq registers.
Drawn by nextcloud-vue `fix/r24-board-menu-tab-icons` (24t1g); an older
library ignores the key in the board look.
