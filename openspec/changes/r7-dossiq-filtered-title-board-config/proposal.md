---
kind: code
---

# Proposal: r7-dossiq-filtered-title-board-config

## Summary

The round-6 cloud check (11 October 2026, dossiq 0.4.52-beta) found two loose
ends on pages the round-6 change touched.

## Why

1. A list narrowed to one case type (a "My case types" menu entry such as
   Woo-verzoek, which opens `/cases?caseType=<id>`) still has the tab title
   "All cases - Dossiq - …". The tab says the list shows every case while it
   shows one case type's cases, and two such tabs cannot be told apart.
2. Every visit to the Workflow board logs
   `[CnPageRenderer] Custom page id "WorkflowBoard" declares only one of
   config.register / config.schema`. The page config names `register: dossiq`
   and no schema. CnPageRenderer then registers nothing, and the board loads
   its three schemas (statusType, caseType, case) through the object types
   `initializeStores()` registers anyway.

## What changes

1. `src/utils/pageTitle.js`: a route with `?caseType=<id>` is named after the
   menu entry that opens that filter (its label is the case type's title, from
   the backend `/api/manifest` delta). With no such entry the tab reads
   "Cases of one case type" (nl "Zaken van één zaaktype"). `main.js` re-titles
   the open route when the resolved menu changes, because the delta lands
   after the first route.
2. `src/manifest.json`: the WorkflowBoard page config drops `register` and
   declares neither half, which matches how the board loads its data. What the
   board shows does not change. The explanation moves to the page `_note`,
   because every config key reaches the board as a DOM attribute on its root.

## Out of scope

- The H1 and the filter chip on the narrowed list ("All cases", "Case type:
  <uuid>") are the library's index page; this change only touches the tab.
