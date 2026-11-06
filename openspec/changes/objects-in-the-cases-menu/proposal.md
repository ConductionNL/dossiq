# Proposal: Objects sits in the Cases menu, with the object first

## Why

Board DqCatalogus draws an overview "als Alle zaken, bereikt via het menu Zaken": you start at the object and find its cases. The page exists (`objects-index-like-cases`), but it sat under settings at `/case-objects`, with the type first and no status of the case. Ruben, 6 Nov: a Cases menu entry "Objecten" at `/apps/dossiq/objects`.

## What changes

- The route becomes `/objects`. The simple profile moves the entry from settings to the Cases part of the menu.
- The simple profile's columns read Object (identification, description under it), Object type, Case (title, number under it) and Status (the case's public status).
- The header has Download and Add case object, the count line says the list is sorted by object.

## Not changed

The object type folder list, saved views and the View case row action. Nothing new is stored.
