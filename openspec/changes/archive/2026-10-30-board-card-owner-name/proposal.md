# Proposal: board-card-owner-name

## Why

r29 (25 Oct): DqWerkbord's card owners read "P" (the initial of the user id "pieter"); the board draws "PJ" for Pieter Jansen.

## What changes

The Cases board card reads its owner (the assignee, a Nextcloud user id) through nextcloud-vue's built-in `userName` formatter, so the initials and the title are the handler's name. Configuration only; needs nextcloud-vue feat/r29-user-name-formatter (without it the formatter is unknown and the id stands, as today).
