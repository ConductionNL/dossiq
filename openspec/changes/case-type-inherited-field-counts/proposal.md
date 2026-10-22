# case-type-inherited-field-counts

## Why

Board dossiq/DqZaaktypeVelden draws a Moedertype card beside the Fields tab: Erft van Omgevingsvergunning, Geërfde velden
6 van 14, Overschreven Geen. The case type's blueprint knows which fields came from a parent but did not count them, and
the card showed only the parent.

## What changes

- The blueprint (`GET /api/case-types/{id}/blueprint`) answers `counts.propertyDefinitions`: `total`, `inherited` (rows a
  parent gave) and `overridden` (the type's own rows that replace a parent's row of the same name). A shared attribute is
  neither.
- The parent type card reads the blueprint and draws Erft van, Geërfde velden "{inherited} van {total}" and Overschreven
  for a type with a parent; a type without one keeps its sentence.
