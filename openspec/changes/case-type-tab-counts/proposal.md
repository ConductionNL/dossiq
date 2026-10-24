# Proposal: case-type-tab-counts

## Why

Every DqZaaktype* board draws the case type's tab strip with a number after Fasen en taken (6), Regels (3) and
Vertalingen (2). Live shows a number after Velden, Statussen and Versies only.

## What changes

Three tabs of `case-type-panels` count with nextcloud-vue's tab `countFrom { endpoint, field }`:

- Phases and tasks: `taskCount` from `GET /api/case-types/{id}/phases` (the tasks the phases card shows).
- Rules: the case type's own `rules` (`countField`), the "Als ... dan ..." rows the Rules tab lists first.
- Translations: the register's `languages` (the languages the Translations tab lists, source language included).

## Reach

Config only. No library change: `countFrom.endpoint` is in nextcloud-vue since screens-tab-counts-parity.
