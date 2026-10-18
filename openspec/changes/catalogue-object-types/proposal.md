# Proposal: the catalogue lists the object types cases link

## Why

The catalogue page (board DqCatalogus) has an Objecttypen card. It read every schema on the instance from OpenRegister, so it would show other apps' schemas and OpenRegister's nc-* virtual schemas as dossiq object types. Through the legacy `table` widget it also never showed anything at all. Listing the schemas of dossiq's own register instead would show case, task and the other record schemas, which are not object types either.

## What changes

- A new admin-only endpoint `GET /api/catalogue/object-types` reads the objects cases link (`caseObject`) on the server and answers each distinct `objectType` with the number of cases that link one, most used first. A read OpenRegister refuses answers 503, not an empty list.
- The Objecttypen card reads that endpoint through the `object-table` widget, with an Objecttype and a Gebruikt in column ("2 zaken").
- With no links, the card says object types appear once cases link objects.

## Not in this change

The board draws a "used in N case types" column. A case type holds no reference to an object type (`caseObject` links an object to a case, with the type as free text), so there is nothing to count from. That column waits until case types reference object types; this change does not invent the link.
