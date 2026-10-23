# Proposal: case-roles-no-secrecy-line

## Why

DqZaakBetrokkenen draws "Geen geheimhouding" under Sanne de Vries in the Roles section. Secrecy is an indicator on the party record (`geheimhouding`, effect `refuse-publication`), so a person without it has no secrecy, but nothing said so.

## What changes

- The Roles section (`case-party-roles`) asks the library parties widget for its secrecy line: `secrecy: {indicator: geheimhouding, kinds: [person]}`.
- Needs the nextcloud-vue widget option from `feat/r24t-parties-no-secrecy-line` (24t). Until that lands, the key is ignored.
