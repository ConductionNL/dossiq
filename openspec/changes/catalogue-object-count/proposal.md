# The catalogue counts the objects behind an object type

## Why

Board DqCatalogus draws, under each object type, the schema and how many objects it holds
("Bomenregister · 4.212 objecten"). The catalogue showed the schema title only.

## What changes

- ObjectTypeSchemaResolver also answers `objects`: the number of objects the register holds in the schema,
  counted through OpenRegister's ObjectService; null without that service, and a refused count throws like the
  catalogue's other reads (the endpoint answers 503).
- `GET /api/catalogue/object-types` adds per row `objects` and `schemaLine`, the schema title with the
  count in the reader's language ("%n object" / "%n objects"); without a count, the title alone.
- The Objecttypen table's line under the type reads `{schemaLine}`.
