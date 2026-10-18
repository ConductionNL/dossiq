# Proposal: the catalogue lists dossiq's own object types

## Why

The catalogue page (board DqCatalogus) has an Objecttypen card. It read every schema on the instance from OpenRegister, so it would show other apps' schemas and OpenRegister's nc-* virtual schemas as dossiq object types. Through the legacy `table` widget it also never showed anything at all.

## What changes

- A new admin-only endpoint `GET /api/catalogue/object-types` lists the schemas in dossiq's own register (app config `register`), each with its title, description, slug, version and number of properties, sorted by title.
- The Objecttypen card reads that endpoint through the `object-table` widget.

## Not in this change

The board draws a "used in N case types" column. A case type holds no reference to an object type (the `caseObject` schema links an object to a case, with the type as free text), so there is nothing to count from. The column waits until case types reference object types; this change does not invent that link.
