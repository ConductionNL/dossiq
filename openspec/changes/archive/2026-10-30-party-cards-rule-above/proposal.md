# Proposal: party-cards-rule-above

## Why

Boards DqContact and DqOrganisatie draw a hairline under the title of Zaken, Tickets and Laatste contactmomenten,
above the first row. r26a measured `header.separator 1 vs 0` on all three, on both pages.

## What changes

The three record lists get `ruleAbove: true` (nextcloud-vue, already released), on both pages. Config only.
