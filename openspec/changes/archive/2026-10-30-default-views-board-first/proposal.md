# Proposal: default-views-board-first

## Why

DqFilterBeheren draws three default views on the case list, each with a line: Mijn zaken (Behandelaar is ik), Alle
lopende zaken (Status niet afgehandeld) and Termijn verstreken (Termijn voor vandaag, niet afgehandeld). dossiq shipped
nine others and none of these three. Ruben decided on 23 Oct: the board's three first, the other defaults under "Meer
standaardweergaven".

## What changes

The Cases page declares the three as its first default views with a description each, built from the list's own
lenses (Mine, Overdue, and the open-cases conditions). Every existing default stays, marked `secondary`. Config and
l10n only; the folding is nc-vue feat/r25-default-views-secondary, and older nc-vue lists all twelve.

Every default view also carries an icon the app registers and a description (Ruben, 23 Oct: the icon stands before
the view in the menu and the description is its tooltip, nc-vue feat/r25-saved-view-icon-description-count).
