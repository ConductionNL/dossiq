# case-type-page-no-follow

## Why

The case type page showed the library's automatic Follow toggle and its follower count beside the title. Boards
dossiq/DqZaaktypeOverzicht, DqZaaktype and DqZaaktypeVelden draw no follow control on a case type: it is configuration,
not work someone keeps track of, the way contact and organisation pages already turn it off.

## What changes

- The case type page sets `follow: false`, so neither profile draws the toggle and the read no longer asks for
  `@self.can`.
