# Proposal: party-cases-final-green

## Why

r31 strict verdicts: boards DqContact and DqOrganisatie draw a finished case's pill in green ("Afgehandeld"). Live drew
every case pill purple.

## What changes

The party pages' Zaken cards (`contact-open-cases`) colour a case's pill green when `isFinalStatus` holds
(`pill.variantWhen`, nextcloud-vue feat/record-list-pill-variant-when). An open case stays purple. Status names stay data
per case type.

## Scope

Config only (`src/manifest.json`).
