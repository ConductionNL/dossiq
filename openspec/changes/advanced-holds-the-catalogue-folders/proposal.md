# Proposal: the catalogue's folders sit under Advanced, the Store is the Marketplace

## Why

Ruben (23 October 2026) decided to remove the Catalogue as a navigation level. It was one page that only listed and linked the things a case type is built from (case types, attributes, templates, object types) plus a Store card, while Case types and Attributes also had their own entries beside it. Two ways to one page read as two different things. Each folder now gets its own entry under Advanced, case types first.

The same day he asked for the footer entry "Store", which rendered as "Winkel" in Dutch (the library's own catalogue translates `Store`), to read Marketplace / Marktplaats. The Dq boards do not draw that entry under any other word (no Winkel, Marketplace or Marktplaats on any Dq board), so the wording follows Ruben.

## What changes

- The Catalogue menu entry and page are gone. Under Advanced, in this order: Case types, Attributes, Templates, Object types. Case types, Attributes and Templates are open to everyone who could open those pages before; Object types stays admin-only, as the catalogue was.
- Object types gets its own page, `/settings/object-types`, carrying what only the catalogue offered: the object types table (with its pager and CSV download), New object type and Add object type. The page declares `permission: admin`, matching its admin-only endpoint.
- The catalogue's case type folders live on as the Case types page's own folder sidebar (it filters on `category`, the same facet). The Store card's items are the Marketplace page, and case type import is on the Case types page.
- `/settings/catalogue` redirects: with `?category=` to the Case types page on that folder, otherwise to Object types, the catalogue's main card.
- The getting-started tour's case types step stays "Open Advanced, then Case types" (nl "Open Geavanceerd, dan Zaaktypen").
- The Store menu entry and page title read `Marketplace`, Dutch `Marktplaats`. Route `/store` and the ids stay.

## Supersedes

The first version of this change ("the catalogue holds the case types"), which hid the Case types entry from administrators in favour of the Catalogue.

## Boards

DqCatalogus no longer matches. design-system follows in its own PR: the board becomes the Object types page under Advanced, without the folder tree and the Store card.
