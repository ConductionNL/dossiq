# Proposal: case-type-blueprint-summary

## Why

"Statuses, results and attributes" on the case type's Statuses tab drew three bare lists with no card around them, at
the foot of the tab. Board DqZaaktypeStatussen draws a titled card, "What this case type offers, also what it inherits
from its parent type" under the title, that leads with numbers: "6, of which 2 final", "3", "14 fields, 6 inherited
from Omgevingsvergunning".

## What changes

- The widget's registry entry asks the host for the titled card (`chrome: "card"`); the widget definition carries the
  board's subtitle.
- The widget leads with the three facts (`blueprintSummary`). The rows it listed before stay, behind "Every status,
  result and attribute", with their Inherited and Shared badges.
