# Proposal: party-dates-longdate

## Why

Boards DqContact and DqOrganisatie spell the month out in the side cards. Live wrote them short:

- Historie: board "Laatst gewijzigd op 3 oktober", live "21 okt 2026";
- Identiteit: board "bij het contactmoment van 4 oktober", live "4 okt".

## What changes

The side-history date, the Identity lead's date and Bereikbaar via's date use the facts format `longdate`
(nextcloud-vue feat/facts-longdate-format, 24t1m): the month written out, the year only outside the current year.

## Scope

Config only. Stacked on r25/contact-side-cards-from-moments (the Identity and Bereikbaar via cards).
