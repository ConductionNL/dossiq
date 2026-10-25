# Proposal: social-people-stacked

## Why

Board DqSociaalDomein draws the side card "Wie" as a short list: each person 10px under the one before, with no rule.
In r29, live drew a rule above each row and 10px of padding.

## What changes

`side-social-people` sets `density: "stacked"`. With nc-vue `fix/board-side-person-rows` (initials beside the name) and
`fix/board-side-lead-gaps` (a stacked side list starts 10px under the title), the card reads as the board draws it.

## Scope

Config only (`src/menu-layout.simple.json`).
