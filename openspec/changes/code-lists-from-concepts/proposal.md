---
kind: config
depends_on: []
---

# Proposal: code-lists-from-concepts

Competitor gap register, row 11.10 "Code lists or choice fields"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner openregister, slug
`code-lists-from-concepts (dossiq)`, size S. This is dossiq's half; the
code list is OpenRegister's SKOS concept register.

## Why

Every choice list is typed into the attribute that uses it.
`propertyDefinition.enumValues` holds the options; two case types that
both ask for a "reden" each carry their own copy, and a municipality-wide
list (wijken, taakvelden, afhandelkanalen) is retyped per type and drifts.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/admin/catalog/entities/versioned_casetype.py`
(`_round2/compare/M1-functionality.md`).

## What changes

- `propertyDefinition.conceptScheme`, a reference to a SKOS concept scheme
  in OpenRegister. When set, the property's options are the scheme's
  concepts; `enumValues` stays for a short inline list.
- The case data form renders a picker over the scheme.
- The property definitions index shows which scheme a property uses.

## Ownership

dossiq builds the property and the picker binding. It consumes openregister
`skos-concept-registers` (spec exists on openregister `development`).

## ADRs

- Company ADR-022 and ADR-045: OpenRegister owns master data and code
  lists.
- Company ADR-031: the binding is declared.

## Capabilities

- Modified: `property-definition-management`: a property can take its
  options from a concept scheme.

## Impact

`lib/Settings/dossiq_register.json` (`propertyDefinition.conceptScheme`);
the case data form's property renderer; the property definitions index
column; one e2e spec.
