# Proposal: case-type-fields-columns

## Why

Board DqZaaktypeVelden's Velden table reads Veld, Soort, Invullen, Herkomst, In de lijst: the field, the kind of
answer in words ("Keuzelijst, 6 opties", "Ja of nee, berekend", "Datum") and how it is filled in (Verplicht, Alleen
lezen). The live table read Field, Category, Type, Required with the raw type code and a tick.

## What changes

- `src/utils/fieldDescription.js`: `fieldKind` (type in words, the options of a choice list counted, "from BAG" for a
  register source, ", calculated" for a calculation) and `fieldFill` (Read only for a calculated field, else Required
  or Optional), wired as the cell formatters `fieldKind` and `fieldFill`.
- `case-type-fields-editor` columns: Field, Kind (fieldKind), Fill in (fieldFill). Category stays in the form and the
  sort.
- l10n en + nl.

## Not in this change

Herkomst (own vs inherited) needs the inherited fields in this list; the list shows the type's own fields, so every
row would read "Eigen veld". In de lijst needs the case list layout per field. Both follow once the example set
shows them live.

## Reach

Config, a formatter, l10n.
