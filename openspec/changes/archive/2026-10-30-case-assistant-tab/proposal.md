# Proposal: case-assistant-tab

## Why

DqZaakAssistent draws the assistant inside the case page, in the AI-toezicht tab. On the case page the assistant was
reachable only through the launcher hex's floating window, so COMPDIFF measured that window against a tab.

## What changes

The simple case page gets an "AI oversight" (AI-toezicht) tab holding `case-assistant-chat`, a nextcloud-vue `ai-chat`
widget: the same AI Chat Companion the hex opens, embedded, with the case as its context, needing hermiq. Like the
other panels the five-tab strip has no room for, it waits under Acties and takes its place in the strip while open.
The hex and the Assistant sidebar tab stay.

The Locations card going full width when the Priority card is hidden is a library change (nextcloud-vue
stack-lone-card-fills-row); this manifest keeps its half-and-half layout, which DqZaak draws.

## Scope

Manifest only. Needs the nextcloud-vue release with the `ai-chat` widget.
