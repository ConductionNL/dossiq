# Proposal: requester-identified-at-intake

## Why

The case's Aanvrager card read "Person" under the requester's name and listed the intake channel as a row. The board
(DqZaak and the other case boards) draws "Ingelogd met DigiD" under the name, a "Contactgegevens bekijken" link at the
foot and no channel row, and the header names the portal the request came through ("via Mijn Zuiddrecht"). The case did
not record either fact, so neither could be shown.

## What changes

- The case gets `intakeIdentification` (`digid` or `eherkenning`): how the requester logged in when the case came in
  through a portal. Left empty for a case taken in any other way.
- The case gets `intakeSourceName`: the name of the portal it came through, as portaliq holds it (`portalName`).
- A portal Woo request writes both, from the verified assertion's audience and portaliq's config.
- The Aanvrager card's subtitle reads the identification first, then the requester's kind; its foot link opens the
  contact or the organisation page by the requester's kind; the intake channel row is dropped.
- The case header's meta line reads "via {intakeSourceName}", and is left out when the case has none.
