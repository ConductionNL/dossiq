# Proposal: organisation-contact-persons-tab

## Why

Board DqOrganisatie draws six tabs since design-system #378: Overzicht, Zaken, Contactmomenten, Contactpersonen, Bestanden
and Historie. The strip wraps, so Historie sits on a second line. The earlier change contact-persons-tab-in-actions moved
Contactpersonen into the strip's Actions menu, because the board drew five tabs then. In r29 the tab count reads 5 against
6.

## What changes

The Contact persons tab is a strip tab again: `overflow` is dropped. It keeps its place after Contact moments, its count,
and the Hoofdcontact link. This supersedes contact-persons-tab-in-actions.

## Scope

Config only (`src/manifest.json`).
