# Proposal: object-types-used-in-case-types

## Why

Board DqCatalogus (the Objecttypen page under Geavanceerd) says where an object type is used in case types: "Boom ·
Gebruikt in 3 zaaktypen". Live counts cases ("27 zaken"). A case type does not declare its object types, so the
number was left out; the coordinator decided on 24 Oct to derive it from the cases that link the type.

## What changes

- `ObjectTypeCatalogue` reads the case type of every case that links an object (batched `_ids` reads of the case
  schema) and gives each row `caseTypes`: the number of distinct case types. The row keeps `cases`. Without a
  configured case schema `caseTypes` is null (unknown, not zero).
- The Objecttypen table's "Used in" column shows `caseTypes` through a new `caseTypeCount` formatter
  ("%n zaaktype(n)"; nothing for null).

## Reach

One service, one formatter, the catalogue fragment, one l10n plural.
