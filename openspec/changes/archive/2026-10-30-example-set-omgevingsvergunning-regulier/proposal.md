# Proposal: example-set-omgevingsvergunning-regulier

## Why

DqZaaktype, DqZaaktypeOverzicht and DqZaaktypeVelden draw the case type "Omgevingsvergunning regulier":
- a child of Omgevingsvergunning;
- a draft fifth version;
- fields marked "Geërfd van Omgevingsvergunning" beside one field of its own.

:8080 had only the parent, so the boards were measured against it.

## What changes

- An example `caseType` "Omgevingsvergunning regulier" (`omgevingsvergunning-regulier`). Its `parentCaseType` is the shipped Omgevingsvergunning, found by identifier; it is version 5 and `isDraft`.
- One `propertyDefinition` of its own: Situatietekening, a required file, hidden on create.
- Example data, not shipped configuration. The child inherits the parent's statuses and results, so no procedure is added (decision 182).
