# Proposal: case-ai-tab-side-alone

## Why

DqZaakAssistent draws the side column beside the AI-toezicht tab with one card, "Assistent". The four generic side
cards (term, requester, handling, work instruction) showed beside every tab, so the chat sat fifth.

## What changes

The four generic side cards declare `notTabs: ["ai-oversight"]` (nextcloud-vue side-card-not-on-tabs): they stand
aside while AI-toezicht is open and show beside every other tab as before.

## Not covered

The banner stack (`case-banner-stack`) and the case-type side cards keep showing on that tab when they have something
to show; the board draws a case with neither. They can take the same key if the boards ask for it.
