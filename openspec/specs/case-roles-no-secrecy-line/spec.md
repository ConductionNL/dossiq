# case-roles-no-secrecy-line Specification

## Purpose
What dossiq does for case roles no secrecy line. The requirements were first written by the change `case-roles-no-secrecy-line` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-case-roles-no-secrecy-line/`) and cover: A person on a case says whether it has secrecy.

## Requirements

### Requirement: A person on a case says whether it has secrecy

The Roles section of a case SHALL show "Geen geheimhouding" for a person party whose record carries no `geheimhouding` indicator. A person who does carry it SHALL show that indicator with its verdict instead.

#### Scenario: Sanne on her Woo case
- **GIVEN** Sanne de Vries is the primary party of the Lindelaan Woo case and asked for no secrecy
- **WHEN** a handler opens the case's people
- **THEN** her row SHALL read "Geen geheimhouding"
