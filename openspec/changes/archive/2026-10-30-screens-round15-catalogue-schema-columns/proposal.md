# The catalogue shows the schema behind an object type

## Why

Board DqCatalogus lists each object type with its schema (how many
properties), its views (map, table) and where it is used. A case links an
object by `objectUrl`; when that URL is OpenRegister's objects endpoint it
names the register and the schema, so the schema is real data, not a guess.
A free-text object type names no schema.

## What changes

- `GET /api/catalogue/object-types` adds per type `schema` (title),
  `properties` (count) and `views` (`map` when a property is of OpenRegister's
  `geo` type, `table` always), read from the first link whose URL names an
  OpenRegister schema (ObjectTypeSchemaResolver). A free-text type answers
  `schema: ''`, `properties: null`, `views: []`.
- The catalogue's object type table gains the Schema and Display columns and
  the schema title under the type name.
