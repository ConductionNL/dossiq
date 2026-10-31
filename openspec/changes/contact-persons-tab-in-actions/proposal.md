# Proposal: contact-persons-tab-in-actions

## Why

Board DqOrganisatie draws five tabs: Overzicht, Zaken, Contactmomenten, Bestanden and Historie. Its contact persons
are reached through the Hoofdcontact card ("Alle 2 contactpersonen"). Live adds a sixth tab, Contactpersonen, and
the strip wraps onto a second line (measured r25b, 24 Oct: tabCount 6 vs 5).

## What changes

The Contact persons tab is `overflow: true`. It moves into the strip's Actions menu, keeping its count, and the
Hoofdcontact link still opens it (by its id, which an overflow tab keeps). Nothing is removed.

## Scope

Config only (`src/manifest.json`).
