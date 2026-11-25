# or-object-search Specification

## ADDED Requirements

### Requirement: A slug search passes the target OpenRegister asks for (REQ-SST-001)

Dossiq's object-search bridge MUST call OpenRegister's slug search with the
register and schema in the shape the installed OpenRegister declares: one
target value when its first parameter is a class, the two slug strings
otherwise.

@e2e exclude Argument-shape adaptation to the installed OpenRegister; covered by unit tests and the live check in tasks 1.2.

#### Scenario: OpenRegister takes a target value

- GIVEN an OpenRegister whose `searchObjectsBySlug()` takes a `RegisterSchemaSlugs` value first
- WHEN dossiq searches register `dossiq`, schema `case`
- THEN it passes one `RegisterSchemaSlugs('dossiq', 'case')` followed by the filters

#### Scenario: OpenRegister takes two slugs

- GIVEN an OpenRegister whose `searchObjectsBySlug()` takes two strings first
- WHEN dossiq searches register `dossiq`, schema `case`
- THEN it passes `'dossiq'`, `'case'` followed by the filters
